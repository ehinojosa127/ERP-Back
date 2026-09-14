<?php

namespace App\Services\Automation;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shipment;
use App\Support\Inventory\PaymentMethod;

final class N8nWebhookPayloadFactory
{
    /**
     * @return array<string, mixed>
     */
    public function orderShipped(Order $order): array
    {
        $order->loadMissing(['customer', 'shipment', 'payments', 'details']);
        $shipment = $order->shipment;
        $customer = $order->customer;
        $balance = (float) $order->remaining_amount;

        return [
            'event' => 'ORDER_SHIPPED',
            'customer' => $this->customerPayload($customer?->name, $customer?->lastname, $customer?->phone_number),
            'order' => [
                'orderNumber' => $order->order_number,
                'status' => $order->status,
                'balance' => $balance,
                'total' => (float) $order->total_amount,
            ],
            'shipment' => $this->shipmentPayload($shipment, $balance),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function shipmentAtDestination(Shipment $shipment): array
    {
        $shipment->loadMissing(['order.customer', 'order.payments', 'order.details']);
        $order = $shipment->order;
        $customer = $order?->customer;
        $balance = $order !== null ? (float) $order->remaining_amount : 0.0;

        return [
            'event' => 'SHIPMENT_AT_DESTINATION',
            'customer' => $this->customerPayload($customer?->name, $customer?->lastname, $customer?->phone_number),
            'order' => [
                'orderNumber' => $order?->order_number,
                'status' => $order?->status,
                'balance' => $balance,
            ],
            'shipment' => $this->shipmentPayload($shipment, $balance),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function orderReadyForPickup(Order $order): array
    {
        $order->loadMissing(['customer', 'shipment', 'payments', 'details']);
        $shipment = $order->shipment;
        $customer = $order->customer;
        $balance = (float) $order->remaining_amount;

        return [
            'event' => 'ORDER_READY_FOR_PICKUP',
            'customer' => $this->customerPayload($customer?->name, $customer?->lastname, $customer?->phone_number),
            'order' => [
                'orderNumber' => $order->order_number,
                'status' => $order->status,
                'balance' => $balance,
            ],
            'shipment' => $this->shipmentPayload($shipment, $balance),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentConfirmed(OrderPayment $payment): array
    {
        $payment->loadMissing(['order.customer', 'order.shipment', 'order.payments', 'order.details']);
        $order = $payment->order;
        $customer = $order?->customer;
        $balance = $order !== null ? (float) $order->remaining_amount : 0.0;
        $method = (int) $payment->payment_method;

        return [
            'event' => 'PAYMENT_CONFIRMED',
            'customer' => $this->customerPayload($customer?->name, $customer?->lastname, $customer?->phone_number),
            'order' => [
                'orderNumber' => $order?->order_number,
                'status' => $order?->status,
                'balance' => $balance,
                'total' => $order !== null ? (float) $order->total_amount : null,
            ],
            'payment' => [
                'id' => (int) $payment->id,
                'amount' => (float) $payment->amount,
                'concept' => $payment->concept,
                'paymentMethod' => $method,
                'paymentMethodLabel' => PaymentMethod::label($method),
                'paymentDate' => optional($payment->payment_date)?->toDateString(),
                'operationNumber' => $payment->operation_number,
            ],
            'shipment' => $order?->shipment !== null
                ? $this->shipmentPayload($order->shipment, $balance)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentPayload(?Shipment $shipment, float $balance): array
    {
        if ($shipment === null) {
            return [
                'agency' => null,
                'agencyDestination' => null,
                'destination' => null,
                'status' => null,
                'shipmentDate' => null,
                'deliveryDate' => null,
                'shippingKey' => null,
            ];
        }

        return [
            'agency' => $shipment->agency,
            'agencyDestination' => $shipment->agency_destination,
            'destination' => $shipment->destination,
            'status' => $shipment->status,
            'shipmentDate' => optional($shipment->shipment_date)?->toDateString(),
            'deliveryDate' => optional($shipment->delivery_date)?->toDateString(),
            'shippingKey' => $balance <= 0.00001 ? $shipment->shipping_key : null,
        ];
    }

    /**
     * @return array{name: string|null, phone: string|null}
     */
    private function customerPayload(?string $name, ?string $lastname, ?string $phone): array
    {
        $fullName = trim(implode(' ', array_filter([(string) $name, (string) $lastname])));

        return [
            'name' => $fullName !== '' ? $fullName : null,
            'phone' => $phone,
        ];
    }
}
