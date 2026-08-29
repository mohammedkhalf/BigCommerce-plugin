<?php

namespace App\Services\BigCommerce;

use App\Models\Store;

final readonly class OrderService
{
    public function __construct(private BigCommerceClient $client) {}

    public function get(Store $store, string|int $orderId): array
    {
        return $this->client->request($store, 'GET', "v2/orders/{$orderId}");
    }

    public function updateStatus(Store $store, string|int $orderId, int $statusId): array
    {
        return $this->client->request($store, 'PUT', "v2/orders/{$orderId}", ['status_id' => $statusId]);
    }

    public function create(Store $store, array $payload): array
    {
        return $this->client->request($store, 'POST', 'v2/orders', $payload + [
            'external_source' => config('bigcommerce.app_id'),
            'payment_method' => 'Tamara',
        ]);
    }
}
