<?php

namespace App\Http\Controllers;

use App\Jobs\CaptureTamaraPayment;
use App\Jobs\RefundTamaraPayment;
use App\Models\Store;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BigCommerceWebhookController
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();
        $producer = (string) ($payload['producer'] ?? '');
        abort_unless(preg_match('#^stores/([a-z0-9]+)$#i', $producer, $match), 400);
        $store = Store::query()->where('store_hash', $match[1])->whereNull('uninstalled_at')->firstOrFail();
        $this->verifySignature($request, $store);
        $externalId = (string) ($request->header('X-BC-Webhook-Id') ?? $payload['hash'] ?? hash('sha256', $request->getContent()));
        $event = WebhookEvent::query()->firstOrCreate(
            ['source' => 'bigcommerce', 'external_id' => $externalId],
            [
                'store_id' => $store->id, 'event_type' => $payload['scope'] ?? 'unknown',
                'payload' => $payload, 'received_at' => now(),
            ],
        );
        if ($event->wasRecentlyCreated) {
            $this->dispatchJob($event);
        }

        return response()->noContent();
    }

    private function dispatchJob(WebhookEvent $event): void
    {
        if ($this->shouldRefund($event)) {
            RefundTamaraPayment::dispatch($event->id);

            return;
        }

        if ($this->shouldCapture($event)) {
            CaptureTamaraPayment::dispatch($event->id);

            return;
        }

        $event->update(['processing_status' => 'ignored', 'processed_at' => now()]);
    }

    private function shouldRefund(WebhookEvent $event): bool
    {
        if ($event->event_type === 'store/order/refund/created') {
            return true;
        }

        if ($event->event_type !== 'store/order/statusUpdated') {
            return false;
        }

        $newStatusId = (int) data_get($event->payload, 'data.status.new_status_id');

        return in_array($newStatusId, [
            (int) config('bigcommerce.refunded_status_id', 4),
            (int) config('bigcommerce.partially_refunded_status_id', 14),
        ], true);
    }

    private function shouldCapture(WebhookEvent $event): bool
    {
        if ($event->event_type === 'store/shipment/created') {
            return true;
        }

        if ($event->event_type !== 'store/order/statusUpdated') {
            return false;
        }

        $newStatusId = (int) data_get($event->payload, 'data.status.new_status_id');

        return $newStatusId === (int) config('bigcommerce.shipped_status_id', 2);
    }

    private function verifySignature(Request $request, Store $store): void
    {
        $configuredToken = (string) $request->header('X-Tamara-Webhook-Token');
        $expectedToken = hash_hmac(
            'sha256',
            $store->store_hash,
            (string) config('bigcommerce.client_secret'),
        );
        abort_unless($configuredToken !== '' && hash_equals($expectedToken, $configuredToken), 401);

        $signature = $request->header('X-BC-Signature');
        if ($signature) {
            $expected = base64_encode(hash_hmac(
                'sha256',
                $request->getContent(),
                (string) config('bigcommerce.client_secret'),
                true,
            ));
            abort_unless(hash_equals($expected, $signature), 401);
        }
    }
}
