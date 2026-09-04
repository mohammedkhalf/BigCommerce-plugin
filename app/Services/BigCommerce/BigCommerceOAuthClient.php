<?php

namespace App\Services\BigCommerce;

use Illuminate\Support\Facades\Http;

final class BigCommerceOAuthClient
{
    /**
     * @return array<string, mixed>
     */
    public function exchange(string $code, string $context, string $scope): array
    {
        $request = Http::asForm()
            ->acceptJson()
            // Total request timeout (seconds)
            ->timeout(30)
            // Limit connect timeout separately for faster failure on network issues
            ->withOptions(['connect_timeout' => 10])
            ->retry(2, 200);

        // In local/dev environments some machines (CI, devboxes) may not have a
        // complete CA bundle which causes cURL error 60 when calling BigCommerce.
        // For local development only, disable peer verification to allow installs.
        // IMPORTANT: do NOT disable verification in production.
        if (app()->environment('local', 'testing')) {
            $request = $request->withoutVerifying();
        }

        return $request->post(config('bigcommerce.oauth_url'), [
                'client_id' => config('bigcommerce.client_id'),
                'client_secret' => config('bigcommerce.client_secret'),
                'redirect_uri' => config('bigcommerce.auth_callback'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'context' => $context,
                'scope' => $scope,
            ])
            ->throw()
            ->json();
    }
}
