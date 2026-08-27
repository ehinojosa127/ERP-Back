<?php

namespace App\Listeners;

use App\Events\ShipmentArrivedAtDestination;
use App\Services\Automation\N8nWebhookPayloadFactory;
use App\Services\Automation\OutboundWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendShipmentAtDestinationWebhook implements ShouldQueue
{
    public function __construct(
        private readonly N8nWebhookPayloadFactory $payloadFactory,
        private readonly OutboundWebhookDispatcher $dispatcher,
    ) {}

    public function handle(ShipmentArrivedAtDestination $event): void
    {
        $shipment = $event->shipment->fresh(['order.customer', 'order.payments', 'order.details'])
            ?? $event->shipment;

        $payload = $this->payloadFactory->shipmentAtDestination($shipment);

        $this->dispatcher->dispatch(
            event: 'SHIPMENT_AT_DESTINATION',
            idempotencyKey: 'shipment_at_destination:'.$shipment->id,
            payload: $payload,
            resourceType: 'shipment',
            resourceId: (int) $shipment->id,
        );
    }
}
