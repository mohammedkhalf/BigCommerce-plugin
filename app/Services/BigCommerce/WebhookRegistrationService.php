<?php

namespace App\Services\BigCommerce;

use App\Models\Store;

final readonly class WebhookRegistrationService
{
    public function __construct(private BigCommerceClient $client) {}

    public function provision(Store $store): void
    {
        foreach (['store/shipment/created', 'store/order/refund/created'] as $scope) {
            if ($store->registeredResources()->where('scope', $scope)->exists()) {
                continue;
            }
            $destination = rtrim(config('app.url'), '/').'/webhooks/bigcommerce';
            $response = $this->client->request($store, 'POST', 'v3/hooks', [
                'scope' => $scope,
                'destination' => $destination,
                'is_active' => true,
                'headers' => [
                    'X-Tamara-Webhook-Token' => hash_hmac(
                        'sha256',
                        $store->store_hash,
                        (string) config('bigcommerce.client_secret'),
                    ),
                ],
            ]);
            $data = $response['data'] ?? $response;
            $store->registeredResources()->create([
                'bigcommerce_resource_id' => (string) $data['id'],
                'scope' => $scope,
                'destination' => $destination,
                'metadata' => $data,
            ]);
        }
    }
}
