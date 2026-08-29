<?php

namespace App\Jobs;

use App\Enums\PaymentSessionStatus;
use App\Models\PaymentSession;
use App\Models\WebhookEvent;
use App\Services\BigCommerce\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class MarkBigCommerceOrderPaid implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $sessionId, public readonly ?string $eventId = null) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(OrderService $orders): void
    {
        $session = PaymentSession::query()->with('store')->findOrFail($this->sessionId);
        if ($session->status !== PaymentSessionStatus::Completed) {
            $orders->updateStatus($session->store, $session->bc_order_id, (int) config('bigcommerce.paid_status_id', 11));
            $session->update(['status' => PaymentSessionStatus::Completed]);
        }
        if ($this->eventId) {
            WebhookEvent::query()->whereKey($this->eventId)->update(['processing_status' => 'processed', 'processed_at' => now(), 'last_error' => null]);
        }
    }
}
