<?php

namespace App\Services\Automation;

use App\Models\Order;
use App\Models\Shipment;

final class N8nWebhookPayloadFactory
{
    /**
     * @return array<string, mixed>
     */
    public function shipmentAtDestination(Shipment $shipment): array
    {
        $shipment->loadMissing(['order.customer', 'order.payments', 'order.details']);
        $order = $shipment->order;
        $customer = $order?->customer;

        $balance = $order !== null ? (float) $order->remaining_amount : 0.0;
        $includeShippingKey = $balance <= 0.00001;

        $shipmentPayload = [
            'agency' => $shipment->agency,
            'agencyDestination' => $shipment->agency_destination,
            'destination' => $shipment->destination,
            'status' => $shipment->status,
            'shipmentDate' => optional($shipment->shipment_date)?->toDateString(),
            'deliveryDate' => optional($shipment->delivery_date)?->toDateString(),
        ];

        if ($includeShippingKey) {
            $shipmentPayload['shippingKey'] = $shipment->shipping_key;
        } else {
            $shipmentPayload['shippingKey'] = null;
        }

        return [
            'event' => 'SHIPMENT_AT_DESTINATION',
            'customer' => $this->customerPayload($customer?->name, $customer?->lastname, $customer?->phone_number),
            'order' => [
                'orderNumber' => $order?->order_number,
                'balance' => $balance,
            ],
            'shipment' => $shipmentPayload,
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

        $shipmentPayload = [
            'agency' => $shipment?->agency,
            'agencyDestination' => $shipment?->agency_destination,
            'destination' => $shipment?->destination,
            'status' => $shipment?->status,
            'shippingKey' => $balance <= 0.00001 ? $shipment?->shipping_key : null,
        ];

        return [
            'event' => 'ORDER_READY_FOR_PICKUP',
            'customer' => $this->customerPayload($customer?->name, $customer?->lastname, $customer?->phone_number),
            'order' => [
                'orderNumber' => $order->order_number,
                'balance' => $balance,
            ],
            'shipment' => $shipmentPayload,
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
