<?php

namespace App\Services\Notification;

use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class NotificationPublisher
{
    /**
     * @throws Exception
     */
    public function publish(int $notificationDeliveryId, int $priority): void
    {
        $connection = new AMQPStreamConnection('rabbitmq', 5672, 'guest', 'guest');
        $channel = $connection->channel();

        $channel->exchange_declare('notifications_exchange', 'direct', false, true, false);

        $channel->queue_declare(
            'notifications_queue',
            false,
            true,
            false,
            false,
            false,
            new AMQPTable(['x-max-priority' => 10]));

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

        $channel->queue_bind('notifications_queue', 'notifications_exchange', 'notification.route');

        $payload = json_encode([
            'notification_delivery_id' => $notificationDeliveryId,
            'timestamp' => time(),
        ]);

        if ($payload) {
            $msg = new AMQPMessage($payload, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $priority,
            ]);

            $channel->basic_publish($msg, 'notifications_exchange', 'notification.route');
        }

        $channel->close();
        $connection->close();
    }
}
