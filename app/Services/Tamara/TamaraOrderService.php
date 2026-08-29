<?php

namespace App\Services\Tamara;

use App\Models\Store;

final readonly class TamaraOrderService
{
    public function __construct(private TamaraClient $client) {}

    public function authorise(Store $store, string $orderId): array
    {
        return $this->client->request($store, 'POST', "orders/{$orderId}/authorise");
    }

    public function capture(Store $store, string $orderId, array $payload): array
    {
        return $this->client->request($store, 'POST', 'payments/capture', $payload + ['order_id' => $orderId]);
    }

    public function cancel(Store $store, string $orderId, array $payload = []): array
    {
        return $this->client->request($store, 'POST', "orders/{$orderId}/cancel", $payload);
    }

    public function refund(Store $store, string $orderId, array $payload): array
    {
        return $this->client->request($store, 'POST', "payments/simplified-refund/{$orderId}", $payload);
    }

    public function details(Store $store, string $orderId): array
    {
        return $this->client->request($store, 'GET', "merchants/orders/{$orderId}");
    }
}
