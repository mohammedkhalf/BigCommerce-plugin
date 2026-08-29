<?php

namespace Tests\Unit;

use App\Services\BigCommerce\BigCommerceOAuthClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BigCommerceOAuthClientTest extends TestCase
{
    public function test_it_exchanges_an_authorization_code(): void
    {
        config()->set('bigcommerce.client_id', 'client-id');
        config()->set('bigcommerce.client_secret', 'client-secret');
        config()->set('bigcommerce.auth_callback', 'https://app.example.com/auth');
        config()->set('bigcommerce.oauth_url', 'https://login.bigcommerce.test/oauth2/token');

        Http::fake([
            'https://login.bigcommerce.test/*' => Http::response(['access_token' => 'token'], 200),
        ]);

        $result = app(BigCommerceOAuthClient::class)->exchange('code', 'stores/abc123', 'store_v2_orders');

        $this->assertSame('token', $result['access_token']);
        Http::assertSent(fn ($request) => $request->url() === 'https://login.bigcommerce.test/oauth2/token'
            && $request['client_secret'] === 'client-secret'
            && $request['context'] === 'stores/abc123');
    }
}
