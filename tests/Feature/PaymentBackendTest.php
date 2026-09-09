<?php

namespace Tests\Feature;

use App\Enums\WebhookSource;
use App\Jobs\AuthoriseTamaraOrder;
use App\Jobs\CaptureTamaraPayment;
use App\Jobs\RefundTamaraPayment;
use App\Models\PaymentSession;
use App\Models\Store;
use App\Models\WebhookEvent;
use App\Services\Tamara\TamaraOrderService;
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
            'publicKey' => 'tamara-public-key-value',
        ]);

        $response->assertOk()
            ->assertJsonMissing(['merchant-secret-token', 'notification-secret-token-32-bytes', 'tamara-public-key-value'])
            ->assertJsonPath('publicKeyConfigured', true);
        $row = DB::table('tamara_configs')->where('store_id', $this->store->id)->first();
        $this->assertNotSame('merchant-secret-token', $row->api_token);
        $this->assertNotSame('notification-secret-token-32-bytes', $row->notification_token);
        $this->assertNotSame('tamara-public-key-value', $row->public_key);
    }

    public function test_checkout_start_uses_authoritative_bigcommerce_data(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes', 'currency_allowlist' => ['SAR'],
        ]);
        $this->store->update(['scopes' => ['store_checkout', 'store_v2_orders']]);
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

        Http::assertSent(fn ($request) => $request->url() === 'https://api-sandbox.tamara.test/checkout'
            && ($request['tax_amount'] ?? null) === ['amount' => 0.0, 'currency' => 'SAR']
            && ($request['shipping_amount'] ?? null) === ['amount' => 0.0, 'currency' => 'SAR']);

        $this->assertDatabaseHas('payment_sessions', ['bc_order_id' => '1234', 'amount' => 150, 'currency' => 'SAR']);
    }

    public function test_checkout_start_uses_cart_amount_when_grand_total_is_zero(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes', 'currency_allowlist' => ['EUR'],
        ]);
        $this->store->update(['scopes' => ['store_checkout', 'store_v2_orders']]);
        Http::fake([
            'https://api.bigcommerce.test/stores/abc123/v3/checkouts/checkout123/orders' => Http::response(['data' => ['id' => 1234]]),
            'https://api.bigcommerce.test/stores/abc123/v3/checkouts/checkout123*' => Http::response(['data' => [
                'grandTotal' => 0,
                'cart' => [
                    'cartAmount' => 109,
                    'currency' => ['code' => 'EUR'],
                    'lineItems' => [],
                ],
                'customer' => ['email' => 'buyer@example.com'],
                'billingAddress' => ['countryCode' => 'SA'],
            ]]),
            'https://api.bigcommerce.test/stores/abc123/v3/checkouts/checkout123/token' => Http::response(['data' => ['checkoutToken' => 'checkout-token']]),
            'https://api-sandbox.tamara.test/checkout' => Http::response([
                'order_id' => 'tamara-1', 'checkout_id' => 'tc-1', 'checkout_url' => 'https://checkout.tamara.test/1',
            ]),
        ]);

        $this->withHeaders(['Origin' => 'https://shop.example', 'X-Store-Hash' => 'abc123'])
            ->postJson('/api/checkout/start', ['store_hash' => 'abc123', 'checkout_id' => 'checkout123'])
            ->assertOk()->assertJson(['checkout_url' => 'https://checkout.tamara.test/1']);
        $this->assertDatabaseHas('payment_sessions', ['bc_order_id' => '1234', 'amount' => 109, 'currency' => 'EUR']);
    }

    public function test_checkout_start_reads_snake_case_bigcommerce_checkout_fields(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes', 'currency_allowlist' => ['SAR'],
        ]);
        $this->store->update(['scopes' => ['store_checkout', 'store_v2_orders']]);
        Http::fake([
            'https://api.bigcommerce.test/stores/abc123/v3/checkouts/checkout123/orders' => Http::response(['data' => ['id' => 1234]]),
            'https://api.bigcommerce.test/stores/abc123/v3/checkouts/checkout123*' => Http::response(['data' => [
                'grand_total' => 109,
                'billing_address' => [
                    'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'buyer@example.com',
                    'address1' => '123 Test St', 'city' => 'Riyadh', 'state_or_province' => 'Riyadh',
                    'postal_code' => '12345', 'country_code' => 'SA', 'phone' => '+966500000000',
                ],
                'consignments' => [[
                    'id' => 'consignment-1',
                    'available_shipping_options' => [['id' => 'ship-1', 'description' => 'Free Shipping', 'cost' => 0]],
                ]],
                'cart' => [
                    'currency' => ['code' => 'SAR'],
                    'line_items' => [
                        'physical_items' => [[
                            'id' => 'line-1', 'product_id' => 80, 'name' => 'Terrarium', 'sku' => 'OTL',
                            'quantity' => 1, 'sale_price' => 109, 'extended_sale_price' => 109,
                        ]],
                    ],
                ],
            ]]),
            'https://api.bigcommerce.test/stores/abc123/v3/checkouts/checkout123/consignments/consignment-1' => Http::response(['data' => [
                'id' => 'consignment-1',
                'selected_shipping_option' => ['id' => 'ship-1', 'cost' => 0],
            ]]),
            'https://api.bigcommerce.test/stores/abc123/v3/checkouts/checkout123/token' => Http::response(['data' => ['checkout_token' => 'checkout-token']]),
            'https://api-sandbox.tamara.test/checkout' => Http::response([
                'order_id' => 'tamara-1', 'checkout_id' => 'tc-1', 'checkout_url' => 'https://checkout.tamara.test/1',
            ]),
        ]);

        $this->withHeaders(['Origin' => 'https://shop.example', 'X-Store-Hash' => 'abc123'])
            ->postJson('/api/checkout/start', ['store_hash' => 'abc123', 'checkout_id' => 'checkout123'])
            ->assertOk()->assertJson(['checkout_url' => 'https://checkout.tamara.test/1']);
        $this->assertDatabaseHas('payment_sessions', ['bc_order_id' => '1234', 'amount' => 109, 'currency' => 'SAR']);
    }

    public function test_checkout_start_rejects_missing_bigcommerce_checkout_scope(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $this->store->update(['scopes' => ['store_v2_default', 'store_v2_orders', 'store_v2_products_read_only']]);

        $this->withHeaders(['Origin' => 'https://shop.example', 'X-Store-Hash' => 'abc123'])
            ->postJson('/api/checkout/start', ['store_hash' => 'abc123', 'checkout_id' => 'checkout123'])
            ->assertStatus(503)
            ->assertJsonPath('missing_scope_groups.checkouts', ['store_checkout', 'store_checkouts']);
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

    public function test_tamara_order_authorised_webhook_sets_authorised_status(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout119',
            'bc_order_id' => '119', 'tamara_order_id' => '7a4f79a9-6f8a-4260-9607-845eba8c37c4',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'approved',
        ]);
        $jwt = JWT::encode(['iat' => now()->timestamp, 'exp' => now()->addMinute()->timestamp], 'notification-secret-token-32-bytes', 'HS256');
        $payload = [
            'order_id' => '7a4f79a9-6f8a-4260-9607-845eba8c37c4',
            'order_reference_id' => '119',
            'order_number' => '119',
            'event_type' => 'order_authorised',
            'data' => [],
        ];

        $this->withToken($jwt)->postJson('/webhooks/tamara', $payload)->assertNoContent();

        $session->refresh();
        $this->assertSame('authorised', $session->status->value);
        $this->assertNotNull($session->authorised_at);
    }

    public function test_tamara_order_captured_webhook_with_fully_captured_status_sets_captured(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout123',
            'bc_order_id' => '123', 'tamara_order_id' => '12345678-1234-1234-1234-123456789abc',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'authorised',
        ]);
        $jwt = JWT::encode(['iat' => now()->timestamp, 'exp' => now()->addMinute()->timestamp], 'notification-secret-token-32-bytes', 'HS256');
        $payload = [
            'order_id' => '12345678-1234-1234-1234-123456789abc',
            'order_reference_id' => '123',
            'event_type' => 'order_captured',
            'data' => [
                'status' => 'fully_captured',
                'captured_amount' => ['amount' => 109.0, 'currency' => 'SAR'],
            ],
        ];

        $this->withToken($jwt)->postJson('/webhooks/tamara', $payload)->assertNoContent();

        $session->refresh();
        $this->assertSame('captured', $session->status->value);
        $this->assertNotNull($session->captured_at);
    }

    public function test_tamara_order_captured_webhook_sets_captured_status(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout122',
            'bc_order_id' => '122', 'tamara_order_id' => 'e7880bf2-b853-41db-a438-5c6b7674e2c5',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'authorised',
        ]);
        $jwt = JWT::encode(['iat' => now()->timestamp, 'exp' => now()->addMinute()->timestamp], 'notification-secret-token-32-bytes', 'HS256');
        $payload = [
            'order_id' => 'e7880bf2-b853-41db-a438-5c6b7674e2c5',
            'order_reference_id' => '122',
            'order_number' => '122',
            'event_type' => 'order_captured',
            'data' => [
                'capture_id' => '7c46bfff-cda7-4a45-bf57-91f9f29f8f84',
                'captured_amount' => ['amount' => 109.0, 'currency' => 'SAR'],
            ],
        ];

        $this->withToken($jwt)->postJson('/webhooks/tamara', $payload)->assertNoContent();

        $session->refresh();
        $this->assertSame('captured', $session->status->value);
        $this->assertNotNull($session->captured_at);
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

    public function test_bigcommerce_order_status_updated_to_shipped_dispatches_capture(): void
    {
        Queue::fake();
        $payload = [
            'scope' => 'store/order/statusUpdated',
            'producer' => 'stores/abc123',
            'hash' => 'bc-event-shipped',
            'data' => [
                'type' => 'order',
                'id' => 119,
                'status' => ['previous_status_id' => 11, 'new_status_id' => 2],
            ],
        ];
        $headers = [
            'X-Tamara-Webhook-Token' => hash_hmac('sha256', 'abc123', (string) config('bigcommerce.client_secret')),
        ];

        $this->withHeaders($headers)->postJson('/webhooks/bigcommerce', $payload)->assertNoContent();

        Queue::assertPushed(CaptureTamaraPayment::class, 1);
    }

    public function test_bigcommerce_order_status_updated_to_non_shipped_is_ignored(): void
    {
        Queue::fake();
        $payload = [
            'scope' => 'store/order/statusUpdated',
            'producer' => 'stores/abc123',
            'hash' => 'bc-event-completed',
            'data' => [
                'type' => 'order',
                'id' => 118,
                'status' => ['previous_status_id' => 2, 'new_status_id' => 10],
            ],
        ];
        $headers = [
            'X-Tamara-Webhook-Token' => hash_hmac('sha256', 'abc123', (string) config('bigcommerce.client_secret')),
        ];

        $this->withHeaders($headers)->postJson('/webhooks/bigcommerce', $payload)->assertNoContent();

        Queue::assertNotPushed(CaptureTamaraPayment::class);
        $this->assertDatabaseHas('webhook_events', [
            'external_id' => 'bc-event-completed',
            'processing_status' => 'ignored',
        ]);
    }

    public function test_bigcommerce_order_shipped_webhook_captures_tamara_payment(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout119',
            'bc_order_id' => '119', 'tamara_order_id' => '7a4f79a9-6f8a-4260-9607-845eba8c37c4',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'authorised', 'authorised_at' => now(),
        ]);
        Http::fake([
            'https://api-sandbox.tamara.test/payments/capture' => Http::response([
                'capture_id' => 'cap-1',
                'status' => 'fully_captured',
                'captured_amount' => ['amount' => 109, 'currency' => 'SAR'],
            ]),
        ]);

        $payload = [
            'scope' => 'store/order/statusUpdated',
            'producer' => 'stores/abc123',
            'hash' => 'bc-event-shipped-capture',
            'data' => [
                'type' => 'order',
                'id' => 119,
                'status' => ['previous_status_id' => 11, 'new_status_id' => 2],
            ],
        ];
        $headers = [
            'X-Tamara-Webhook-Token' => hash_hmac('sha256', 'abc123', (string) config('bigcommerce.client_secret')),
        ];

        $this->withHeaders($headers)->postJson('/webhooks/bigcommerce', $payload)->assertNoContent();
        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $session->refresh();
        $this->assertSame('captured', $session->status->value);
        $this->assertNotNull($session->captured_at);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-sandbox.tamara.test/payments/capture'
            && ($request['order_id'] ?? null) === '7a4f79a9-6f8a-4260-9607-845eba8c37c4'
            && ($request['total_amount']['amount'] ?? null) === 109.0
            && ($request['total_amount']['currency'] ?? null) === 'SAR'
            && ($request['shipping_info']['shipping_company'] ?? null) === 'BigCommerce'
            && filled($request['shipping_info']['shipped_at'] ?? null));
    }

    public function test_capture_payload_formats_three_decimal_amounts(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout127',
            'bc_order_id' => '127', 'tamara_order_id' => '11111111-1111-1111-1111-111111111111',
            'amount' => '109.000', 'currency' => 'SAR', 'status' => 'authorised', 'authorised_at' => now(),
            'tamara_snapshot' => ['authorized_amount' => ['amount' => 109, 'currency' => 'SAR']],
        ]);
        Http::fake([
            'https://api-sandbox.tamara.test/payments/capture' => Http::response(['capture_id' => 'cap-2']),
        ]);

        $payload = [
            'scope' => 'store/order/statusUpdated',
            'producer' => 'stores/abc123',
            'hash' => 'bc-event-shipped-format',
            'data' => [
                'type' => 'order',
                'id' => 127,
                'status' => ['previous_status_id' => 11, 'new_status_id' => 2],
            ],
        ];
        $headers = [
            'X-Tamara-Webhook-Token' => hash_hmac('sha256', 'abc123', (string) config('bigcommerce.client_secret')),
        ];

        $this->withHeaders($headers)->postJson('/webhooks/bigcommerce', $payload)->assertNoContent();
        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        Http::assertSent(fn ($request) => ($request['total_amount']['amount'] ?? null) === 109.0
            && is_float($request['total_amount']['amount']));
    }

    public function test_capture_job_waits_until_payment_is_authorised(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout120',
            'bc_order_id' => '120', 'tamara_order_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'approved',
        ]);
        $event = WebhookEvent::query()->create([
            'store_id' => $this->store->id,
            'source' => WebhookSource::BigCommerce,
            'external_id' => 'bc-event-approved',
            'event_type' => 'store/order/statusUpdated',
            'payload' => [
                'scope' => 'store/order/statusUpdated',
                'data' => ['type' => 'order', 'id' => 120, 'status' => ['new_status_id' => 2]],
            ],
            'received_at' => now(),
        ]);
        Http::fake();

        (new CaptureTamaraPayment($event->id))->handle(app(TamaraOrderService::class));

        Http::assertNothingSent();
        $event->refresh();
        $this->assertNull($event->processed_at);
        $session->refresh();
        $this->assertSame('approved', $session->status->value);
    }

    public function test_capture_job_ignores_non_authorised_payment(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout121',
            'bc_order_id' => '121', 'tamara_order_id' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'pending',
        ]);
        $event = WebhookEvent::query()->create([
            'store_id' => $this->store->id,
            'source' => WebhookSource::BigCommerce,
            'external_id' => 'bc-event-pending',
            'event_type' => 'store/order/statusUpdated',
            'payload' => [
                'scope' => 'store/order/statusUpdated',
                'data' => ['type' => 'order', 'id' => 121, 'status' => ['new_status_id' => 2]],
            ],
            'received_at' => now(),
        ]);
        Http::fake();

        (new CaptureTamaraPayment($event->id))->handle(app(TamaraOrderService::class));

        Http::assertNothingSent();
        $this->assertDatabaseHas('webhook_events', [
            'id' => $event->id,
            'processing_status' => 'ignored',
        ]);
    }

    public function test_checkout_complete_sets_captured_when_tamara_order_is_fully_captured(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout128',
            'bc_order_id' => '128', 'tamara_order_id' => '88888888-8888-8888-8888-888888888888',
            'checkout_token' => 'token-128', 'amount' => 109, 'currency' => 'SAR', 'status' => 'authorised',
        ]);
        Http::fake([
            'https://api-sandbox.tamara.test/merchants/orders/*' => Http::response([
                'order_id' => '88888888-8888-8888-8888-888888888888',
                'status' => 'fully_captured',
                'total_amount' => ['amount' => 109, 'currency' => 'SAR'],
            ]),
        ]);

        $this->get(route('checkout.complete', ['result' => 'success', 'session' => $session->id]))
            ->assertRedirect();

        $session->refresh();
        $this->assertSame('captured', $session->status->value);
        $this->assertNotNull($session->captured_at);
    }

    public function test_bigcommerce_order_status_updated_to_refunded_dispatches_refund(): void
    {
        Queue::fake();
        $payload = [
            'scope' => 'store/order/statusUpdated',
            'producer' => 'stores/abc123',
            'hash' => 'bc-event-refunded',
            'data' => [
                'type' => 'order',
                'id' => 127,
                'status' => ['previous_status_id' => 2, 'new_status_id' => 4],
            ],
        ];
        $headers = [
            'X-Tamara-Webhook-Token' => hash_hmac('sha256', 'abc123', (string) config('bigcommerce.client_secret')),
        ];

        $this->withHeaders($headers)->postJson('/webhooks/bigcommerce', $payload)->assertNoContent();

        Queue::assertPushed(RefundTamaraPayment::class, 1);
        Queue::assertNotPushed(CaptureTamaraPayment::class);
    }

    public function test_bigcommerce_order_refunded_webhook_refunds_tamara_payment(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout127',
            'bc_order_id' => '127', 'tamara_order_id' => '99999999-9999-9999-9999-999999999999',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'captured', 'captured_at' => now(),
            'tamara_snapshot' => ['captured_amount' => ['amount' => 109, 'currency' => 'SAR']],
        ]);
        Http::fake([
            'https://api-sandbox.tamara.test/payments/simplified-refund/*' => Http::response([
                'refund_id' => 'ref-1',
                'status' => 'fully_refunded',
                'refunded_amount' => ['amount' => 109, 'currency' => 'SAR'],
            ]),
        ]);

        $payload = [
            'scope' => 'store/order/statusUpdated',
            'producer' => 'stores/abc123',
            'hash' => 'bc-event-refunded-process',
            'data' => [
                'type' => 'order',
                'id' => 127,
                'status' => ['previous_status_id' => 2, 'new_status_id' => 4],
            ],
        ];
        $headers = [
            'X-Tamara-Webhook-Token' => hash_hmac('sha256', 'abc123', (string) config('bigcommerce.client_secret')),
        ];

        $this->withHeaders($headers)->postJson('/webhooks/bigcommerce', $payload)->assertNoContent();
        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $session->refresh();
        $this->assertSame('refunded', $session->status->value);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/payments/simplified-refund/')
            && ($request['total_amount']['amount'] ?? null) === 109.0);
    }

    public function test_refund_job_ignores_non_captured_payment(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout126',
            'bc_order_id' => '126', 'tamara_order_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'authorised', 'authorised_at' => now(),
        ]);
        $event = WebhookEvent::query()->create([
            'store_id' => $this->store->id,
            'source' => WebhookSource::BigCommerce,
            'external_id' => 'bc-event-refund-authorised',
            'event_type' => 'store/order/statusUpdated',
            'payload' => [
                'scope' => 'store/order/statusUpdated',
                'data' => ['type' => 'order', 'id' => 126, 'status' => ['new_status_id' => 4]],
            ],
            'received_at' => now(),
        ]);
        Http::fake();

        (new RefundTamaraPayment($event->id))->handle(app(TamaraOrderService::class));

        Http::assertNothingSent();
        $this->assertDatabaseHas('webhook_events', [
            'id' => $event->id,
            'processing_status' => 'ignored',
        ]);
    }

    public function test_refund_payload_uses_captured_amount_from_snapshot(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout129',
            'bc_order_id' => '129', 'tamara_order_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            'amount' => '109.000', 'currency' => 'SAR', 'status' => 'captured', 'captured_at' => now(),
            'tamara_snapshot' => ['captured_amount' => ['amount' => 109, 'currency' => 'SAR']],
        ]);
        Http::fake([
            'https://api-sandbox.tamara.test/payments/simplified-refund/*' => Http::response([
                'status' => 'fully_refunded',
                'refunded_amount' => ['amount' => 109, 'currency' => 'SAR'],
            ]),
        ]);
        $event = WebhookEvent::query()->create([
            'store_id' => $this->store->id,
            'source' => WebhookSource::BigCommerce,
            'external_id' => 'bc-event-refund-format',
            'event_type' => 'store/order/statusUpdated',
            'payload' => [
                'scope' => 'store/order/statusUpdated',
                'data' => [
                    'type' => 'order',
                    'id' => 129,
                    'status' => ['new_status_id' => 4],
                    'refunded_amount' => ['amount' => 109, 'currency' => 'SAR'],
                ],
            ],
            'received_at' => now(),
        ]);

        (new RefundTamaraPayment($event->id))->handle(app(TamaraOrderService::class));

        Http::assertSent(fn ($request) => ($request['total_amount']['amount'] ?? null) === 109.0
            && ($request['total_amount']['currency'] ?? null) === 'SAR');
    }

    public function test_refund_payload_fetches_captured_amount_from_tamara_when_missing(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout130',
            'bc_order_id' => '130', 'tamara_order_id' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'captured', 'captured_at' => now(),
            'tamara_snapshot' => [],
        ]);
        Http::fake([
            'https://api-sandbox.tamara.test/merchants/orders/*' => Http::response([
                'status' => 'fully_captured',
                'captured_amount' => ['amount' => 109, 'currency' => 'SAR'],
            ]),
            'https://api-sandbox.tamara.test/payments/simplified-refund/*' => Http::response([
                'status' => 'fully_refunded',
                'refunded_amount' => ['amount' => 109, 'currency' => 'SAR'],
            ]),
        ]);
        $event = WebhookEvent::query()->create([
            'store_id' => $this->store->id,
            'source' => WebhookSource::BigCommerce,
            'external_id' => 'bc-event-refund-details',
            'event_type' => 'store/order/statusUpdated',
            'payload' => [
                'scope' => 'store/order/statusUpdated',
                'data' => ['type' => 'order', 'id' => 130, 'status' => ['new_status_id' => 4]],
            ],
            'received_at' => now(),
        ]);

        (new RefundTamaraPayment($event->id))->handle(app(TamaraOrderService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/payments/simplified-refund/')
            && ($request['total_amount']['amount'] ?? null) === 109.0);
    }

    public function test_tamara_order_refunded_webhook_sets_refunded_status(): void
    {
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes',
        ]);
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout125',
            'bc_order_id' => '125', 'tamara_order_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'captured', 'captured_at' => now(),
        ]);
        $jwt = JWT::encode(['iat' => now()->timestamp, 'exp' => now()->addMinute()->timestamp], 'notification-secret-token-32-bytes', 'HS256');
        $payload = [
            'order_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'order_reference_id' => '125',
            'event_type' => 'order_refunded',
            'data' => [
                'status' => 'fully_refunded',
                'refunded_amount' => ['amount' => 109.0, 'currency' => 'SAR'],
            ],
        ];

        $this->withToken($jwt)->postJson('/webhooks/tamara', $payload)->assertNoContent();

        $session->refresh();
        $this->assertSame('refunded', $session->status->value);
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
