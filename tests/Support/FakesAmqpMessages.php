<?php

namespace Tests\Support;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

trait FakesAmqpMessages
{
    protected function fakeAmqpMessage(
        array $payload,
        AMQPChannel $channel,
        int $priority = 1,
        ?int $retryCount = null,
    ): AMQPMessage {
        $message = new AMQPMessage(json_encode($payload) ?: '', [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'priority' => $priority,
        ]);

        if ($retryCount !== null) {
            $message->set('application_headers', new AMQPTable(['x-retry-count' => $retryCount]));
        }

        $channel->shouldReceive('basic_ack')->andReturnNull();
        $message->setChannel($channel);
        $message->setDeliveryTag('test-delivery-tag');

        return $message;
    }
}
