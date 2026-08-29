<?php

namespace App\Services\BigCommerce;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\AuthenticationException;
use stdClass;
use Throwable;

final class BigCommerceJwtVerifier
{
    /**
     * @return array<string, mixed>
     */
    public function verify(string $jwt): array
    {
        try {
            $claims = JWT::decode(
                $jwt,
                new Key((string) config('bigcommerce.client_secret'), 'HS256')
            );
        } catch (Throwable) {
            throw new AuthenticationException('Invalid BigCommerce signed payload.');
        }

        $audience = is_array($claims->aud ?? null) ? $claims->aud : [$claims->aud ?? null];
        if (! in_array(config('bigcommerce.client_id'), $audience, true)) {
            throw new AuthenticationException('Invalid BigCommerce JWT audience.');
        }

        if (($claims->iss ?? null) !== config('bigcommerce.jwt_issuer')) {
            throw new AuthenticationException('Invalid BigCommerce JWT issuer.');
        }

        return $this->toArray($claims);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(stdClass $claims): array
    {
        return json_decode(json_encode($claims, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
