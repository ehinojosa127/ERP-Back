<?php

namespace App\Support\Billing;

/**
 * Condición de pago tributaria enviada a BillingService (paymentForm).
 * Independiente del medio de pago (Yape, efectivo, etc.).
 */
final class PaymentCondition
{
    public const CASH = 'cash';

    public const CREDIT = 'credit';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::CASH, self::CREDIT];
    }

    public static function normalize(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, self::values(), true) ? $normalized : self::CASH;
    }
}
