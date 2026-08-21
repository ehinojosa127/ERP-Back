<?php

namespace App\Support\Inventory;

/**
 * Tipo de movimiento de inventario. El stock se deriva como
 * SUM(entradas) - SUM(salidas); nunca se escribe en `products`.
 */
final class MovementType
{
    public const IN = 1;

    public const OUT = 0;

    /** @return array<int, int> */
    public static function values(): array
    {
        return [self::IN, self::OUT];
    }
}
