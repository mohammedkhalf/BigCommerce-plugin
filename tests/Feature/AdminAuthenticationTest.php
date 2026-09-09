<?php

namespace Tests\Feature;

use App\Models\PaymentSession;
use App\Models\Store;
use App\Models\StoreUser;
use App\Services\Auth\AppSessionJwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('app.url', 'https://app.test');
        config()->set('bigcommerce.client_id', 'client-id');
        config()->set('bigcommerce.app_jwt_secret', str_repeat('s', 32));
    }

    public function test_unauthenticated_admin_page_does_not_redirect_to_login(): void
    {
        $response = $this->get('/payments');

        $response->assertUnauthorized();
        $response->assertDontSee('Route [login] not defined', false);
    }

    public function test_admin_page_accepts_session_cookie_on_full_page_reload(): void
    {
        $store = Store::query()->create([
            'store_hash' => 'abc123',
            'access_token' => 'bc-secret',
            'currency' => 'SAR',
            'installed_at' => now(),
        ]);
        $user = StoreUser::query()->create([
            'store_id' => $store->id,
            'bigcommerce_user_id' => 10,
            'email' => 'owner@example.com',
            'is_active' => true,
        ]);
        $token = app(AppSessionJwt::class)->issue($store, $user);

        $response = $this->withCookie('tamara_app_session', $token)
            ->get('/payments');

        $response->assertOk();
    }

    public function test_payments_page_includes_customer_from_checkout_snapshot(): void
    {
        $store = Store::query()->create([
            'store_hash' => 'abc123',
            'access_token' => 'bc-secret',
            'currency' => 'SAR',
            'installed_at' => now(),
        ]);
        $user = StoreUser::query()->create([
            'store_id' => $store->id,
            'bigcommerce_user_id' => 10,
            'email' => 'owner@example.com',
            'is_active' => true,
        ]);
        PaymentSession::query()->create([
            'store_id' => $store->id,
            'bc_checkout_id' => 'checkout123',
            'bc_order_id' => '117',
            'amount' => 109,
            'currency' => 'SAR',
            'bc_snapshot' => [
                'billing_address' => [
                    'first_name' => 'Sara',
                    'last_name' => 'Ibrahim',
                    'email' => 'sara@example.com',
                ],
            ],
        ]);
        $token = app(AppSessionJwt::class)->issue($store, $user);

        $this->withCookie('tamara_app_session', $token)
            ->get('/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payments/Index')
                ->has('payments.data', 1)
                ->where('payments.data.0.customer', 'Sara Ibrahim'));
    }
}
