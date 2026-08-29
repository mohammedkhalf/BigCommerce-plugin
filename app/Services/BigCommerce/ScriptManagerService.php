<?php

namespace App\Services\BigCommerce;

use App\Models\Store;

final readonly class ScriptManagerService
{
    public function __construct(private BigCommerceClient $client) {}

    public function provision(Store $store): void
    {
        if ($store->registeredResources()->where('scope', 'script:checkout')->exists()) {
            return;
        }

        $payload = [
            'name' => 'Tamara Checkout',
            'description' => 'Tamara payment checkout integration',
            'src' => rtrim(config('app.url'), '/').'/build/checkout/tamara-checkout.js?store_hash='.$store->store_hash,
            'auto_uninstall' => true,
            'load_method' => 'defer',
            'location' => 'footer',
            'visibility' => 'checkout',
            'kind' => 'src',
            'consent_category' => 'essential',
            'enabled' => true,
        ];
        if (filled(config('bigcommerce.checkout_script_sri'))) {
            $payload['integrity_hash'] = config('bigcommerce.checkout_script_sri');
        }
        $response = $this->client->request($store, 'POST', 'v3/content/scripts', $payload);
        $data = $response['data'] ?? $response;
        $store->registeredResources()->create([
            'bigcommerce_resource_id' => (string) $data['uuid'],
            'scope' => 'script:checkout',
            'destination' => $data['src'] ?? '',
            'metadata' => $data,
        ]);
    }
}
