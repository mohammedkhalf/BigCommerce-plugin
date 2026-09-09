<?php

namespace App\Http\Controllers;

use App\Models\PaymentSession;
use App\Support\StoreContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AdminPageController
{
    public function dashboard(Request $request): Response
    {
        $context = $this->context($request);
        $payments = $context->store->paymentSessions()->latest()->limit(5)->get();
        $attempts = $context->store->paymentSessions()->count();
        $approved = $context->store->paymentSessions()->whereIn('status', ['approved', 'authorised', 'completed'])->count();
        $volume = $context->store->paymentSessions()->whereIn('status', ['approved', 'authorised', 'completed'])->sum('amount');

        return Inertia::render('Dashboard', $this->shared($request) + [
            'connected' => $context->store->tamaraConfig()->exists(),
            'environment' => $this->environment($context),
            'stats' => [
                'totalVolume' => (float) $volume,
                'payments' => $attempts,
                'approvalRate' => $attempts > 0 ? (int) round(($approved / $attempts) * 100) : 0,
                'currency' => $payments->first()?->currency ?? 'SAR',
            ],
            'recentPayments' => $payments->map(fn (PaymentSession $payment) => $this->serializePayment($payment)),
        ]);
    }

    public function payments(Request $request): Response
    {
        $context = $this->context($request);

        return Inertia::render('Payments/Index', $this->shared($request) + [
            'payments' => $context->store->paymentSessions()->latest()->paginate(25)
                ->through(fn (PaymentSession $payment) => $this->serializePayment($payment)),
        ]);
    }

    public function payment(Request $request, string $payment): Response
    {
        $context = $this->context($request);
        $session = $context->store->paymentSessions()->whereKey($payment)->firstOrFail();

        return Inertia::render('Payments/Show', $this->shared($request) + ['payment' => $this->serializePayment($session)]);
    }

    public function settings(Request $request): Response
    {
        $context = $this->context($request);
        $config = $context->store->tamaraConfig()->first();

        return Inertia::render('Settings', $this->shared($request) + [
            'connected' => $config !== null,
            'enabled' => $config?->enabled ?? false,
            'environment' => $this->environment($context),
            'merchantTokenConfigured' => filled($config?->api_token),
            'notificationTokenConfigured' => filled($config?->notification_token),
            'publicKeyConfigured' => filled($config?->public_key),
            'currencies' => $config?->currency_allowlist ?? [],
            'lastTestedAt' => $config?->last_tested_at?->toIso8601String(),
            'saveEndpoint' => route('settings.save'),
            'testEndpoint' => route('settings.test'),
            'enableEndpoint' => route('settings.enable'),
            'disconnectEndpoint' => route('settings.disconnect'),
            'webhookUrl' => route('webhooks.tamara'),
        ]);
    }

    public function help(Request $request): Response
    {
        return Inertia::render('Help', $this->shared($request));
    }

    private function shared(Request $request): array
    {
        $context = $this->context($request);
        $metadata = $context->store->metadata ?? [];

        return [
            'appToken' => $request->bearerToken(),
            'store' => [
                'id' => $context->store->id,
                'hash' => $context->store->store_hash,
                'name' => $context->store->name ?? $metadata['name'] ?? null,
                'environment' => $this->environment($context),
            ],
            'user' => [
                'id' => $context->user->id,
                'email' => $context->user->email,
                'name' => $context->user->name,
                'isOwner' => $context->user->is_owner,
            ],
        ];
    }

    private function context(Request $request): StoreContext
    {
        return $request->attributes->get(StoreContext::class);
    }

    private function environment(StoreContext $context): string
    {
        return $context->store->tamaraConfig?->mode?->value === 'production' ? 'live' : 'sandbox';
    }

    private function serializePayment(PaymentSession $payment): array
    {
        return [
            'id' => $payment->id,
            'orderId' => $payment->bc_order_id,
            'customer' => $payment->customerDisplayName(),
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status->value,
            'checkoutId' => $payment->bc_checkout_id,
            'reference' => $payment->tamara_order_id,
            'createdAt' => $payment->created_at?->toIso8601String(),
            'updatedAt' => $payment->updated_at?->toIso8601String(),
        ];
    }
}
