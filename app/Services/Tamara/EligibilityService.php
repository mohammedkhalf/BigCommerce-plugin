<?php

namespace App\Services\Tamara;

use App\Models\Store;

final readonly class EligibilityService
{
    public function __construct(private TamaraClient $client) {}

    public function check(Store $store, float $amount, string $currency, string $country = 'SA'): array
    {
        return $this->client->request($store, 'GET', 'checkout/payment-types', [
            'country' => $country,
            'currency' => $currency,
            'order_value' => $amount,
        ]);
    }
}
