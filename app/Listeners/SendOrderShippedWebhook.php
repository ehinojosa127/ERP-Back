<?php

namespace App\Listeners;

use App\Events\OrderShipped;
use App\Services\Automation\N8nWebhookPayloadFactory;
use App\Services\Automation\OutboundWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderShippedWebhook implements ShouldQueue
{
    public function __construct(
        private readonly N8nWebhookPayloadFactory $payloadFactory,
        private readonly OutboundWebhookDispatcher $dispatcher,
    ) {}

    public function handle(OrderShipped $event): void
    {
        $order = $event->order->fresh(['customer', 'shipment', 'payments', 'details'])
            ?? $event->order;

        $payload = $this->payloadFactory->orderShipped($order);

        $this->dispatcher->dispatch(
            event: 'ORDER_SHIPPED',
            idempotencyKey: 'order_shipped:'.$order->id,
            payload: $payload,
            resourceType: 'order',
            resourceId: (int) $order->id,
        );
    }
}
