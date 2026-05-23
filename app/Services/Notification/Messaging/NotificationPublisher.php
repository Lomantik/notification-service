<?php

namespace App\Services\Notification\Messaging;

use Exception;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class NotificationPublisher
{
    public function __construct(
        protected RabbitMqConnectionFactory $connectionFactory,
    ) {}

    /**
     * @throws Exception
     */
    public function publish(int $notificationDeliveryId, int $priority): void
    {
        $config = config('rabbitmq');

        $connection = $this->connectionFactory->createConnection();
        $channel = $connection->channel();

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

        $payload = json_encode([
            'notification_delivery_id' => $notificationDeliveryId,
            'timestamp' => time(),
        ]);

        if ($payload) {
            $msg = new AMQPMessage($payload, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $priority,
            ]);

            $channel->basic_publish($msg, $config['exchange'], $config['routing_key']);
        }

        $channel->close();
        $connection->close();
    }
}
