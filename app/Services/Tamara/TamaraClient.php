<?php

namespace App\Services\Tamara;

use App\Models\Store;
use App\Support\OutboundHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TamaraClient
{
    public function request(Store $store, string $method, string $path, array $data = []): array
    {
        $config = $store->tamaraConfig;
        if (! $config || blank($config->api_token)) {
            throw new RuntimeException('Tamara is not configured for this store.');
        }

        $base = $config->mode->value === 'production'
            ? config('tamara.production_url')
            : config('tamara.sandbox_url');
        $response = $this->http($config->api_token)->send($method, rtrim($base, '/').'/'.ltrim($path, '/'), [
            strtoupper($method) === 'GET' ? 'query' : 'json' => $data,
        ]);


        if ($response->status() === 404 && str_contains((string) $response->body(), 'Merchant is not found')) {
            $environment = $config->mode->value === 'production' ? 'live' : 'sandbox';
            throw new RuntimeException(
                "Tamara returned \"Merchant is not found\" on the {$environment} API. "
                .'Use sandbox credentials with environment Sandbox, or production credentials with environment Live. '
                .'Generate tokens from Tamara Partners Portal for the matching environment.',
            );
        }

        $response->throw();

        return $response->json() ?? [];
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
