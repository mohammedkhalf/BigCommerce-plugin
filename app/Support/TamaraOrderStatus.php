<?php

namespace App\Support;

use App\Enums\PaymentSessionStatus;

final class TamaraOrderStatus
{
    public static function fromDetails(array $details): ?PaymentSessionStatus
    {
        return self::fromTamaraStatus((string) ($details['status'] ?? $details['order_status'] ?? ''));
    }

    public static function fromTamaraStatus(string $status): ?PaymentSessionStatus
    {
        $status = strtolower(trim($status));

        return match (true) {
            in_array($status, ['fully_captured', 'fully captured'], true) => PaymentSessionStatus::Captured,
            $status === 'partially_captured' => PaymentSessionStatus::PartiallyCaptured,
            in_array($status, ['authorised', 'authorized'], true) => PaymentSessionStatus::Authorised,
            $status === 'approved' => PaymentSessionStatus::Approved,
            default => null,
        };
    }

    public static function isFullyCaptured(array $payload): bool
    {
        $status = (string) (
            data_get($payload, 'data.status')
            ?? data_get($payload, 'status')
            ?? ''
        );

        if (self::fromTamaraStatus($status) === PaymentSessionStatus::Captured) {
            return true;
        }

        $capturedAmount = data_get($payload, 'data.captured_amount.amount')
            ?? data_get($payload, 'captured_amount.amount');
        if ($capturedAmount === null) {
            return false;
        }

        $totalAmount = data_get($payload, 'data.total_amount.amount')
            ?? data_get($payload, 'total_amount.amount')
            ?? data_get($payload, 'data.authorized_amount.amount')
            ?? data_get($payload, 'authorized_amount.amount');

        return $totalAmount !== null
            ? (float) $capturedAmount >= (float) $totalAmount
            : true;
    }
}
