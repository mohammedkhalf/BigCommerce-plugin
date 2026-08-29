<?php

namespace App\Services\BigCommerce;

use App\Models\Store;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class BigCommerceClient
{
    public function request(Store $store, string $method, string $path, array $data = []): array
    {
        $response = $this->http($store)->send($method, $this->url($store, $path), [
            strtoupper($method) === 'GET' ? 'query' : 'json' => $data,
        ]);

        $response->throw();

        return $response->json() ?? [];
    }

    public function raw(Store $store, string $method, string $path, array $data = []): Response
    {
        return $this->http($store)->send($method, $this->url($store, $path), ['json' => $data]);
    }

    private function http(Store $store): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['X-Auth-Token' => $store->access_token])
            ->connectTimeout(5)->timeout(20)
            ->retry(3, 200, throw: false);
    }

    private function url(Store $store, string $path): string
    {
        return rtrim(config('bigcommerce.api_url'), '/').'/stores/'.$store->store_hash.'/'.ltrim($path, '/');
    }
}
