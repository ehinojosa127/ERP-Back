<?php

namespace App\Support\Orders;

/**
 * Estado operativo del pedido.
 * REGISTERED → PREPARING → SHIPPED → CLOSED
 * Cualquier estado previo a CLOSED puede pasar a CANCELLED.
 */
final class OrderStatus
{
    public const REGISTERED = 'REGISTERED';

    public const PREPARING = 'PREPARING';

    public const SHIPPED = 'SHIPPED';

    public const CLOSED = 'CLOSED';

    public const CANCELLED = 'CANCELLED';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [
            self::REGISTERED,
            self::PREPARING,
            self::SHIPPED,
            self::CLOSED,
            self::CANCELLED,
        ];
    }

    /** @return array<string, array<int, string>> */
    public static function allowedTransitions(): array
    {
        return [
            self::REGISTERED => [self::PREPARING, self::CANCELLED],
            self::PREPARING => [self::SHIPPED, self::CANCELLED],
            self::SHIPPED => [self::CLOSED, self::CANCELLED],
            self::CLOSED => [],
            self::CANCELLED => [],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedTransitions()[$from] ?? [], true);
    }

    public static function isEditable(string $status): bool
    {
        return in_array($status, [self::REGISTERED, self::PREPARING], true);
    }

    public static function isCancellable(string $status): bool
    {
        return self::canTransition($status, self::CANCELLED);
    }

    public static function isOpen(string $status): bool
    {
        return ! in_array($status, [self::CLOSED, self::CANCELLED], true);
    }
}
