<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\BigCommerce\WebhookRegistrationService;
use Illuminate\Console\Command;

final class ProvisionBigCommerceWebhooks extends Command
{
    protected $signature = 'bigcommerce:provision-webhooks {store_hash? : Store hash; omit to provision all installed stores}';

    protected $description = 'Register missing BigCommerce webhooks (shipment, order status, refund)';

    public function handle(WebhookRegistrationService $hooks): int
    {
        $query = Store::query()->whereNull('uninstalled_at');
        if ($hash = $this->argument('store_hash')) {
            $query->where('store_hash', $hash);
        }

        $stores = $query->get();
        if ($stores->isEmpty()) {
            $this->error('No matching installed stores found.');

            return self::FAILURE;
        }

        foreach ($stores as $store) {
            $hooks->provision($store);
            $this->info("Provisioned webhooks for {$store->store_hash}");
        }

        return self::SUCCESS;
    }
}
