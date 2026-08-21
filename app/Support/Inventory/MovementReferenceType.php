<?php

namespace App\Support\Inventory;

/**
 * Referencia polimórfica lógica de un movimiento.
 * PURCHASE: entrada al pasar una compra a almacén.
 * ORDER: salida al enviar un pedido con detalles STOCK.
 */
final class MovementReferenceType
{
    public const PURCHASE = 'PURCHASE';

    public const ORDER = 'ORDER';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::PURCHASE, self::ORDER];
    }
}
