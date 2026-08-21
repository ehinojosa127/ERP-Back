<?php

namespace App\Support\Orders;

/**
 * Origen del detalle del pedido.
 * STOCK: sale del inventario (genera movimiento OUT al enviar).
 * SUPPLIER: se obtiene del proveedor (sin movimiento de stock).
 */
final class FulfillmentType
{
    public const STOCK = 'STOCK';

    public const SUPPLIER = 'SUPPLIER';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::STOCK, self::SUPPLIER];
    }
}
