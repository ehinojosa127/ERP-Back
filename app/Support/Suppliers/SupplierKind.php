<?php

namespace App\Support\Suppliers;

/**
 * No hay columna `type` en la tabla: un proveedor es empresa cuando tiene
 * razón social o RUC, y persona en caso contrario. El frontend deriva lo mismo
 * en `supplier.view.ts`.
 */
final class SupplierKind
{
    public const PERSON = 'person';

    public const COMPANY = 'company';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::PERSON, self::COMPANY];
    }
}
