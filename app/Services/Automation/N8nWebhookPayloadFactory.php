<?php

namespace App\Services\Automation;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderPayment;
use App\Models\Shipment;
use App\Support\Inventory\PaymentMethod;
use Illuminate\Support\Collection;

final class N8nWebhookPayloadFactory
{
    public function __construct(
        private readonly ShipmentReceiptSignedUrl $receiptUrls,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function orderShipped(Order $order): array
    {
        $order->loadMissing(['customer', 'shipment', 'payments', 'details.product']);
        $shipment = $order->shipment;
        $customer = $order->customer;
        $balance = (float) $order->remaining_amount;

        return [
            'event' => 'ORDER_SHIPPED',
            'customer' => $this->customerPayload($customer?->name, $customer?->lastname, $customer?->phone_number),
            'order' => $this->orderPayload($order, $balance, includeTotal: true),
            'shipment' => $this->shipmentPayload($shipment, $balance),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function shipmentAtDestination(Shipment $shipment): array
    {
        $shipment->loadMissing(['order.customer', 'order.payments', 'order.details.product']);
        $order = $shipment->order;
        $customer = $order?->customer;
        $balance = $order !== null ? (float) $order->remaining_amount : 0.0;

        return [
            'event' => 'SHIPMENT_AT_DESTINATION',
            'customer' => $this->customerPayload($customer?->name, $customer?->lastname, $customer?->phone_number),
            'order' => $order !== null
                ? $this->orderPayload($order, $balance)
                : [
                    'orderNumber' => null,
                    'status' => null,
                    'balance' => $balance,
                    'items' => [],
                    'itemsSummary' => '',
                ],
            'shipment' => $this->shipmentPayload($shipment, $balance),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function orderReadyForPickup(Order $order): array
    {
        $order->loadMissing(['customer', 'shipment', 'payments', 'details.product']);
        $shipment = $order->shipment;
        $customer = $order->customer;
        $balance = (float) $order->remaining_amount;

        return [
            'event' => 'ORDER_READY_FOR_PICKUP',
            'customer' => $this->customerPayload($customer?->name, $customer?->lastname, $customer?->phone_number),
            'order' => $this->orderPayload($order, $balance),
            'shipment' => $this->shipmentPayload($shipment, $balance),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentConfirmed(OrderPayment $payment): array
    {
        $payment->loadMissing(['order.customer', 'order.shipment', 'order.payments', 'order.details.product']);
        $order = $payment->order;
        $customer = $order?->customer;
        $balance = $order !== null ? (float) $order->remaining_amount : 0.0;
        $method = (int) $payment->payment_method;

        return [
            'event' => 'PAYMENT_CONFIRMED',
            'customer' => $this->customerPayload($customer?->name, $customer?->lastname, $customer?->phone_number),
            'order' => $order !== null
                ? $this->orderPayload($order, $balance, includeTotal: true)
                : [
                    'orderNumber' => null,
                    'status' => null,
                    'balance' => $balance,
                    'total' => null,
                    'items' => [],
                    'itemsSummary' => '',
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
    private function orderPayload(Order $order, float $balance, bool $includeTotal = false): array
    {
        $items = $this->itemsPayload($order->details);

        $payload = [
            'orderNumber' => $order->order_number,
            'status' => $order->status,
            'balance' => $balance,
            'items' => $items,
            'itemsSummary' => $this->itemsSummary($items),
        ];

        if ($includeTotal) {
            $payload['total'] = (float) $order->total_amount;
        }

        return $payload;
    }

    /**
     * @param  Collection<int, OrderDetail>|iterable<OrderDetail>  $details
     * @return list<array{name: string, quantity: int, unitPrice: float, subtotal: float}>
     */
    private function itemsPayload(iterable $details): array
    {
        $items = [];

        foreach ($details as $detail) {
            $name = trim((string) ($detail->display_name ?: $detail->product_name));
            $quantity = (int) $detail->quantity;
            $unitPrice = (float) $detail->unit_price;

            $items[] = [
                'name' => $name !== '' ? $name : 'Producto',
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'subtotal' => round($quantity * $unitPrice, 2),
            ];
        }

        return $items;
    }

    /**
     * @param  list<array{name: string, quantity: int, unitPrice: float, subtotal: float}>  $items
     */
    private function itemsSummary(array $items): string
    {
        return implode(', ', array_map(
            static fn (array $item): string => $item['quantity'].'x '.$item['name'],
            $items,
        ));
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
                'receipt' => null,
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
            'receipt' => $this->receiptUrls->make($shipment),
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
