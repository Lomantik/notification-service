<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'exchange' => env('RABBITMQ_EXCHANGE', 'notifications_exchange'),
    'queue' => env('RABBITMQ_QUEUE', 'notifications_queue'),
    'retry_queue' => env('RABBITMQ_RETRY_QUEUE', 'notifications_retry_queue'),
    'routing_key' => env('RABBITMQ_ROUTING_KEY', 'notification.route'),
    'max_priority' => (int) env('RABBITMQ_MAX_PRIORITY', 10),
    'retry_ttl_ms' => (int) env('RABBITMQ_RETRY_TTL_MS', 5000),
    'max_retries' => (int) env('RABBITMQ_MAX_RETRIES', 3),
];
