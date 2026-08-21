<?php

namespace App\Support\Orders;

/**
 * Estado logístico del envío (independiente del estado del pedido).
 * SHIPPED → IN_TRANSIT → AT_DESTINATION → DELIVERED
 */
final class ShipmentStatus
{
    public const SHIPPED = 'SHIPPED';

    public const IN_TRANSIT = 'IN_TRANSIT';

    public const AT_DESTINATION = 'AT_DESTINATION';

    public const DELIVERED = 'DELIVERED';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [
            self::SHIPPED,
            self::IN_TRANSIT,
            self::AT_DESTINATION,
            self::DELIVERED,
        ];
    }

    /** @return array<string, array<int, string>> */
    public static function allowedTransitions(): array
    {
        return [
            self::SHIPPED => [self::IN_TRANSIT],
            self::IN_TRANSIT => [self::AT_DESTINATION],
            self::AT_DESTINATION => [self::DELIVERED],
            self::DELIVERED => [],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedTransitions()[$from] ?? [], true);
    }
}
