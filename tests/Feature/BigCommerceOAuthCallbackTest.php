<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BigCommerceOAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('bigcommerce.client_id', 'client-id');
        config()->set('bigcommerce.client_secret', 'client-secret-that-is-at-least-32b');
        config()->set('bigcommerce.app_jwt_secret', 'app-session-secret-that-is-32-bytes');
        config()->set('bigcommerce.oauth_url', 'https://login.bigcommerce.test/oauth2/token');
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_auth_callback_persists_the_store_and_user_and_returns_onboarding(): void
    {
        Queue::fake();
        Http::fake([
            'https://login.bigcommerce.test/*' => Http::response([
                'access_token' => 'private-access-token',
                'scope' => 'store_v2_orders store_v2_content',
                'context' => 'stores/abc123',
                'user' => [
                    'id' => 42,
                    'email' => 'merchant@example.com',
                    'name' => 'Merchant',
                    'locale' => 'en-US',
                ],
            ]),
        ]);

        $response = $this->get('/auth?'.http_build_query([
            'code' => 'authorization-code',
            'context' => 'stores/abc123',
            'scope' => 'store_v2_orders store_v2_content',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Onboarding')
                ->where('store.hash', 'abc123')
                ->where('user.email', 'merchant@example.com')
                ->has('appToken'));

        $store = Store::query()->where('store_hash', 'abc123')->firstOrFail();
        $this->assertSame('private-access-token', $store->access_token);
        $this->assertDatabaseHas('store_users', [
            'store_id' => $store->id,
            'bigcommerce_user_id' => 42,
            'is_active' => true,
        ]);
    }

    public function test_auth_callback_rejects_an_invalid_store_context(): void
    {
        $this->get('/auth?'.http_build_query([
            'code' => 'authorization-code',
            'context' => '../stores/abc123',
            'scope' => 'store_v2_orders',
        ]))->assertSessionHasErrors('context');

        Http::assertNothingSent();
    }
}
