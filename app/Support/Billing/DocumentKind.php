<?php

namespace App\Support\Billing;

final class DocumentKind
{
    public const SALES_NOTE = 'sales_note';

    public const INVOICE = 'invoice';

    public const RECEIPT = 'receipt';

    public const CREDIT_NOTE = 'credit_note';

    public const DEBIT_NOTE = 'debit_note';

    public const SHIPPING_GUIDE = 'shipping_guide';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [
            self::SALES_NOTE,
            self::INVOICE,
            self::RECEIPT,
            self::CREDIT_NOTE,
            self::DEBIT_NOTE,
            self::SHIPPING_GUIDE,
        ];
    }

    /** @return array<int, string> */
    public static function issuableFromOrder(): array
    {
        return [self::SALES_NOTE, self::RECEIPT, self::INVOICE];
    }

    public static function isInternal(string $kind): bool
    {
        return $kind === self::SALES_NOTE;
    }

    public static function isElectronic(string $kind): bool
    {
        return ! self::isInternal($kind);
    }

    public static function sunatTypeCode(string $kind): string
    {
        return match ($kind) {
            self::INVOICE => '01',
            self::RECEIPT => '03',
            self::CREDIT_NOTE => '07',
            self::DEBIT_NOTE => '08',
            self::SHIPPING_GUIDE => '09',
            default => throw new \InvalidArgumentException('El tipo de documento no es electrónico.'),
        };
    }

    /**
     * Serie por defecto cuando BillingService aún no tiene series registradas.
     * BillingService crea el correlativo al emitir si la serie no existe.
     */
    public static function defaultSeries(string $kind): string
    {
        return match ($kind) {
            self::INVOICE => 'F001',
            self::RECEIPT => 'B001',
            self::CREDIT_NOTE => 'FC01',
            self::DEBIT_NOTE => 'FD01',
            self::SHIPPING_GUIDE => 'T001',
            default => throw new \InvalidArgumentException('El tipo de documento no es electrónico.'),
        };
    }

    public static function billingPath(string $kind): string
    {
        return match ($kind) {
            self::INVOICE => '/api/v1/invoices',
            self::RECEIPT => '/api/v1/receipts',
            self::CREDIT_NOTE => '/api/v1/credit-notes',
            self::DEBIT_NOTE => '/api/v1/debit-notes',
            self::SHIPPING_GUIDE => '/api/v1/shipping-guides',
            default => throw new \InvalidArgumentException('El tipo de documento no es electrónico.'),
        };
    }
}
