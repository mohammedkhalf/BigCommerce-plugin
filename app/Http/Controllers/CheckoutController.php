<?php

namespace App\Http\Controllers;

use App\Enums\PaymentSessionStatus;
use App\Support\TamaraOrderStatus;
use App\Models\PaymentSession;
use App\Models\Store;
use App\Services\BigCommerce\CheckoutService;
use App\Services\Tamara\TamaraCheckoutService;
use App\Services\Tamara\TamaraOrderService;
use App\Support\BigCommerceScopes;
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

        $missingScopes = BigCommerceScopes::missingForCheckout($store);
        if ($missingScopes !== []) {
            return response()->json([
                'message' => 'The app is missing required BigCommerce OAuth scopes for checkout. In BigCommerce DevTools, enable Checkouts (modify), save the app, then reinstall it on this store.',
                'missing_scope_groups' => $missingScopes,
                'granted_scopes' => $store->scopes ?? [],
            ], 503)->withHeaders($this->corsHeaders($request));
        }

        $existing = $store->paymentSessions()->where('bc_checkout_id', $input['checkout_id'])->first();

        if ($existing && filled($existing->tamara_snapshot['checkout_url'] ?? null)) {
            return response()->json(['checkout_url' => $existing->tamara_snapshot['checkout_url']])->withHeaders($this->corsHeaders($request));
        }

        $raw = $this->bigCommerce->get($store, $input['checkout_id']);
        $checkout = $raw['data'] ?? $raw;

        try {
            $checkout = $this->bigCommerce->ensureShippingSelected($store, $input['checkout_id'], $checkout);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['checkout_id' => $e->getMessage()]);
        }

        $amount = $this->resolveCheckoutAmount($checkout);
        $currency = $this->resolveCheckoutCurrency($checkout, $store->currency);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'checkout_id' => 'BigCommerce returned an invalid checkout total. Add billing address and shipping consignment in Postman (steps 4–5), then verify grandTotal or cartAmount is greater than zero.',
            ]);
        }
        if ($currency === null) {
            throw ValidationException::withMessages([
                'checkout_id' => 'BigCommerce returned an invalid checkout currency. Complete the checkout in Postman or sync store metadata.',
            ]);
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
        $mapped = TamaraOrderStatus::fromDetails($details);
        if ($result !== 'success' || ! in_array($mapped, [
            PaymentSessionStatus::Approved,
            PaymentSessionStatus::Authorised,
            PaymentSessionStatus::Captured,
        ], true)) {
            return redirect()->away($session->store->metadata['secure_url'] ?? '/');
        }

        $this->applyTamaraDetails($session, $details, $mapped);
        $base = rtrim((string) ($session->store->metadata['secure_url'] ?? ''), '/');

        return redirect()->away("{$base}/checkout/order-confirmation/{$session->bc_order_id}?t=".urlencode($session->checkout_token));
    }

    private function applyTamaraDetails(
        PaymentSession $session,
        array $details,
        PaymentSessionStatus $mapped,
    ): void {
        if ($session->status === PaymentSessionStatus::Completed) {
            $session->update(['tamara_snapshot' => $details]);

            return;
        }

        $updates = ['tamara_snapshot' => $details];

        if ($mapped === PaymentSessionStatus::Captured) {
            $updates['status'] = PaymentSessionStatus::Captured;
            $updates['captured_at'] = $session->captured_at ?? now();
        } elseif ($mapped === PaymentSessionStatus::Authorised && ! in_array($session->status, [
            PaymentSessionStatus::Captured,
            PaymentSessionStatus::PartiallyCaptured,
        ], true)) {
            $updates['status'] = PaymentSessionStatus::Authorised;
            $updates['authorised_at'] = $session->authorised_at ?? now();
        } elseif ($mapped === PaymentSessionStatus::Approved && ! in_array($session->status, [
            PaymentSessionStatus::Authorised,
            PaymentSessionStatus::Captured,
            PaymentSessionStatus::PartiallyCaptured,
        ], true)) {
            $updates['status'] = PaymentSessionStatus::Approved;
        }

        $session->update($updates);
    }

    private function tamaraPayload(PaymentSession $session, array $checkout): array
    {
        $customer = $checkout['customer'] ?? [];
        $address = $checkout['billingAddress']
            ?? $checkout['billing_address']
            ?? $checkout['consignments'][0]['shippingAddress']
            ?? $checkout['consignments'][0]['shipping_address']
            ?? [];
        $return = fn (string $result) => route('checkout.complete', ['result' => $result, 'session' => $session->id]);

        return [
            'order_reference_id' => $session->bc_order_id,
            'order_number' => $session->bc_order_id,
            'total_amount' => $this->money($session->amount, $session->currency),
            'description' => 'BigCommerce order '.$session->bc_order_id,
            'country_code' => $this->pick($address, 'countryCode', 'country_code', 'SA'),
            'payment_type' => 'PAY_BY_INSTALMENTS',
            'locale' => str_replace('-', '_', $session->store->locale ?? 'en_US'),
            'items' => $this->items($checkout, $session->currency),
            'consumer' => [
                'first_name' => $this->pick($customer, 'firstName', 'first_name', $this->pick($address, 'firstName', 'first_name')),
                'last_name' => $this->pick($customer, 'lastName', 'last_name', $this->pick($address, 'lastName', 'last_name')),
                'phone_number' => $this->pick($address, 'phone', 'phone'),
                'email' => $this->pick($customer, 'email', 'email', $this->pick($address, 'email', 'email')),
            ],
            'billing_address' => $this->address($address),
            'shipping_address' => $this->address($address),
            'tax_amount' => $this->money($this->checkoutTaxAmount($checkout), $session->currency),
            'shipping_amount' => $this->money($this->checkoutShippingAmount($checkout), $session->currency),
            'merchant_url' => [
                'success' => $return('success'), 'failure' => $return('failure'),
                'cancel' => $return('cancel'), 'notification' => route('webhooks.tamara'),
            ],
            'platform' => 'BigCommerce',
        ];
    }

    private function items(array $checkout, string $currency): array
    {
        $lineItems = $checkout['cart']['lineItems'] ?? $checkout['cart']['line_items'] ?? [];

        return collect([
            ...($lineItems['physicalItems'] ?? $lineItems['physical_items'] ?? []),
            ...($lineItems['digitalItems'] ?? $lineItems['digital_items'] ?? []),
            ...($lineItems['customItems'] ?? $lineItems['custom_items'] ?? []),
        ])->map(fn ($item) => [
            'reference_id' => (string) ($this->pick($item, 'productId', 'product_id') ?: $item['id']),
            'type' => 'Physical', 'name' => $item['name'] ?? 'Item',
            'sku' => $item['sku'] ?? '', 'quantity' => $item['quantity'] ?? 1,
            'unit_price' => $this->money(
                $this->pick($item, 'salePrice', 'sale_price', $this->pick($item, 'listPrice', 'list_price', 0)),
                $currency,
            ),
            'total_amount' => $this->money(
                $this->pick($item, 'extendedSalePrice', 'extended_sale_price', 0),
                $currency,
            ),
        ])->values()->all();
    }

    private function address(array $address): array
    {
        return [
            'first_name' => $this->pick($address, 'firstName', 'first_name'),
            'last_name' => $this->pick($address, 'lastName', 'last_name'),
            'line1' => $this->pick($address, 'address1', 'address1'),
            'line2' => $this->pick($address, 'address2', 'address2'),
            'city' => $this->pick($address, 'city', 'city'),
            'region' => $this->pick($address, 'stateOrProvince', 'state_or_province'),
            'postal_code' => $this->pick($address, 'postalCode', 'postal_code'),
            'country_code' => $this->pick($address, 'countryCode', 'country_code', 'SA'),
        ];
    }

    private function resolveCheckoutAmount(array $checkout): float
    {
        $grandTotal = (float) ($checkout['grandTotal'] ?? $checkout['grand_total'] ?? 0);
        if ($grandTotal > 0) {
            return $grandTotal;
        }

        $cart = $checkout['cart'] ?? [];
        foreach (['cartAmount', 'cart_amount_inc_tax', 'cart_amount_ex_tax', 'baseAmount', 'base_amount'] as $key) {
            $amount = (float) ($cart[$key] ?? 0);
            if ($amount > 0) {
                return $amount;
            }
        }

        return 0;
    }

    private function pick(array $data, string $camel, string $snake, mixed $default = ''): mixed
    {
        return $data[$camel] ?? $data[$snake] ?? $default;
    }

    private function money(float|int|string $amount, string $currency): array
    {
        return ['amount' => round((float) $amount, 2), 'currency' => $currency];
    }

    private function checkoutTaxAmount(array $checkout): float
    {
        return (float) ($checkout['tax_total'] ?? $checkout['taxTotal'] ?? 0);
    }

    private function checkoutShippingAmount(array $checkout): float
    {
        $shipping = 0.0;
        foreach ($checkout['consignments'] ?? [] as $consignment) {
            $shipping += (float) (
                $consignment['shipping_cost_inc_tax']
                ?? $consignment['shippingCostIncTax']
                ?? $consignment['shipping_cost_ex_tax']
                ?? $consignment['shippingCostExTax']
                ?? 0
            );
        }

        return $shipping;
    }

    private function resolveCheckoutCurrency(array $checkout, ?string $storeCurrency): ?string
    {
        $cartCurrency = $checkout['cart']['currency'] ?? null;
        $candidates = [
            $checkout['currency']['code'] ?? null,
            is_array($cartCurrency) ? ($cartCurrency['code'] ?? null) : $cartCurrency,
            is_string($checkout['currency'] ?? null) ? $checkout['currency'] : null,
            $storeCurrency,
        ];

        foreach ($candidates as $code) {
            $code = strtoupper(trim((string) $code));
            if (preg_match('/^[A-Z]{3}$/', $code)) {
                return $code;
            }
        }

        return null;
    }

    private function assertOrigin(Request $request, Store $store): void
    {
        $origin = $request->headers->get('Origin');
        $secureUrl = (string) ($store->metadata['secure_url'] ?? '');
        $allowed = parse_url($secureUrl, PHP_URL_HOST);
        $originHost = $origin ? parse_url($origin, PHP_URL_HOST) : null;

        abort_unless(
            $originHost
            && $allowed
            && hash_equals(strtolower($allowed), strtolower((string) $originHost)),
            403,
            $secureUrl === ''
                ? 'Origin is not allowed. Store secure_url is not configured yet.'
                : "Origin is not allowed. Set the Origin header to {$secureUrl}",
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
