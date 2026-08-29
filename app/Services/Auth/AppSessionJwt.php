<?php

namespace App\Services\Auth;

use App\Models\Store;
use App\Models\StoreUser;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;
use Throwable;

final class AppSessionJwt
{
    public function issue(Store $store, StoreUser $user): string
    {
        $now = now()->timestamp;

        return JWT::encode([
            'iss' => config('app.url'),
            'aud' => config('bigcommerce.client_id'),
            'sub' => $user->id,
            'store_id' => $store->id,
            'store_hash' => $store->store_hash,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + (int) config('bigcommerce.app_jwt_ttl'),
            'jti' => (string) Str::uuid(),
        ], $this->secret(), 'HS256');
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $jwt): array
    {
        try {
            $claims = JWT::decode($jwt, new Key($this->secret(), 'HS256'));
        } catch (Throwable) {
            throw new AuthenticationException('Invalid app session token.');
        }

        if (($claims->iss ?? null) !== config('app.url')
            || ($claims->aud ?? null) !== config('bigcommerce.client_id')) {
            throw new AuthenticationException('Invalid app session claims.');
        }

        return json_decode(json_encode($claims, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private function secret(): string
    {
        $secret = (string) config('bigcommerce.app_jwt_secret');

        if ($secret === '') {
            throw new \RuntimeException('APP_SESSION_JWT_SECRET is not configured.');
        }

        return $secret;
    }
}
