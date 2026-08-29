<?php

namespace App\Services\Tamara;

use App\Models\Store;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\AuthenticationException;

final class TamaraNotificationVerifier
{
    public function verify(Store $store, ?string $authorization): array
    {
        if (! preg_match('/^Bearer\s+(.+)$/i', (string) $authorization, $match)) {
            throw new AuthenticationException('Missing Tamara notification token.');
        }
        $secret = $store->tamaraConfig?->notification_token;
        if (blank($secret)) {
            throw new AuthenticationException('Tamara notification verification is not configured.');
        }

        try {
            return (array) JWT::decode($match[1], new Key($secret, 'HS256'));
        } catch (\Throwable $e) {
            throw new AuthenticationException('Invalid Tamara notification token.', previous: $e);
        }
    }
}
