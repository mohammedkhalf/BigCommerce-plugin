<?php

namespace App\Jobs;

use App\Enums\PaymentSessionStatus;
use App\Enums\WebhookSource;
use App\Models\PaymentSession;
use App\Models\WebhookEvent;
use App\Services\Tamara\TamaraOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        $event = WebhookEvent::query()->with('paymentSession')->findOrFail($this->eventId);
        if ($event->processed_at) {
            return;
        }

        $session = $this->resolveSession($event);
        if ($event->source === WebhookSource::Tamara && $event->event_type === 'order_captured') {
            $this->syncTamaraCapture($session, $event);

            return;
        }

        try {
            if (! $session->captured_at) {
                $orders->capture($session->store, $session->tamara_order_id, [
                    'total_amount' => ['amount' => $session->amount, 'currency' => $session->currency],
                    'shipping_info' => data_get($event->payload, 'data', []),
                ]);
                $session->update([
                    'status' => PaymentSessionStatus::Captured,
                    'captured_at' => now(),
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

    private function syncTamaraCapture(PaymentSession $session, WebhookEvent $event): void
    {
        $status = $this->resolveCaptureStatus($session, $event->payload ?? []);
        $session->update([
            'status' => $status,
            'captured_at' => $session->captured_at ?? now(),
        ]);
        $event->update([
            'payment_session_id' => $session->id,
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    private function resolveCaptureStatus(PaymentSession $session, array $payload): PaymentSessionStatus
    {
        $capturedAmount = data_get($payload, 'data.captured_amount.amount');
        if ($capturedAmount !== null) {
            return (float) $capturedAmount >= (float) $session->amount
                ? PaymentSessionStatus::Captured
                : PaymentSessionStatus::PartiallyCaptured;
        }

        if (str_contains((string) data_get($payload, 'data.status'), 'partial')) {
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
            ?? data_get($event->payload, 'order_reference_id');

        return PaymentSession::query()
            ->where('store_id', $event->store_id)
            ->where('bc_order_id', (string) $orderId)
            ->firstOrFail();
    }
}
