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
use Throwable;

final class RefundTamaraPayment implements ShouldQueue
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

        if ($event->source === WebhookSource::Tamara && $event->event_type === 'order_refunded') {
            $this->syncTamaraRefund($session, $event);

            return;
        }

        if ($session->status === PaymentSessionStatus::Refunded) {
            $event->update([
                'payment_session_id' => $session->id,
                'processing_status' => 'processed',
                'processed_at' => now(),
            ]);

            return;
        }

        if (! in_array($session->status, [
            PaymentSessionStatus::Captured,
            PaymentSessionStatus::PartiallyCaptured,
        ], true)) {
            $event->update([
                'payment_session_id' => $session->id,
                'processing_status' => 'ignored',
                'processed_at' => now(),
                'last_error' => 'Refund requires a captured payment.',
            ]);

            return;
        }

        try {
            $response = $orders->refund($session->store, $session->tamara_order_id, [
                'total_amount' => $this->refundAmount($session, $event, $orders),
                'comment' => 'BigCommerce refund for order '.$session->bc_order_id,
            ]);
            $status = TamaraOrderStatus::fromDetails($response) ?? PaymentSessionStatus::Refunded;
            $session->update([
                'status' => $status === PaymentSessionStatus::PartiallyRefunded
                    ? PaymentSessionStatus::PartiallyRefunded
                    : PaymentSessionStatus::Refunded,
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

    private function refundAmount(PaymentSession $session, WebhookEvent $event, TamaraOrderService $orders): array
    {
        $explicit = TamaraMoney::extractAmount(data_get($event->payload, 'data.amount'))
            ?? TamaraMoney::extractAmount(data_get($event->payload, 'data.refunded_amount'));

        if ($explicit !== null && $explicit > 0) {
            return TamaraMoney::format($explicit, $session->currency);
        }

        $snapshot = $session->tamara_snapshot ?? [];
        foreach ([
            data_get($snapshot, 'captured_amount'),
            data_get($snapshot, 'authorized_amount'),
            data_get($snapshot, 'total_amount'),
        ] as $candidate) {
            $amount = TamaraMoney::extractAmount($candidate);
            if ($amount !== null && $amount > 0) {
                return TamaraMoney::format($amount, $session->currency);
            }
        }

        try {
            $details = $orders->details($session->store, $session->tamara_order_id);
            foreach ([
                data_get($details, 'captured_amount'),
                data_get($details, 'total_amount'),
            ] as $candidate) {
                $amount = TamaraMoney::extractAmount($candidate);
                if ($amount !== null && $amount > 0) {
                    return TamaraMoney::format($amount, $session->currency);
                }
            }
        } catch (Throwable) {
        }

        return TamaraMoney::format($session->amount, $session->currency);
    }

    private function syncTamaraRefund(PaymentSession $session, WebhookEvent $event): void
    {
        $payload = $event->payload ?? [];
        $status = $this->resolveRefundStatus($session, $payload);
        $session->update([
            'status' => $status,
            'tamara_snapshot' => array_merge($session->tamara_snapshot ?? [], $payload),
        ]);
        $event->update([
            'payment_session_id' => $session->id,
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    private function resolveRefundStatus(PaymentSession $session, array $payload): PaymentSessionStatus
    {
        if (TamaraOrderStatus::isFullyRefunded($payload)) {
            return PaymentSessionStatus::Refunded;
        }

        $refundedAmount = data_get($payload, 'data.refunded_amount.amount');
        if ($refundedAmount !== null) {
            return (float) $refundedAmount >= (float) $session->amount
                ? PaymentSessionStatus::Refunded
                : PaymentSessionStatus::PartiallyRefunded;
        }

        $status = TamaraOrderStatus::fromTamaraStatus((string) data_get($payload, 'data.status'));
        if ($status === PaymentSessionStatus::PartiallyRefunded) {
            return PaymentSessionStatus::PartiallyRefunded;
        }

        return PaymentSessionStatus::Refunded;
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
