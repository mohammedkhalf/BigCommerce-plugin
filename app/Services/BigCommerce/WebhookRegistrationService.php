<?php

namespace App\Services\BigCommerce;

use App\Models\RegisteredResource;
use App\Models\Store;

final readonly class WebhookRegistrationService
{
    /** @var list<string> */
    private const SCOPES = [
        'store/shipment/created',
        'store/order/statusUpdated',
        'store/order/updated',
        'store/order/refund/created',
    ];

    public function __construct(private BigCommerceClient $client) {}

    public function provision(Store $store): void
    {
        $destination = rtrim(config('app.url'), '/').'/webhooks/bigcommerce';
        $headers = ['X-Tamara-Webhook-Token' => $this->webhookToken($store)];
        $remoteHooks = collect($this->listHooks($store))->keyBy('scope');

        foreach (self::SCOPES as $scope) {
            $remote = $remoteHooks->get($scope);
            $registered = $store->registeredResources()->where('scope', $scope)->first();

            if ($remote && $this->hookMatches($remote, $destination, $headers)) {
                $this->saveRegisteredResource($store, $registered, $remote, $destination);

                continue;
            }

            if ($remote) {
                $response = $this->client->request($store, 'PUT', "v3/hooks/{$remote['id']}", [
                    'destination' => $destination,
                    'is_active' => true,
                    'headers' => $headers,
                ]);
                $data = $response['data'] ?? $response;
            } else {
                $response = $this->client->request($store, 'POST', 'v3/hooks', [
                    'scope' => $scope,
                    'destination' => $destination,
                    'is_active' => true,
                    'headers' => $headers,
                ]);
                $data = $response['data'] ?? $response;
            }

            $this->saveRegisteredResource($store, $registered, $data, $destination);
        }
    }

    /** @return list<array<string, mixed>> */
    private function listHooks(Store $store): array
    {
        $response = $this->client->request($store, 'GET', 'v3/hooks', ['limit' => 100]);

        return $response['data'] ?? [];
    }

    private function webhookToken(Store $store): string
    {
        return hash_hmac(
            'sha256',
            $store->store_hash,
            (string) config('bigcommerce.client_secret'),
        );
    }

    private function hookMatches(array $hook, string $destination, array $headers): bool
    {
        $configuredHeaders = collect($hook['headers'] ?? [])
            ->mapWithKeys(fn (array $header) => [$header['key'] ?? '' => $header['value'] ?? ''])
            ->all();

        return ($hook['destination'] ?? '') === $destination
            && ($configuredHeaders['X-Tamara-Webhook-Token'] ?? '') === ($headers['X-Tamara-Webhook-Token'] ?? '');
    }

    private function saveRegisteredResource(
        Store $store,
        ?RegisteredResource $registered,
        array $data,
        string $destination,
    ): void {
        $attributes = [
            'bigcommerce_resource_id' => (string) ($data['id'] ?? ''),
            'scope' => (string) ($data['scope'] ?? $registered?->scope ?? ''),
            'destination' => $destination,
            'metadata' => $data,
        ];

        if ($registered) {
            $registered->update($attributes);

            return;
        }

        $store->registeredResources()->create($attributes);
    }
}
