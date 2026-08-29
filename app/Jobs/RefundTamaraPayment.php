<?php

namespace App\Jobs;

use App\Models\PaymentSession;
use App\Models\WebhookEvent;
use App\Services\Tamara\TamaraOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        $event = WebhookEvent::query()->findOrFail($this->eventId);
        if ($event->processed_at) {
            return;
        }
        $orderId = data_get($event->payload, 'data.order_id');
        $session = PaymentSession::query()->where('store_id', $event->store_id)->where('bc_order_id', $orderId)->firstOrFail();
        $amount = (float) (data_get($event->payload, 'data.amount') ?? $session->amount);
        $orders->refund($session->store, $session->tamara_order_id, [
            'total_amount' => ['amount' => $amount, 'currency' => $session->currency],
            'comment' => 'BigCommerce refund',
        ]);
        $event->update(['payment_session_id' => $session->id, 'processing_status' => 'processed', 'processed_at' => now()]);
    }
}
