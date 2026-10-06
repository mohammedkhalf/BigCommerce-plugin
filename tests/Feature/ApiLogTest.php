<?php

namespace Tests\Feature;

use App\Enums\WebhookSource;
use App\Jobs\AuthoriseTamaraOrder;
use App\Jobs\CancelTamaraOrder;
use App\Jobs\CaptureTamaraPayment;
use App\Jobs\RefundTamaraPayment;
use App\Models\ApiLog;
use App\Models\PaymentSession;
use App\Models\Store;
use App\Models\WebhookEvent;
use App\Services\Tamara\TamaraOrderService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiLogTest extends TestCase
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
        config()->set('bigcommerce.api_url', 'https://api.bigcommerce.test');
        config()->set('tamara.sandbox_url', 'https://api-sandbox.tamara.test');
        $this->store = Store::query()->create([
            'store_hash' => 'abc123', 'access_token' => 'bc-secret',
            'currency' => 'SAR', 'installed_at' => now(),
            'metadata' => ['secure_url' => 'https://shop.example'],
            'scopes' => ['store_checkout', 'store_v2_orders'],
        ]);
        $this->store->tamaraConfig()->create([
            'enabled' => true, 'mode' => 'sandbox', 'api_token' => 'merchant-secret-token',
            'notification_token' => 'notification-secret-token-32-bytes', 'currency_allowlist' => ['SAR'],
        ]);
    }

    public function test_checkout_start_logs_tamara_request_and_response(): void
    {
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
            ->assertOk();

        $log = ApiLog::query()->where('action', 'checkout')->first();
        $this->assertNotNull($log);
        $this->assertSame('1234', $log->bc_order_id);
        $this->assertSame('1234', $log->request_payload['order_reference_id'] ?? null);
        $this->assertSame('tamara-1', $log->response_payload['order_id'] ?? null);
        $this->assertSame(200, $log->http_status);
        $this->assertNotNull($log->logged_at);
    }

    public function test_authorise_is_not_written_to_api_logs(): void
    {
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout123',
            'bc_order_id' => '1234', 'tamara_order_id' => 'tamara-1',
            'amount' => 150, 'currency' => 'SAR',
        ]);
        $event = WebhookEvent::query()->create([
            'store_id' => $this->store->id,
            'payment_session_id' => $session->id,
            'source' => WebhookSource::Tamara,
            'external_id' => 'evt-authorise',
            'event_type' => 'order_approved',
            'payload' => ['order_id' => 'tamara-1'],
            'received_at' => now(),
        ]);
        Http::fake([
            'https://api-sandbox.tamara.test/orders/*/authorise' => Http::response(['status' => 'authorised']),
        ]);

        (new AuthoriseTamaraOrder($event->id))->handle(app(TamaraOrderService::class));

        $this->assertDatabaseMissing('api_logs', ['action' => 'authorised']);
        $this->assertDatabaseCount('api_logs', 0);
    }

    public function test_capture_refund_and_cancel_are_logged(): void
    {
        $captureSession = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout-cap',
            'bc_order_id' => '119', 'tamara_order_id' => 'tamara-cap',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'authorised', 'authorised_at' => now(),
        ]);
        $refundSession = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout-ref',
            'bc_order_id' => '127', 'tamara_order_id' => 'tamara-ref',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'captured', 'captured_at' => now(),
            'tamara_snapshot' => ['captured_amount' => ['amount' => 109, 'currency' => 'SAR']],
        ]);
        $cancelSession = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout-can',
            'bc_order_id' => '131', 'tamara_order_id' => 'tamara-can',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'authorised', 'authorised_at' => now(),
            'tamara_snapshot' => ['authorized_amount' => ['amount' => 109, 'currency' => 'SAR']],
        ]);
        $captureEvent = WebhookEvent::query()->create([
            'store_id' => $this->store->id, 'source' => WebhookSource::BigCommerce,
            'external_id' => 'bc-cap', 'event_type' => 'store/order/statusUpdated',
            'payload' => ['data' => ['id' => 119, 'status' => ['new_status_id' => 2]]],
            'received_at' => now(),
        ]);
        $refundEvent = WebhookEvent::query()->create([
            'store_id' => $this->store->id, 'source' => WebhookSource::BigCommerce,
            'external_id' => 'bc-ref', 'event_type' => 'store/order/statusUpdated',
            'payload' => ['data' => ['id' => 127, 'status' => ['new_status_id' => 4]]],
            'received_at' => now(),
        ]);
        $cancelEvent = WebhookEvent::query()->create([
            'store_id' => $this->store->id, 'source' => WebhookSource::BigCommerce,
            'external_id' => 'bc-can', 'event_type' => 'store/order/statusUpdated',
            'payload' => ['data' => ['id' => 131, 'status' => ['new_status_id' => 5]]],
            'received_at' => now(),
        ]);
        Http::fake([
            'https://api-sandbox.tamara.test/payments/capture' => Http::response(['status' => 'fully_captured']),
            'https://api-sandbox.tamara.test/payments/simplified-refund/*' => Http::response(['status' => 'fully_refunded']),
            'https://api-sandbox.tamara.test/orders/*/cancel' => Http::response(['status' => 'canceled']),
        ]);

        (new CaptureTamaraPayment($captureEvent->id))->handle(app(TamaraOrderService::class));
        (new RefundTamaraPayment($refundEvent->id))->handle(app(TamaraOrderService::class));
        (new CancelTamaraOrder($cancelEvent->id))->handle(app(TamaraOrderService::class));

        $this->assertSame('119', ApiLog::query()->where('action', 'captured')->value('bc_order_id'));
        $this->assertSame('127', ApiLog::query()->where('action', 'refunded')->value('bc_order_id'));
        $this->assertSame('131', ApiLog::query()->where('action', 'cancel')->value('bc_order_id'));
        $this->assertNotNull($captureSession->fresh()->captured_at);
        $this->assertSame('refunded', $refundSession->fresh()->status->value);
        $this->assertNotNull($cancelSession->fresh()->cancelled_at);
    }

    public function test_failed_tamara_request_is_logged_with_available_payloads(): void
    {
        $session = PaymentSession::query()->create([
            'store_id' => $this->store->id, 'bc_checkout_id' => 'checkout-fail',
            'bc_order_id' => null, 'tamara_order_id' => 'tamara-fail',
            'amount' => 109, 'currency' => 'SAR', 'status' => 'authorised', 'authorised_at' => now(),
        ]);
        $event = WebhookEvent::query()->create([
            'store_id' => $this->store->id,
            'payment_session_id' => $session->id,
            'source' => WebhookSource::BigCommerce,
            'external_id' => 'bc-fail', 'event_type' => 'store/order/statusUpdated',
            'payload' => ['data' => ['id' => 999, 'status' => ['new_status_id' => 2]]],
            'received_at' => now(),
        ]);
        Http::fake([
            'https://api-sandbox.tamara.test/payments/capture' => Http::response(['message' => 'capture failed'], 422),
        ]);

        try {
            (new CaptureTamaraPayment($event->id))->handle(app(TamaraOrderService::class));
            $this->fail('Capture should fail when Tamara returns 422.');
        } catch (RequestException) {
        }

        $log = ApiLog::query()->where('action', 'captured')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->bc_order_id);
        $this->assertSame('tamara-fail', $log->request_payload['order_id'] ?? null);
        $this->assertSame('capture failed', $log->response_payload['message'] ?? null);
        $this->assertSame(422, $log->http_status);
        $this->assertNotNull($log->logged_at);
        $this->assertNull($session->fresh()->captured_at);
    }

    public function test_webhooks_do_not_write_api_logs(): void
    {
        $jwt = JWT::encode(['iat' => now()->timestamp, 'exp' => now()->addMinute()->timestamp], 'notification-secret-token-32-bytes', 'HS256');
        PaymentSession::query()->create([
            'store_id' => $this->store->id,
            'bc_checkout_id' => 'checkout-auth',
            'bc_order_id' => '141',
            'tamara_order_id' => 'tamara-auth',
            'amount' => 109,
            'currency' => 'SAR',
            'status' => 'pending',
        ]);

        $this->withToken($jwt)->postJson('/webhooks/tamara', [
            'order_id' => 'tamara-auth',
            'order_reference_id' => '141',
            'event_type' => 'order_authorised',
            'data' => ['status' => 'order_authorised'],
        ])->assertNoContent();

        $this->assertDatabaseCount('api_logs', 0);
    }
}
