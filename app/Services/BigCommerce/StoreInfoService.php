<?php

namespace App\Services\BigCommerce;

use App\Models\Store;

final readonly class StoreInfoService
{
    public function __construct(private BigCommerceClient $client) {}

    public function sync(Store $store): Store
    {
        $info = $this->client->request($store, 'GET', 'v2/store');
        $store->update([
            'account_uuid' => $info['account_uuid'] ?? null,
            'name' => $info['name'] ?? null,
            'currency' => $info['currency'] ?? null,
            'timezone' => $info['timezone']['name'] ?? $info['timezone'] ?? null,
            'locale' => $info['locale'] ?? null,
            'metadata' => $info,
        ]);

        return $store->refresh();
    }
}
