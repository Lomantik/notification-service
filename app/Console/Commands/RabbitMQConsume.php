<?php

namespace App\Console\Commands;

use App\Services\NotificationsService;
use Exception;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

#[Signature('rabbitmq:consume')]
#[Description('Listen and consume messages from RabbitMQ priority queue')]
class RabbitMQConsume extends Command
{
    /**
     * Execute the console command.
     *
     * @throws Exception
     */
    public function handle(): void
    {
        $connection = new AMQPStreamConnection('rabbitmq', 5672, 'guest', 'guest');
        $channel = $connection->channel();

        $this->info(' [*] Waiting for messages in [notifications_queue]. To exit press CTRL+C');

        $channel->queue_declare(
            'notifications_queue',
            false,
            true,
            false,
            false,
            false,
            new AMQPTable(['x-max-priority' => 10]));
        $channel->basic_qos(0, 1, null);

        $channel->queue_declare(
            'notifications_retry_queue',
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-dead-letter-exchange' => 'notifications_exchange',
                'x-dead-letter-routing-key' => 'notification.route',
                'x-message-ttl' => 5000,
            ]));

        $channel->basic_consume(
            'notifications_queue',
            '',
            false,
            false,
            false,
            false,
            function ($msg) use ($channel) {
                try {
                    $data = json_decode($msg->body, true);
                    $this->info(' [x] Received message from RabbitMQ:');
                    $this->line($data);

                    app(NotificationsService::class)->sendNotification($data['notification_delivery_id']);

                    $msg->ack();
                    $this->info(' [x] Done! Sent ACK to broker.');
                } catch (Exception) {
                    $maxRetries = 3;
                    $currentRetry = 0;

                    if ($msg->has('application_headers')) {
                        $headers = $msg->get('application_headers')->getNativeData();
                        if (isset($headers['x-retry-count'])) {
                            $currentRetry = $headers['x-retry-count'];
                        }
                    }

                    if ($currentRetry < $maxRetries) {
                        $currentRetry++;
                        echo " [!] Сбой шлюза. Попытка $currentRetry из $maxRetries. Отправка в буфер сна...\n";

                        $retryHeaders = new AMQPTable(['x-retry-count' => $currentRetry]);
                        $retryMsg = new AMQPMessage($msg->body, [
                            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                            'priority' => $msg->has('priority') ? $msg->get('priority') : 1,
                        ]);
                        $retryMsg->set('application_headers', $retryHeaders);

                        $channel->basic_publish($retryMsg, '', 'notifications_retry_queue');
                    } else {
                        echo " [-] Превышен лимит попыток (3/3).\n";
                        if (isset($data['notification_delivery_id'])) {
                            app(NotificationsService::class)->dropNotification($data['notification_delivery_id']);
                        }
                    }
                    $msg->ack();
                }
            });
        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }
}
