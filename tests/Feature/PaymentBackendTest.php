<?php

namespace Tests\Feature;

use App\Jobs\AuthoriseTamaraOrder;
use App\Jobs\CaptureTamaraPayment;
use App\Models\PaymentSession;
use App\Models\Store;
use App\Models\StoreUser;
use App\Services\Auth\AppSessionJwt;
use App\Services\Tamara\TamaraNotificationVerifier;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentBackendTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('app.url', 'https://app.test');
        config()->set('bigcommerce.client_id', 'client-id');
        config()->set('bigcommerce.client_secret', 'client-secret');
        config()->set('bigcommerce.app_jwt_secret', str_repeat('s', 32));
        config()->set('bigcommerce.api_url', 'https://api.bigcommerce.test');
        config()->set('tamara.sandbox_url', 'https://api-sandbox.tamara.test');
        $this->store = Store::query()->create([
            'store_hash' => 'abc123', 'access_token' => 'bc-secret',
            'currency' => 'SAR', 'installed_at' => now(),
            'metadata' => ['secure_url' => 'https://shop.example'],
        ]);
    }

    public function test_settings_never_return_or_store_plaintext_secrets(): void
    {
        $user = StoreUser::query()->create([
            'store_id' => $this->store->id, 'bigcommerce_user_id' => 10,
            'email' => 'owner@example.com', 'is_active' => true,
        ]);
        $token = app(AppSessionJwt::class)->issue($this->store, $user);
        $response = $this->withToken($token)->postJson('/api/settings', [
            'environment' => 'sandbox',
            'merchantToken' => 'merchant-secret-token',
            'notificationToken' => 'notification-secret-token-32-bytes',
        ]);

        $response->assertOk()->assertJsonMissing(['merchant-secret-token', 'notification-secret-token-32-bytes']);
        $row = DB::table('tamara_configs')->where('store_id', $this->store->id)->first();
        $this->assertNotSame('merchant-secret-token', $row->api_token);
        $this->assertNotSame('notification-secret-token-32-bytes', $row->notification_token);
    }

    public function test_checkout_start_uses_authoritative_bigcommerce_data(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes', 'currency_allowlist' => ['SAR'],
        ]);
        Http::fake([
            'https://api.bigcommerce.test/stores/abc123/v3/checkouts/checkout123/orders' => Http::response(['data' => ['id' => 1234]]),
            'https://api.bigcommerce.test/stores/abc123/v3/checkouts/checkout123*' => Http::response(['data' => [
                'grandTotal' => 150, 'currency' => ['code' => 'SAR'], 'cart' => ['lineItems' => []],
                'customer' => ['email' => 'buyer@example.com'], 'billingAddress' => ['countryCode' => 'SA'],
            ]]),
            'https://api.bigcommerce.test/stores/abc123/v3/checkouts/checkout123/token' => Http::response(['data' => ['checkoutToken' => 'checkout-token']]),
            'https://api-sandbox.tamara.test/checkout' => Http::response([
                'order_id' => 'tamara-1', 'checkout_id' => 'tc-1', 'checkout_url' => 'https://checkout.tamara.test/1',
            ]),
        ]);

        $this->withHeaders(['Origin' => 'https://shop.example', 'X-Store-Hash' => 'abc123'])
            ->postJson('/api/checkout/start', ['store_hash' => 'abc123', 'checkout_id' => 'checkout123'])
            ->assertOk()->assertJson(['checkout_url' => 'https://checkout.tamara.test/1']);
        $this->assertDatabaseHas('payment_sessions', ['bc_order_id' => '1234', 'amount' => 150, 'currency' => 'SAR']);
    }

    public function test_tamara_jwt_and_webhook_are_verified_and_idempotent(): void
    {
        Queue::fake();
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout123',
            'bc_order_id' => '1234', 'tamara_order_id' => 'tamara-1',
            'amount' => 150, 'currency' => 'SAR',
        ]);
        $jwt = JWT::encode(['iat' => now()->timestamp, 'exp' => now()->addMinute()->timestamp], 'notification-secret-token-32-bytes', 'HS256');
        $payload = ['event_id' => 'evt-1', 'event_type' => 'order_approved', 'order_reference_id' => '1234', 'order_id' => 'tamara-1'];

        app(TamaraNotificationVerifier::class)->verify($this->store->refresh(), 'Bearer '.$jwt);
        $this->withToken($jwt)->postJson('/webhooks/tamara', $payload)->assertNoContent();
        $this->withToken($jwt)->postJson('/webhooks/tamara', $payload)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(AuthoriseTamaraOrder::class, 1);
    }

    public function test_bigcommerce_webhook_dispatches_capture_once(): void
    {
        Queue::fake();
        $payload = ['scope' => 'store/shipment/created', 'producer' => 'stores/abc123', 'hash' => 'bc-event-1', 'data' => ['order_id' => 1234]];
        $headers = [
            'X-Tamara-Webhook-Token' => hash_hmac('sha256', 'abc123', (string) config('bigcommerce.client_secret')),
        ];
        $this->withHeaders($headers)->postJson('/webhooks/bigcommerce', $payload)->assertNoContent();
        $this->withHeaders($headers)->postJson('/webhooks/bigcommerce', $payload)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(CaptureTamaraPayment::class, 1);
    }

    public function test_bigcommerce_webhook_rejects_missing_authentication(): void
    {
        $this->postJson('/webhooks/bigcommerce', [
            'scope' => 'store/shipment/created',
            'producer' => 'stores/abc123',
            'hash' => 'unauthenticated-event',
            'data' => ['order_id' => 1234],
        ])->assertUnauthorized();

        $this->assertDatabaseMissing('webhook_events', ['external_id' => 'unauthenticated-event']);
    }
}
