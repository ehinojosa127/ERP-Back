<?php

namespace App\Services\Billing;

use App\Models\Order;

final class PaymentConceptSuggester
{
    /**
     * Si el pago deja balance en 0, sugiere la lista de productos del pedido.
     * Si es parcial, no fuerza un concepto (el usuario debe escribirlo).
     */
    public function suggest(Order $order, float $paymentAmount, float $remainingBeforePayment): ?string
    {
        $remainingAfter = max(0, round($remainingBeforePayment - $paymentAmount, 2));
        if ($remainingAfter > 0.00001) {
            return null;
        }

        $order->loadMissing(['details.product']);
        $parts = [];
        foreach ($order->details as $detail) {
            $name = trim((string) ($detail->product?->name ?: $detail->product_name));
            if ($name === '') {
                $name = 'Ítem';
            }
            $parts[] = sprintf('%s x%d', $name, (int) $detail->quantity);
        }

        if ($parts === []) {
            return sprintf('Pago completo de pedido %s', $order->order_number);
        }

        return implode(', ', $parts);
    }
}
