<?php

namespace App\Support\Billing;

/**
 * Plantillas PDF del sistema. No son editables por el usuario.
 */
final class BillingPdfTemplate
{
    public const DEFAULT = 'DEFAULT';

    public const CUSTOM = 'CUSTOM';

    public const SETTING_KEY = 'billing.pdf_template';

    /** @return array<int, string> */
    public static function codes(): array
    {
        return [
            self::DEFAULT,
            self::CUSTOM,
        ];
    }

    public static function defaultCode(): string
    {
        return self::DEFAULT;
    }

    public static function isValid(string $code): bool
    {
        return in_array(self::normalize($code), self::codes(), true);
    }

    public static function normalize(string $code): string
    {
        $value = strtoupper(str_replace('-', '_', trim($code)));

        return match ($value) {
            'COMPANY', 'CUSTOM' => self::CUSTOM,
            'SUNAT_DEFAULT', 'SUNAT', 'DEFAULT' => self::DEFAULT,
            default => $value,
        };
    }

    public static function label(string $code): string
    {
        return match (self::normalize($code)) {
            self::CUSTOM => 'Con logo de la empresa',
            self::DEFAULT => 'Formato estándar',
            default => 'Plantilla desconocida',
        };
    }

    /**
     * @return array<int, array{code: string, name: string, selected: bool}>
     */
    public static function catalog(string $selectedCode): array
    {
        $selected = self::normalize($selectedCode);

        return array_map(
            fn (string $code) => [
                'code' => $code,
                'name' => self::label($code),
                'selected' => $code === $selected,
            ],
            self::codes(),
        );
    }
}
