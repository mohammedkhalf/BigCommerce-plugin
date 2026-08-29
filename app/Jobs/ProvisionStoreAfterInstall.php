<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\BigCommerce\ScriptManagerService;
use App\Services\BigCommerce\StoreInfoService;
use App\Services\BigCommerce\WebhookRegistrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProvisionStoreAfterInstall implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $storeId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(StoreInfoService $info, ScriptManagerService $scripts, WebhookRegistrationService $hooks): void
    {
        $store = Store::query()->whereKey($this->storeId)->whereNull('uninstalled_at')->firstOrFail();
        $info->sync($store);
        $scripts->provision($store);
        $hooks->provision($store);
    }
}
