<?php

namespace App\Services\Tamara;

use App\Models\Store;
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
        $response->throw();

        return $response->json() ?? [];
    }

    private function http(string $token): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->connectTimeout(5)->timeout(20)
            ->retry(3, 250, throw: false);
    }
}
