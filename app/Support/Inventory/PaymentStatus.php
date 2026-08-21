<?php

namespace App\Support\Inventory;

/**
 * Estado de pago derivado: SUM(payments) vs total_amount.
 * No se persiste en la tabla `purchases`.
 */
final class PaymentStatus
{
    public const UNPAID = 'UNPAID';

    public const PARTIALLY_PAID = 'PARTIALLY_PAID';

    public const PAID = 'PAID';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::UNPAID, self::PARTIALLY_PAID, self::PAID];
    }

    public static function fromAmounts(float $totalAmount, float $paidAmount): string
    {
        if ($paidAmount <= 0) {
            return self::UNPAID;
        }

        if ($paidAmount + 0.00001 < $totalAmount) {
            return self::PARTIALLY_PAID;
        }

        return self::PAID;
    }
}
