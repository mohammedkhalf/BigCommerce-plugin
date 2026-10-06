<?php

namespace App\Services;

use App\Enums\ApiLogAction;
use App\Models\ApiLog;
use App\Models\Store;
use Throwable;

final class ApiLogService
{
    public function record(
        ?Store $store,
        ?ApiLogAction $action,
        ?string $bcOrderId,
        mixed $requestPayload,
        mixed $responsePayload,
        ?int $httpStatus = null,
    ): void {
        if ($action === null) {
            return;
        }

        try {
            ApiLog::query()->create([
                'store_id' => $store?->id,
                'bc_order_id' => filled($bcOrderId) ? (string) $bcOrderId : null,
                'action' => $action,
                'request_payload' => $this->normalizePayload($requestPayload),
                'response_payload' => $this->normalizePayload($responsePayload),
                'http_status' => $httpStatus,
                'logged_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function normalizePayload(mixed $payload): ?array
    {
        if ($payload === null || $payload === '' || $payload === []) {
            return null;
        }

        if (is_array($payload)) {
            return $payload;
        }

        return ['value' => $payload];
    }
}
