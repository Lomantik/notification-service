<?php

namespace App\Enums;

enum ProviderCallbackStatus: string
{
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
}
