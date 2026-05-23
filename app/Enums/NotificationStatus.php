<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case PROCESSING = 'processing';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case DROPPED = 'dropped';
}
