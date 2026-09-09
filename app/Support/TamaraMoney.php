<?php

namespace App\Support;

final class TamaraMoney
{
    public static function format(float|int|string $amount, string $currency): array
    {
        return [
            'amount' => round((float) $amount, 2),
            'currency' => strtoupper($currency),
        ];
    }

    public static function extractAmount(mixed $value): ?float
    {
        if (is_array($value)) {
            $amount = $value['amount'] ?? null;

            return is_numeric($amount) ? (float) $amount : null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
