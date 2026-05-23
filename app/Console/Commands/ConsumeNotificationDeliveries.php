<?php

namespace App\Console\Commands;

use App\Exceptions\TemporaryGatewayException;
use App\Services\Notification\Messaging\RabbitMqConnectionFactory;
use App\Services\Notification\NotificationService;
use Exception;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

#[Signature('notifications:consume')]
#[Description('Consume notification delivery messages from RabbitMQ priority queue')]
class ConsumeNotificationDeliveries extends Command
{
    /**
     * @throws Exception
     */
    public function handle(
        RabbitMqConnectionFactory $connectionFactory,
        NotificationService $notificationService,
    ): void {
        $config = config('rabbitmq');

        $connection = $connectionFactory->createConnection();
        $channel = $connection->channel();

        $this->declareTopology($channel);

        $this->info("Waiting for messages in [{$config['queue']}]. Press CTRL+C to exit.");

        $channel->basic_qos(0, 1, null);

        $channel->basic_consume(
            $config['queue'],
            '',
            false,
            false,
            false,
            false,
            function ($msg) use ($channel, $config, $notificationService) {
                $data = json_decode($msg->body, true);
                $deliveryId = $data['notification_delivery_id'] ?? null;

                if (! is_int($deliveryId) && ! is_numeric($deliveryId)) {
                    $this->warn('Received message without notification_delivery_id. Acknowledging.');

                    $msg->ack();

                    return;
                }

                $deliveryId = (int) $deliveryId;

                try {
                    $this->info("Processing delivery #{$deliveryId}");

                    $notificationService->sendNotification($deliveryId);

                    $msg->ack();
                    $this->info("Delivery #{$deliveryId} processed.");
                } catch (TemporaryGatewayException $exception) {
                    $this->retryOrDrop($channel, $msg, $config, $notificationService, $deliveryId, $exception);
                } catch (ModelNotFoundException $exception) {
                    $this->warn("Delivery #{$deliveryId} not found. Acknowledging.");
                    $msg->ack();
                } catch (Throwable $exception) {
                    $this->error("Permanent failure for delivery #{$deliveryId}: {$exception->getMessage()}");
                    $notificationService->dropNotification($deliveryId);
                    $msg->ack();
                }
            },
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }

    private function declareTopology(AMQPChannel $channel): void
    {
        $config = config('rabbitmq');

        $channel->exchange_declare($config['exchange'], 'direct', false, true, false);

        $channel->queue_declare(
            $config['queue'],
            false,
            true,
            false,
            false,
            false,
            new AMQPTable(['x-max-priority' => $config['max_priority']]),
        );

        $channel->queue_declare(
            $config['retry_queue'],
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => $config['exchange'],
                'x-dead-letter-routing-key' => $config['routing_key'],
                'x-message-ttl' => $config['retry_ttl_ms'],
            ]),
        );

        $channel->queue_bind($config['queue'], $config['exchange'], $config['routing_key']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function retryOrDrop(
        AMQPChannel $channel,
        AMQPMessage $msg,
        array $config,
        NotificationService $notificationService,
        int $deliveryId,
        TemporaryGatewayException $exception,
    ): void {
        $maxRetries = $config['max_retries'];
        $currentRetry = 0;

        if ($msg->has('application_headers')) {
            $headers = $msg->get('application_headers')->getNativeData();
            $currentRetry = (int) ($headers['x-retry-count'] ?? 0);
        }

        if ($currentRetry < $maxRetries) {
            $currentRetry++;
            $this->warn("Gateway unavailable for delivery #{$deliveryId}. Retry {$currentRetry}/{$maxRetries}.");

            $retryHeaders = new AMQPTable(['x-retry-count' => $currentRetry]);
            $retryMsg = new AMQPMessage($msg->body, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $msg->has('priority') ? $msg->get('priority') : 1,
            ]);
            $retryMsg->set('application_headers', $retryHeaders);

            $channel->basic_publish($retryMsg, '', $config['retry_queue']);
            $msg->ack();

            return;
        }

        $this->error("Retry limit reached for delivery #{$deliveryId}: {$exception->getMessage()}");
        $notificationService->dropNotification($deliveryId);
        $msg->ack();
    }
}
