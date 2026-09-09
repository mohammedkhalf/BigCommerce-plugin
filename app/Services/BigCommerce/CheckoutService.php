<?php

namespace App\Services\BigCommerce;

use App\Models\Store;
use RuntimeException;

final readonly class CheckoutService
{
    public function __construct(private BigCommerceClient $client) {}

    public function get(Store $store, string $checkoutId): array
    {
        return $this->client->request($store, 'GET', "v3/checkouts/{$checkoutId}", [
            'include' => 'cart.lineItems.physicalItems,cart.lineItems.digitalItems,customer,consignments.available_shipping_options',
        ]);
    }

    public function ensureShippingSelected(Store $store, string $checkoutId, array $checkout): array
    {
        $updated = false;

        foreach ($checkout['consignments'] ?? [] as $consignment) {
            if ($this->consignmentHasFulfillment($consignment)) {
                continue;
            }

            $consignmentId = (string) ($consignment['id'] ?? '');
            if ($consignmentId === '') {
                continue;
            }

            $optionId = $this->firstShippingOptionId($consignment);
            if ($optionId === null) {
                $refreshed = $this->get($store, $checkoutId);
                $checkout = $refreshed['data'] ?? $refreshed;
                $consignment = collect($checkout['consignments'] ?? [])
                    ->firstWhere('id', $consignmentId) ?? $consignment;
                $optionId = $this->firstShippingOptionId($consignment);
            }

            if ($optionId === null) {
                throw new RuntimeException(
                    'BigCommerce requires a shipping method before creating an order. Add a consignment in Postman (step 5), then select shipping (step 5b).',
                );
            }

            $this->selectShippingOption($store, $checkoutId, $consignmentId, $optionId);
            $updated = true;
        }

        if (! $updated) {
            return $checkout;
        }

        $refreshed = $this->get($store, $checkoutId);

        return $refreshed['data'] ?? $refreshed;
    }

    public function selectShippingOption(Store $store, string $checkoutId, string $consignmentId, string $shippingOptionId): array
    {
        return $this->client->request($store, 'PUT', "v3/checkouts/{$checkoutId}/consignments/{$consignmentId}", [
            'shipping_option_id' => $shippingOptionId,
        ]);
    }

    public function createToken(Store $store, string $checkoutId): string
    {
        $payload = $this->client->request($store, 'POST', "v3/checkouts/{$checkoutId}/token", [
            'maxUses' => 1,
            'ttl' => 3600,
        ]);

        return (string) ($payload['data']['checkoutToken']
            ?? $payload['data']['checkout_token']
            ?? $payload['data']['token']
            ?? $payload['checkoutToken']
            ?? $payload['checkout_token']
            ?? $payload['token']
            ?? '');
    }

    public function createOrder(Store $store, string $checkoutId): array
    {
        return $this->client->request($store, 'POST', "v3/checkouts/{$checkoutId}/orders");
    }

    private function consignmentHasFulfillment(array $consignment): bool
    {
        return filled($consignment['selected_shipping_option'] ?? $consignment['selectedShippingOption'] ?? null)
            || filled($consignment['selected_pickup_option'] ?? $consignment['selectedPickupOption'] ?? null);
    }

    private function firstShippingOptionId(array $consignment): ?string
    {
        $options = $consignment['available_shipping_options'] ?? $consignment['availableShippingOptions'] ?? [];
        $optionId = $options[0]['id'] ?? null;

        return filled($optionId) ? (string) $optionId : null;
    }
}
