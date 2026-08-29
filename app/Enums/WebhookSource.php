<?php

namespace App\Enums;

enum WebhookSource: string
{
    case BigCommerce = 'bigcommerce';
    case Tamara = 'tamara';
}
