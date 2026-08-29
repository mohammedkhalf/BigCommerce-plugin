<?php

namespace App\Http\Controllers;

use App\Enums\PaymentSessionStatus;
use App\Jobs\AuthoriseTamaraOrder;
use App\Models\PaymentSession;
use App\Models\WebhookEvent;
use App\Services\Tamara\TamaraNotificationVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class TamaraWebhookController
{
    public function __construct(private TamaraNotificationVerifier $verifier) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->validate(['event_type' => ['nullable', 'string', 'max:100']]) + $request->all();
        $reference = data_get($payload, 'order_reference_id') ?? data_get($payload, 'data.order_reference_id');
        $tamaraId = data_get($payload, 'order_id') ?? data_get($payload, 'data.order_id');
        $session = PaymentSession::query()
            ->when($reference, fn ($q) => $q->where('bc_order_id', $reference))
            ->when(! $reference && $tamaraId, fn ($q) => $q->where('tamara_order_id', $tamaraId))
            ->firstOrFail();
        $this->verifier->verify($session->store, $request->header('Authorization'));
        $externalId = (string) (data_get($payload, 'event_id') ?? data_get($payload, 'data.event_id') ?? hash('sha256', $request->getContent()));
        $event = WebhookEvent::query()->firstOrCreate(
            ['source' => 'tamara', 'external_id' => $externalId],
            [
                'store_id' => $session->store_id, 'payment_session_id' => $session->id,
                'event_type' => $payload['event_type'] ?? 'order_approved',
                'payload' => $payload, 'received_at' => now(),
            ],
        );
        if ($event->wasRecentlyCreated) {
            match ($event->event_type) {
                'order_approved' => AuthoriseTamaraOrder::dispatch($event->id),
                'order_authorised', 'order_authorized' => $this->syncStatus($session, $event, PaymentSessionStatus::Authorised),
                'order_declined' => $this->syncStatus($session, $event, PaymentSessionStatus::Declined, failed: true),
                'order_expired' => $this->syncStatus($session, $event, PaymentSessionStatus::Expired, failed: true),
                'order_canceled', 'order_cancelled' => $this->syncStatus($session, $event, PaymentSessionStatus::Cancelled),
                'order_captured' => $this->syncStatus(
                    $session,
                    $event,
                    str_contains((string) data_get($payload, 'data.status'), 'partial')
                        ? PaymentSessionStatus::PartiallyCaptured
                        : PaymentSessionStatus::Captured,
                ),
                'order_refunded' => $this->syncStatus(
                    $session,
                    $event,
                    str_contains((string) data_get($payload, 'data.status'), 'partial')
                        ? PaymentSessionStatus::PartiallyRefunded
                        : PaymentSessionStatus::Refunded,
                ),
                default => $event->update(['processing_status' => 'ignored', 'processed_at' => now()]),
            };
        }

        return response()->noContent();
    }

    private function syncStatus(
        PaymentSession $session,
        WebhookEvent $event,
        PaymentSessionStatus $status,
        bool $failed = false,
    ): void {
        $session->update([
            'status' => $status,
            'failed_at' => $failed ? now() : $session->failed_at,
            'cancelled_at' => $status === PaymentSessionStatus::Cancelled ? now() : $session->cancelled_at,
            'captured_at' => $status === PaymentSessionStatus::Captured ? now() : $session->captured_at,
        ]);

        $event->update([
            'processing_status' => 'processed',
            'processed_at' => now(),
        ]);
    }
}
