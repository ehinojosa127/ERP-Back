<?php

namespace App\Support\Inventory;

/**
 * Medio de pago persistido como entero en order_payments / purchase_payments.
 */
final class PaymentMethod
{
    public const YAPE = 1;

    public const PLIN = 2;

    public const CASH = 3;

    public const BANK_TRANSFER = 4;

    public const CARD = 5;

    public const OTHER = 6;

    /** @return array<int, int> */
    public static function values(): array
    {
        return [
            self::YAPE,
            self::PLIN,
            self::CASH,
            self::BANK_TRANSFER,
            self::CARD,
            self::OTHER,
        ];
    }

    public static function label(int $value): string
    {
        return match ($value) {
            self::YAPE => 'Yape',
            self::PLIN => 'Plin',
            self::CASH => 'Efectivo',
            self::BANK_TRANSFER => 'Transferencia bancaria',
            self::CARD => 'Tarjeta de crédito/débito',
            self::OTHER => 'Otros',
            default => 'Desconocido',
        };
    }
}
