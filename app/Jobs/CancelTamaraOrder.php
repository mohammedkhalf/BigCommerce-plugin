<?php

namespace App\Jobs;

use App\Enums\PaymentSessionStatus;
use App\Models\PaymentSession;
use App\Models\WebhookEvent;
use App\Services\Tamara\TamaraOrderService;
use App\Support\TamaraMoney;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class CancelTamaraOrder implements ShouldQueue
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

        if ($session->cancelled_at || $session->status === PaymentSessionStatus::Cancelled) {
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
                'last_error' => 'Cancel requires an authorised payment.',
            ]);

            return;
        }

        try {
            $response = $orders->cancel($session->store, $session->tamara_order_id, [
                'total_amount' => $this->cancelAmount($session),
            ], $session->bc_order_id);
            $session->update([
                'status' => PaymentSessionStatus::Cancelled,
                'cancelled_at' => now(),
                'tamara_snapshot' => array_merge($session->tamara_snapshot ?? [], $response),
            ]);
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

    private function cancelAmount(PaymentSession $session): array
    {
        $snapshot = $session->tamara_snapshot ?? [];
        foreach ([
            data_get($snapshot, 'authorized_amount'),
            data_get($snapshot, 'total_amount'),
        ] as $candidate) {
            $amount = TamaraMoney::extractAmount($candidate);
            if ($amount !== null && $amount > 0) {
                return TamaraMoney::format($amount, $session->currency);
            }
        }

        return TamaraMoney::format($session->amount, $session->currency);
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
