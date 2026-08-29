<?php

namespace Tests\Unit;

use App\Services\BigCommerce\BigCommerceJwtVerifier;
use Firebase\JWT\JWT;
use Illuminate\Auth\AuthenticationException;
use Tests\TestCase;

class BigCommerceJwtVerifierTest extends TestCase
{
    private const SECRET = 'client-secret-that-is-at-least-32b';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bigcommerce.client_id', 'client-id');
        config()->set('bigcommerce.client_secret', self::SECRET);
        config()->set('bigcommerce.jwt_issuer', 'bc');
    }

    public function test_it_verifies_valid_bigcommerce_claims(): void
    {
        $token = JWT::encode([
            'iss' => 'bc',
            'aud' => 'client-id',
            'sub' => 'stores/abc123',
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 60,
        ], self::SECRET, 'HS256');

        $claims = app(BigCommerceJwtVerifier::class)->verify($token);

        $this->assertSame('stores/abc123', $claims['sub']);
    }

    public function test_it_rejects_an_invalid_audience(): void
    {
        $token = JWT::encode([
            'iss' => 'bc',
            'aud' => 'another-client',
            'exp' => time() + 60,
        ], self::SECRET, 'HS256');

        $this->expectException(AuthenticationException::class);

        app(BigCommerceJwtVerifier::class)->verify($token);
    }
}
