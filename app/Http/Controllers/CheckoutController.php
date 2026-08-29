<?php

namespace App\Http\Controllers;

use App\Enums\PaymentSessionStatus;
use App\Models\PaymentSession;
use App\Models\Store;
use App\Services\BigCommerce\CheckoutService;
use App\Services\Tamara\TamaraCheckoutService;
use App\Services\Tamara\TamaraOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CheckoutController
{
    public function __construct(
        private CheckoutService $bigCommerce,
        private TamaraCheckoutService $tamara,
        private TamaraOrderService $orders,
    ) {}

    public function options(Request $request): Response
    {
        $store = Store::query()->where('store_hash', $request->header('X-Store-Hash'))->firstOrFail();
        $this->assertOrigin($request, $store);

        return response('', 204, $this->corsHeaders($request));
    }

    public function start(Request $request): JsonResponse
    {
        $input = $request->validate([
            'store_hash' => ['required', 'string', 'regex:/^[a-z0-9]{2,32}$/i'],
            'checkout_id' => ['required', 'string', 'regex:/^[a-z0-9_-]{6,128}$/i'],
        ]);
        $store = Store::query()->where('store_hash', $input['store_hash'])
            ->whereNull('uninstalled_at')->whereNotNull('access_token')
            ->whereHas('tamaraConfig', fn ($query) => $query->where('enabled', true))
            ->firstOrFail();
        $this->assertOrigin($request, $store);

        $existing = $store->paymentSessions()->where('bc_checkout_id', $input['checkout_id'])->first();
        if ($existing && filled($existing->tamara_snapshot['checkout_url'] ?? null)) {
            return response()->json(['checkout_url' => $existing->tamara_snapshot['checkout_url']])->withHeaders($this->corsHeaders($request));
        }

        $raw = $this->bigCommerce->get($store, $input['checkout_id']);
        $checkout = $raw['data'] ?? $raw;
        $amount = (float) ($checkout['grandTotal'] ?? $checkout['cart']['cartAmount'] ?? 0);
        $currency = strtoupper((string) ($checkout['currency']['code'] ?? $checkout['cart']['currency']['code'] ?? $store->currency));
        if ($amount <= 0 || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ValidationException::withMessages(['checkout_id' => 'BigCommerce returned an invalid checkout total.']);
        }
        $allowlist = $store->tamaraConfig->currency_allowlist ?? [];
        if ($allowlist && ! in_array($currency, $allowlist, true)) {
            throw ValidationException::withMessages(['checkout_id' => 'Tamara is not enabled for this currency.']);
        }

        $token = $this->bigCommerce->createToken($store, $input['checkout_id']);
        $order = $this->bigCommerce->createOrder($store, $input['checkout_id']);
        $orderData = $order['data'] ?? $order;
        $orderId = (string) ($orderData['id'] ?? $orderData['orderId'] ?? '');
        if ($orderId === '') {
            throw ValidationException::withMessages(['checkout_id' => 'BigCommerce did not create an order.']);
        }

        $session = DB::transaction(fn () => PaymentSession::query()->firstOrCreate(
            ['store_id' => $store->id, 'bc_checkout_id' => $input['checkout_id']],
            [
                'bc_order_id' => $orderId, 'checkout_token' => $token,
                'status' => PaymentSessionStatus::Pending, 'amount' => $amount,
                'currency' => $currency, 'bc_snapshot' => $checkout,
                'expires_at' => now()->addHour(),
            ],
        ));
        if (! $session->wasRecentlyCreated && filled($session->tamara_snapshot['checkout_url'] ?? null)) {
            return response()->json(['checkout_url' => $session->tamara_snapshot['checkout_url']])->withHeaders($this->corsHeaders($request));
        }

        $payload = $this->tamaraPayload($session, $checkout);
        $tamara = $this->tamara->create($store, $payload);
        $session->update([
            'tamara_order_id' => $tamara['order_id'] ?? null,
            'tamara_checkout_id' => $tamara['checkout_id'] ?? null,
            'tamara_snapshot' => $tamara,
        ]);

        return response()->json(['checkout_url' => $tamara['checkout_url'] ?? $tamara['url'] ?? null])->withHeaders($this->corsHeaders($request));
    }

    public function complete(Request $request, string $result): RedirectResponse
    {
        $session = PaymentSession::query()->whereKey($request->query('session'))->firstOrFail();
        abort_unless($session->tamara_order_id, 404);
        $details = $this->orders->details($session->store, $session->tamara_order_id);
        $status = strtolower((string) ($details['status'] ?? $details['order_status'] ?? ''));
        if ($result !== 'success' || ! in_array($status, ['approved', 'authorised', 'authorized', 'fully_captured'], true)) {
            return redirect()->away($session->store->metadata['secure_url'] ?? '/');
        }
        $session->update(['status' => PaymentSessionStatus::Approved, 'tamara_snapshot' => $details]);
        $base = rtrim((string) ($session->store->metadata['secure_url'] ?? ''), '/');

        return redirect()->away("{$base}/checkout/order-confirmation/{$session->bc_order_id}?t=".urlencode($session->checkout_token));
    }

    private function tamaraPayload(PaymentSession $session, array $checkout): array
    {
        $customer = $checkout['customer'] ?? [];
        $address = $checkout['billingAddress'] ?? $checkout['consignments'][0]['shippingAddress'] ?? [];
        $return = fn (string $result) => route('checkout.complete', ['result' => $result, 'session' => $session->id]);

        return [
            'order_reference_id' => $session->bc_order_id,
            'order_number' => $session->bc_order_id,
            'total_amount' => ['amount' => $session->amount, 'currency' => $session->currency],
            'description' => 'BigCommerce order '.$session->bc_order_id,
            'country_code' => $address['countryCode'] ?? 'SA',
            'payment_type' => 'PAY_BY_INSTALMENTS',
            'locale' => str_replace('-', '_', $session->store->locale ?? 'en_US'),
            'items' => $this->items($checkout, $session->currency),
            'consumer' => [
                'first_name' => $customer['firstName'] ?? $address['firstName'] ?? '',
                'last_name' => $customer['lastName'] ?? $address['lastName'] ?? '',
                'phone_number' => $address['phone'] ?? '',
                'email' => $customer['email'] ?? $address['email'] ?? '',
            ],
            'billing_address' => $this->address($address),
            'shipping_address' => $this->address($address),
            'merchant_url' => [
                'success' => $return('success'), 'failure' => $return('failure'),
                'cancel' => $return('cancel'), 'notification' => route('webhooks.tamara'),
            ],
            'platform' => 'BigCommerce',
        ];
    }

    private function items(array $checkout, string $currency): array
    {
        $groups = $checkout['cart']['lineItems'] ?? [];

        return collect($groups)->flatten(1)->map(fn ($item) => [
            'reference_id' => (string) ($item['productId'] ?? $item['id']),
            'type' => 'Physical', 'name' => $item['name'] ?? 'Item',
            'sku' => $item['sku'] ?? '', 'quantity' => $item['quantity'] ?? 1,
            'unit_price' => ['amount' => $item['salePrice'] ?? $item['listPrice'] ?? 0, 'currency' => $currency],
            'total_amount' => ['amount' => $item['extendedSalePrice'] ?? 0, 'currency' => $currency],
        ])->values()->all();
    }

    private function address(array $address): array
    {
        return [
            'first_name' => $address['firstName'] ?? '', 'last_name' => $address['lastName'] ?? '',
            'line1' => $address['address1'] ?? '', 'line2' => $address['address2'] ?? '',
            'city' => $address['city'] ?? '', 'region' => $address['stateOrProvince'] ?? '',
            'postal_code' => $address['postalCode'] ?? '', 'country_code' => $address['countryCode'] ?? 'SA',
        ];
    }

    private function assertOrigin(Request $request, Store $store): void
    {
        $origin = $request->headers->get('Origin');
        $allowed = parse_url((string) ($store->metadata['secure_url'] ?? ''), PHP_URL_HOST);
        abort_unless(
            $origin
            && $allowed
            && hash_equals(strtolower($allowed), strtolower((string) parse_url($origin, PHP_URL_HOST))),
            403,
            'Origin is not allowed.',
        );
    }

    private function corsHeaders(Request $request): array
    {
        return [
            'Access-Control-Allow-Origin' => $request->header('Origin', ''),
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Accept, Content-Type, X-Store-Hash',
            'Vary' => 'Origin',
        ];
    }
}
