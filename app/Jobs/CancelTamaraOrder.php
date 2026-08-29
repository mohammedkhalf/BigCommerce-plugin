<?php

namespace App\Jobs;

use App\Enums\PaymentSessionStatus;
use App\Models\PaymentSession;
use App\Services\Tamara\TamaraOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CancelTamaraOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $sessionId) {}

    public function handle(TamaraOrderService $orders): void
    {
        $session = PaymentSession::query()->with('store')->findOrFail($this->sessionId);
        if ($session->cancelled_at) {
            return;
        }
        $orders->cancel($session->store, $session->tamara_order_id, [
            'total_amount' => ['amount' => $session->amount, 'currency' => $session->currency],
        ]);
        $session->update(['status' => PaymentSessionStatus::Cancelled, 'cancelled_at' => now()]);
    }
}
