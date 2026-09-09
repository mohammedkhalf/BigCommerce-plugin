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
}
