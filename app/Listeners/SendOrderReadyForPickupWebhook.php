<?php

namespace App\Listeners;

use App\Events\OrderReadyForPickup;
use App\Services\Automation\N8nWebhookPayloadFactory;
use App\Services\Automation\OutboundWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderReadyForPickupWebhook implements ShouldQueue
{
    public function __construct(
        private readonly N8nWebhookPayloadFactory $payloadFactory,
        private readonly OutboundWebhookDispatcher $dispatcher,
    ) {}

    public function handle(OrderReadyForPickup $event): void
    {
        $order = $event->order->fresh(['customer', 'shipment', 'payments', 'details'])
            ?? $event->order;

        $shipmentId = (int) ($order->shipment?->id ?? 0);

        $payload = $this->payloadFactory->orderReadyForPickup($order);

        $this->dispatcher->dispatch(
            event: 'ORDER_READY_FOR_PICKUP',
            idempotencyKey: 'order_ready_for_pickup:'.$order->id.':'.$shipmentId,
            payload: $payload,
            resourceType: 'order',
            resourceId: (int) $order->id,
        );
    }
}
