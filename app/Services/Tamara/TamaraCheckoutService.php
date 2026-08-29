<?php

namespace App\Services\Tamara;

use App\Models\Store;

final readonly class TamaraCheckoutService
{
    public function __construct(private TamaraClient $client) {}

    public function create(Store $store, array $payload): array
    {
        return $this->client->request($store, 'POST', 'checkout', $payload);
    }
}
