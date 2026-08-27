<?php

namespace App\Support\Customers;

final class PhoneNormalizer
{
    public static function digits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    /**
     * Variantes de búsqueda para no duplicar por formato (+51, espacios, etc.).
     *
     * @return array<int, string>
     */
    public static function searchVariants(?string $phone): array
    {
        $digits = self::digits($phone);
        if ($digits === '') {
            return [];
        }

        $variants = [$digits];

        if (str_starts_with($digits, '51') && strlen($digits) >= 11) {
            $variants[] = substr($digits, 2);
        } elseif (strlen($digits) === 9) {
            $variants[] = '51'.$digits;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /** Forma canónica a persistir (E.164 PE sin + cuando aplica). */
    public static function canonical(?string $phone): string
    {
        $digits = self::digits($phone);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 9) {
            return '51'.$digits;
        }

        return $digits;
    }
}
