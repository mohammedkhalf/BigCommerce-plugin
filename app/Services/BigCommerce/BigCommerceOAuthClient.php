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
        return Http::asForm()
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 200)
            ->post(config('bigcommerce.oauth_url'), [
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
