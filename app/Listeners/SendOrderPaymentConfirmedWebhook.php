<?php

namespace App\Listeners;

use App\Events\OrderPaymentConfirmed;
use App\Services\Automation\N8nWebhookPayloadFactory;
use App\Services\Automation\OutboundWebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderPaymentConfirmedWebhook implements ShouldQueue
{
    public function __construct(
        private readonly N8nWebhookPayloadFactory $payloadFactory,
        private readonly OutboundWebhookDispatcher $dispatcher,
    ) {}

    public function handle(OrderPaymentConfirmed $event): void
    {
        $payment = $event->payment->fresh(['order.customer', 'order.shipment', 'order.payments', 'order.details'])
            ?? $event->payment;

        $payload = $this->payloadFactory->paymentConfirmed($payment);

        $this->dispatcher->dispatch(
            event: 'PAYMENT_CONFIRMED',
            idempotencyKey: 'payment_confirmed:'.$payment->id,
            payload: $payload,
            resourceType: 'order_payment',
            resourceId: (int) $payment->id,
        );
    }
}
