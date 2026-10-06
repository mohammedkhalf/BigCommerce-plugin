<?php

namespace App\Services\Tamara;

use App\Enums\ApiLogAction;
use App\Models\PaymentSession;
use App\Models\Store;
use App\Services\ApiLogService;
use App\Support\OutboundHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class TamaraClient
{
    public function __construct(private ApiLogService $apiLogs) {}

    public function request(
        Store $store,
        string $method,
        string $path,
        array $data = [],
        ?ApiLogAction $action = null,
        ?string $bcOrderId = null,
    ): array {
        $action = $this->resolveAction($action, $method, $path);
        $bcOrderId = $this->resolveBcOrderId($store, $bcOrderId, $path, $data);

        $config = $store->tamaraConfig;
        if (! $config || blank($config->api_token)) {
            $this->apiLogs->record(
                $store,
                $action,
                $bcOrderId,
                $data,
                ['error' => 'Tamara is not configured for this store.'],
            );
            throw new RuntimeException('Tamara is not configured for this store.');
        }

        $base = $config->mode->value === 'production'
            ? config('tamara.production_url')
            : config('tamara.sandbox_url');

        $response = null;
        try {
            $response = $this->http($config->api_token)->send($method, rtrim($base, '/').'/'.ltrim($path, '/'), [
                strtoupper($method) === 'GET' ? 'query' : 'json' => $data,
            ]);
        } catch (Throwable $e) {
            $this->apiLogs->record($store, $action, $bcOrderId, $data, ['error' => $e->getMessage()]);
            throw $e;
        }

        $parsed = $this->parseResponse($response);
        $this->apiLogs->record($store, $action, $bcOrderId, $data, $parsed, $response->status());

        if ($response->status() === 404 && str_contains((string) $response->body(), 'Merchant is not found')) {
            $environment = $config->mode->value === 'production' ? 'live' : 'sandbox';
            throw new RuntimeException(
                "Tamara returned \"Merchant is not found\" on the {$environment} API. "
                .'Use sandbox credentials with environment Sandbox, or production credentials with environment Live. '
                .'Generate tokens from Tamara Partners Portal for the matching environment.',
            );
        }

        $response->throw();

        return is_array($parsed) ? $parsed : [];
    }

    private function parseResponse(Response $response): ?array
    {
        $json = $response->json();
        if (is_array($json)) {
            return $json === [] ? null : $json;
        }

        $body = $response->body();

        return $body === '' ? null : ['body' => $body];
    }

    private function resolveAction(?ApiLogAction $action, string $method, string $path): ?ApiLogAction
    {
        if ($action instanceof ApiLogAction) {
            return $action;
        }

        $normalized = strtolower(trim($path, '/'));
        if (strtoupper($method) !== 'POST') {
            return null;
        }

        return match (true) {
            $normalized === 'checkout' => ApiLogAction::Checkout,
            $normalized === 'payments/capture' => ApiLogAction::Captured,
            (bool) preg_match('#^payments/simplified-refund/[^/]+$#', $normalized) => ApiLogAction::Refunded,
            (bool) preg_match('#^orders/[^/]+/cancel$#', $normalized) => ApiLogAction::Cancel,
            default => null,
        };
    }

    private function resolveBcOrderId(Store $store, ?string $bcOrderId, string $path, array $data): ?string
    {
        if (filled($bcOrderId)) {
            return (string) $bcOrderId;
        }

        foreach (['order_reference_id', 'order_number'] as $key) {
            if (filled($data[$key] ?? null)) {
                return (string) $data[$key];
            }
        }

        $tamaraOrderId = $data['order_id'] ?? null;
        if (! filled($tamaraOrderId) && preg_match('#(?:orders|simplified-refund)/([^/]+)#', trim($path, '/'), $matches)) {
            $tamaraOrderId = $matches[1];
        }

        if (! filled($tamaraOrderId)) {
            return null;
        }

        try {
            return PaymentSession::query()
                ->where('store_id', $store->id)
                ->where('tamara_order_id', (string) $tamaraOrderId)
                ->value('bc_order_id');
        } catch (Throwable) {
            return null;
        }
    }

    private function http(string $token): PendingRequest
    {
        return OutboundHttp::apply(
            Http::acceptJson()
                ->withToken($token)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->connectTimeout(5)->timeout(20)
                ->retry(3, 250, throw: false),
        );
    }
}
