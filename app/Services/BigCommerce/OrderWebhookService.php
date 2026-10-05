<?php

namespace App\Services\BigCommerce;

use App\Models\Store;
use App\Models\WebhookEvent;
use Throwable;

final readonly class OrderWebhookService
{
    public function __construct(private OrderService $orders) {}

    public function shouldCapture(WebhookEvent $event, Store $store): bool
    {
        if ($event->event_type === 'store/shipment/created') {
            return true;
        }

        if (! $this->isOrderStatusEvent($event->event_type)) {
            return false;
        }

        return $this->resolveStatusId($store, $event->payload ?? [])
            === (int) config('bigcommerce.shipped_status_id', 2);
    }

    public function shouldRefund(WebhookEvent $event, Store $store): bool
    {
        if ($event->event_type === 'store/order/refund/created') {
            return true;
        }

        if (! $this->isOrderStatusEvent($event->event_type)) {
            return false;
        }

        $statusId = $this->resolveStatusId($store, $event->payload ?? []);

        return in_array($statusId, [
            (int) config('bigcommerce.refunded_status_id', 4),
            (int) config('bigcommerce.partially_refunded_status_id', 14),
        ], true);
    }

    public function shouldCancel(WebhookEvent $event, Store $store): bool
    {
        if (! $this->isOrderStatusEvent($event->event_type)) {
            return false;
        }

        return $this->resolveStatusId($store, $event->payload ?? [])
            === (int) config('bigcommerce.cancelled_status_id', 5);
    }

    private function isOrderStatusEvent(string $eventType): bool
    {
        return in_array($eventType, ['store/order/statusUpdated', 'store/order/updated'], true);
    }

    public function resolveStatusId(Store $store, array $payload): ?int
    {
        $fromPayload = data_get($payload, 'data.status.new_status_id')
            ?? data_get($payload, 'data.new_status_id');

        if ($fromPayload !== null) {
            return (int) $fromPayload;
        }

        $orderId = data_get($payload, 'data.id') ?? data_get($payload, 'data.order_id');
        if ($orderId === null) {
            return null;
        }

        try {
            $order = $this->orders->get($store, $orderId);

            return isset($order['status_id']) ? (int) $order['status_id'] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
