<?php

namespace App\Services\Tamara;

use App\Enums\ApiLogAction;
use App\Models\Store;

final readonly class TamaraOrderService
{
    public function __construct(private TamaraClient $client) {}

    public function authorise(Store $store, string $orderId, ?string $bcOrderId = null): array
    {
        return $this->client->request($store, 'POST', "orders/{$orderId}/authorise", [], null, $bcOrderId);
    }

    public function capture(Store $store, string $orderId, array $payload, ?string $bcOrderId = null): array
    {
        return $this->client->request(
            $store,
            'POST',
            'payments/capture',
            $payload + ['order_id' => $orderId],
            ApiLogAction::Captured,
            $bcOrderId,
        );
    }

    public function cancel(Store $store, string $orderId, array $payload = [], ?string $bcOrderId = null): array
    {
        return $this->client->request(
            $store,
            'POST',
            "orders/{$orderId}/cancel",
            $payload,
            ApiLogAction::Cancel,
            $bcOrderId,
        );
    }

    public function refund(Store $store, string $orderId, array $payload, ?string $bcOrderId = null): array
    {
        return $this->client->request(
            $store,
            'POST',
            "payments/simplified-refund/{$orderId}",
            $payload,
            ApiLogAction::Refunded,
            $bcOrderId,
        );
    }

    public function details(Store $store, string $orderId): array
    {
        return $this->client->request($store, 'GET', "merchants/orders/{$orderId}");
    }
}
