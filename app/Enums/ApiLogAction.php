<?php

namespace App\Enums;

enum ApiLogAction: string
{
    case Checkout = 'checkout';
    case Captured = 'captured';
    case Refunded = 'refunded';
    case Cancel = 'cancel';
}
