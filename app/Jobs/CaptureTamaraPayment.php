<?php

namespace App\Jobs;

use App\Enums\PaymentSessionStatus;
use App\Enums\WebhookSource;
use App\Models\PaymentSession;
use App\Models\WebhookEvent;
use App\Services\Tamara\TamaraOrderService;
use App\Support\TamaraMoney;
use App\Support\TamaraOrderStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

final class CaptureTamaraPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $eventId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(TamaraOrderService $orders): void
    {
        $event = WebhookEvent::query()->with('paymentSession.store.tamaraConfig')->findOrFail($this->eventId);
        if ($event->processed_at) {
            return;
        }

        $session = $this->resolveSession($event);
        if ($event->source === WebhookSource::Tamara && $event->event_type === 'order_captured') {
            $this->syncTamaraCapture($session, $event);

            return;
        }

        if ($session->captured_at || $session->status === PaymentSessionStatus::Captured) {
            $event->update([
                'payment_session_id' => $session->id,
                'processing_status' => 'processed',
                'processed_at' => now(),
            ]);

            return;
        }

        if ($session->status !== PaymentSessionStatus::Authorised) {
            if ($session->status === PaymentSessionStatus::Approved) {
                $this->release(60);

                return;
            }

            $event->update([
                'payment_session_id' => $session->id,
                'processing_status' => 'ignored',
                'processed_at' => now(),
                'last_error' => 'Capture requires an authorised payment.',
            ]);

            return;
        }

        try {
            if (! $session->captured_at) {
                $response = $orders->capture($session->store, $session->tamara_order_id, $this->capturePayload($session, $event));
                $status = TamaraOrderStatus::fromDetails($response) ?? PaymentSessionStatus::Captured;
                $session->update([
                    'status' => $status === PaymentSessionStatus::PartiallyCaptured
                        ? PaymentSessionStatus::PartiallyCaptured
                        : PaymentSessionStatus::Captured,
                    'captured_at' => now(),
                    'tamara_snapshot' => array_merge($session->tamara_snapshot ?? [], $response),
                ]);
            }
            $event->update([
                'payment_session_id' => $session->id,
                'processing_status' => 'processed',
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $event->update([
                'processing_status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
                'attempts' => $event->attempts + 1,
            ]);
            throw $e;
        }
    }

    private function capturePayload(PaymentSession $session, WebhookEvent $event): array
    {
        return [
            'total_amount' => $this->captureAmount($session),
            'shipping_info' => $this->shippingInfo($event, $session),
        ];
    }

    private function captureAmount(PaymentSession $session): array
    {
        $snapshot = $session->tamara_snapshot ?? [];
        $amount = data_get($snapshot, 'authorized_amount.amount')
            ?? data_get($snapshot, 'total_amount.amount')
            ?? $session->amount;

        return TamaraMoney::format($amount, $session->currency);
    }

    private function shippingInfo(WebhookEvent $event, PaymentSession $session): array
    {
        $data = data_get($event->payload, 'data', []);
        $shippedAt = data_get($data, 'date_created')
            ?? data_get($data, 'shipped_at')
            ?? now()->toIso8601String();

        return [
            'shipped_at' => Carbon::parse($shippedAt)->toIso8601String(),
            'shipping_company' => (string) (
                data_get($data, 'shipping_provider')
                ?? data_get($data, 'shipping_method')
                ?? data_get($data, 'shipping_company')
                ?? 'BigCommerce'
            ),
            'tracking_number' => (string) (
                data_get($data, 'tracking_number')
                ?? data_get($data, 'tracking_id')
                ?? $session->bc_order_id
            ),
        ];
    }

    private function syncTamaraCapture(PaymentSession $session, WebhookEvent $event): void
    {
        $payload = $event->payload ?? [];
        $status = $this->resolveCaptureStatus($session, $payload);
        $session->update([
            'status' => $status,
            'captured_at' => $session->captured_at ?? now(),
            'tamara_snapshot' => array_merge($session->tamara_snapshot ?? [], $payload),
        ]);
        $event->update([
            'payment_session_id' => $session->id,
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    private function resolveCaptureStatus(PaymentSession $session, array $payload): PaymentSessionStatus
    {
        if (TamaraOrderStatus::isFullyCaptured($payload)) {
            return PaymentSessionStatus::Captured;
        }

        $capturedAmount = data_get($payload, 'data.captured_amount.amount');
        if ($capturedAmount !== null) {
            return (float) $capturedAmount >= (float) $session->amount
                ? PaymentSessionStatus::Captured
                : PaymentSessionStatus::PartiallyCaptured;
        }

        $status = TamaraOrderStatus::fromTamaraStatus((string) data_get($payload, 'data.status'));
        if ($status === PaymentSessionStatus::PartiallyCaptured) {
            return PaymentSessionStatus::PartiallyCaptured;
        }

        return PaymentSessionStatus::Captured;
    }

    private function resolveSession(WebhookEvent $event): PaymentSession
    {
        if ($event->paymentSession) {
            return $event->paymentSession;
        }

        $orderId = data_get($event->payload, 'data.order_id')
            ?? data_get($event->payload, 'data.id')
            ?? data_get($event->payload, 'order_reference_id');

        return PaymentSession::query()
            ->with('store.tamaraConfig')
            ->where('store_id', $event->store_id)
            ->where('bc_order_id', (string) $orderId)
            ->firstOrFail();
    }
}
