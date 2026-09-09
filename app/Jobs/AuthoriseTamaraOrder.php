<?php

namespace App\Jobs;

use App\Enums\PaymentSessionStatus;
use App\Models\WebhookEvent;
use App\Services\Tamara\TamaraOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class AuthoriseTamaraOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $eventId) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(TamaraOrderService $orders): void
    {
        $event = WebhookEvent::query()->with('paymentSession.store.tamaraConfig')->findOrFail($this->eventId);
        if ($event->processed_at) {
            return;
        }
        $session = $event->paymentSession;
        try {
            if (! $session->authorised_at) {
                $details = $orders->authorise($session->store, $session->tamara_order_id);
                $session->update([
                    'status' => PaymentSessionStatus::Authorised,
                    'authorised_at' => now(), 'tamara_snapshot' => $details,
                ]);
            }
            $event->update(['processing_status' => 'processed', 'processed_at' => now(), 'last_error' => null]);
        } catch (Throwable $e) {
            $event->update(['processing_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 2000), 'attempts' => $event->attempts + 1]);
            throw $e;
        }
    }
}
