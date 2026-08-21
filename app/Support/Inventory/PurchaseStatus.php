<?php

namespace App\Support\Inventory;

/**
 * Flujo físico de una orden de compra.
 * CREATED → SHIPPED → IN_WAREHOUSE (única transición válida hacia almacén).
 */
final class PurchaseStatus
{
    public const CREATED = 'CREATED';

    public const SHIPPED = 'SHIPPED';

    public const IN_WAREHOUSE = 'IN_WAREHOUSE';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::CREATED, self::SHIPPED, self::IN_WAREHOUSE];
    }

    /** Transiciones permitidas: from => [to, ...] */
    public static function allowedTransitions(): array
    {
        return [
            self::CREATED => [self::SHIPPED],
            self::SHIPPED => [self::IN_WAREHOUSE],
            self::IN_WAREHOUSE => [],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedTransitions()[$from] ?? [], true);
    }
}
