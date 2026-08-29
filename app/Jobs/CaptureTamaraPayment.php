<?php

namespace App\Jobs;

use App\Models\PaymentSession;
use App\Models\WebhookEvent;
use App\Services\Tamara\TamaraOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        $event = WebhookEvent::query()->findOrFail($this->eventId);
        if ($event->processed_at) {
            return;
        }
        $orderId = data_get($event->payload, 'data.order_id');
        $session = PaymentSession::query()->where('store_id', $event->store_id)->where('bc_order_id', $orderId)->firstOrFail();
        if (! $session->captured_at) {
            $orders->capture($session->store, $session->tamara_order_id, [
                'total_amount' => ['amount' => $session->amount, 'currency' => $session->currency],
                'shipping_info' => data_get($event->payload, 'data', []),
            ]);
            $session->update(['captured_at' => now()]);
        }
        $event->update(['payment_session_id' => $session->id, 'processing_status' => 'processed', 'processed_at' => now()]);
    }
}
