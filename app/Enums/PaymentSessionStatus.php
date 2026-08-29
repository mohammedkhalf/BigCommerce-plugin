<?php

namespace App\Enums;

enum PaymentSessionStatus: string
{
    case Pending = 'pending';
    case Redirected = 'redirected';
    case Approved = 'approved';
    case Authorised = 'authorised';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case PartiallyCaptured = 'partially_captured';
    case Captured = 'captured';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Failed = 'failed';
    case Completed = 'completed';
}
