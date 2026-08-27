<?php

namespace App\Services\Billing;

use App\Exceptions\Billing\BillingValidationException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Support\Billing\DocumentKind;
use App\Support\Billing\PaymentCondition;

final class BillingDocumentMapper
{
    /**
     * @return array<string, mixed>
     */
    public function fromPayment(
        Order $order,
        OrderPayment $payment,
        string $kind,
        ?string $series = null,
        string $paymentCondition = PaymentCondition::CASH,
    ): array {
        $order->loadMissing(['customer', 'details.product']);
        $customer = $order->customer;
        if ($customer === null) {
            throw new BillingValidationException('El pedido no tiene cliente asociado.');
        }

        $recipient = $this->mapRecipient($customer, $kind);
        $concept = trim((string) ($payment->concept ?: sprintf('Pago pedido %s', $order->order_number)));
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            throw new BillingValidationException('El monto del pago debe ser mayor a cero para emitir.');
        }

        $unitValue = round($amount / 1.18, 6);
        $items = [[
            'code' => 'PAY-'.$payment->id,
            'description' => $concept,
            'quantity' => 1,
            'unitCode' => 'NIU',
            'unitValue' => $unitValue,
            'taxInclusiveUnitPrice' => $amount,
            'discount' => 0,
            'taxAffectation' => '10',
        ]];

        return [
            'series' => $series,
            'recipient' => $recipient,
            'currency' => 'PEN',
            'operationType' => '0101',
            'paymentForm' => PaymentCondition::normalize($paymentCondition),
            'observation' => $order->observations,
            'externalSystem' => (string) config('services.billing.external_system'),
            'externalEntity' => 'order_payment',
            'externalId' => (string) $payment->id,
            'externalReference' => sprintf('%s/payment-%d', $order->order_number, $payment->id),
            'requestedBy' => 'erp',
            'issueDate' => optional($payment->payment_date)?->toDateString()
                ?? optional($order->order_date)?->toDateString(),
            'items' => $items,
            'snapshot' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'order_payment_id' => $payment->id,
                'payment_amount' => $amount,
                'concept' => $concept,
                'customer_id' => $customer->id,
                'recipient' => $recipient,
                'items' => $items,
            ],
        ];
    }

    /**
     * Compatibilidad: emite por el total del pedido (flujo legado).
     *
     * @return array<string, mixed>
     */
    public function fromOrder(Order $order, string $kind, ?string $series = null): array
    {
        $order->loadMissing(['customer', 'details.product']);
        $customer = $order->customer;
        if ($customer === null) {
            throw new BillingValidationException('El pedido no tiene cliente asociado.');
        }

        $recipient = $this->mapRecipient($customer, $kind);
        $items = $this->mapItems($order);

        return [
            'series' => $series,
            'recipient' => $recipient,
            'currency' => 'PEN',
            'operationType' => '0101',
            'paymentForm' => PaymentCondition::CASH,
            'observation' => $order->observations,
            'externalSystem' => (string) config('services.billing.external_system'),
            'externalEntity' => 'order',
            'externalId' => (string) $order->id,
            'externalReference' => $order->order_number,
            'requestedBy' => 'erp',
            'issueDate' => optional($order->order_date)?->toDateString(),
            'items' => $items,
            'snapshot' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'order_date' => optional($order->order_date)?->toDateString(),
                'customer_id' => $customer->id,
                'recipient' => $recipient,
                'items' => $items,
                'total' => $order->total_amount,
            ],
        ];
    }

    /**
     * @return array{identityType: string, identityNumber: string, name: string, address: string|null}
     */
    public function mapRecipient(Customer $customer, string $kind): array
    {
        if ($kind === DocumentKind::INVOICE) {
            $ruc = preg_replace('/\D+/', '', (string) $customer->ruc);
            if (strlen((string) $ruc) !== 11) {
                throw new BillingValidationException(
                    'La factura requiere RUC del cliente. Completa los datos tributarios antes de emitir.',
                    ['ruc' => ['La factura requiere un RUC de 11 dígitos.']],
                );
            }

            $name = trim((string) ($customer->legal_name ?: trim($customer->name.' '.$customer->lastname)));

            return [
                'identityType' => '6',
                'identityNumber' => $ruc,
                'name' => $name,
                'address' => $customer->address,
            ];
        }

        $dni = preg_replace('/\D+/', '', (string) $customer->dni);
        if (strlen((string) $dni) !== 8) {
            throw new BillingValidationException(
                'La boleta requiere DNI del cliente.',
                ['dni' => ['La boleta requiere un DNI de 8 dígitos.']],
            );
        }

        return [
            'identityType' => '1',
            'identityNumber' => $dni,
            'name' => trim($customer->name.' '.$customer->lastname),
            'address' => $customer->address,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function mapItems(Order $order): array
    {
        $items = [];
        foreach ($order->details as $detail) {
            $unitPrice = round((float) $detail->unit_price, 2);
            $unitValue = round($unitPrice / 1.18, 6);
            $items[] = [
                'code' => $detail->product_id ? (string) $detail->product_id : null,
                'description' => $this->itemDescription($detail),
                'quantity' => (int) $detail->quantity,
                'unitCode' => 'NIU',
                'unitValue' => $unitValue,
                'taxInclusiveUnitPrice' => $unitPrice,
                'discount' => 0,
                'taxAffectation' => '10',
            ];
        }

        if ($items === []) {
            throw new BillingValidationException('El pedido no tiene ítems para facturar.');
        }

        return $items;
    }

    public function itemDescription(\App\Models\OrderDetail $detail): string
    {
        $name = trim((string) $detail->display_name);
        if ($name !== '') {
            return $name;
        }

        return 'Ítem';
    }
}
