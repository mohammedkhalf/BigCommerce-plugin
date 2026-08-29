<?php

namespace App\Services\BigCommerce;

use App\Models\Store;

final readonly class CheckoutService
{
    public function __construct(private BigCommerceClient $client) {}

    public function get(Store $store, string $checkoutId): array
    {
        return $this->client->request($store, 'GET', "v3/checkouts/{$checkoutId}", ['include' => 'cart.lineItems.physicalItems,cart.lineItems.digitalItems,customer']);
    }

    public function createToken(Store $store, string $checkoutId): string
    {
        $payload = $this->client->request($store, 'POST', "v3/checkouts/{$checkoutId}/token", [
            'maxUses' => 1,
            'ttl' => 3600,
        ]);

        return (string) ($payload['data']['checkoutToken']
            ?? $payload['data']['token']
            ?? $payload['checkoutToken']
            ?? $payload['token']
            ?? '');
    }

    public function createOrder(Store $store, string $checkoutId): array
    {
        return $this->client->request($store, 'POST', "v3/checkouts/{$checkoutId}/orders");
    }
}
