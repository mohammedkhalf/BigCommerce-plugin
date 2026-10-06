<?php

namespace App\Services\Tamara;

use App\Enums\ApiLogAction;
use App\Models\Store;

final readonly class TamaraCheckoutService
{
    public function __construct(private TamaraClient $client) {}

    public function create(Store $store, array $payload): array
    {
        $bcOrderId = $payload['order_reference_id'] ?? $payload['order_number'] ?? null;

        return $this->client->request(
            $store,
            'POST',
            'checkout',
            $payload,
            ApiLogAction::Checkout,
            $bcOrderId !== null ? (string) $bcOrderId : null,
        );
    }
}
