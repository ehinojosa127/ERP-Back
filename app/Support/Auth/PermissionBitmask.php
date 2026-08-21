<?php

namespace App\Support\Auth;

/**
 * Representación compacta de los ids de permiso como bitmask hexadecimal.
 *
 * Regla de codificación (debe coincidir exactamente con el frontend, ver
 * `src/utils/permission.util.ts`): el bit en la posición N está encendido
 * cuando el usuario posee el permiso con id N.
 *
 * Ejemplo: [1, 3, 5] -> bits 1, 3 y 5 -> 0b101010 -> "2a".
 *
 * Se trabaja sobre un arreglo de bytes y no sobre enteros nativos para que la
 * codificación no dependa del tamaño de palabra de PHP: los ids de permiso
 * pueden superar 63 sin desbordar ni perder precisión.
 *
 * El bitmask NO es un mecanismo de seguridad: solo comprime la lista de
 * permisos que viaja dentro del JWT. La autorización real siempre la resuelve
 * el backend contra la sesión en Redis.
 */
final class PermissionBitmask
{
    private const BITS_PER_BYTE = 8;

    private const EMPTY_MASK = '0';

    /** @param  array<int, int|string>  $permissionIds */
    public static function encode(array $permissionIds): string
    {
        /** @var array<int, int> $bytes */
        $bytes = [];
        $highestByte = 0;

        foreach ($permissionIds as $permissionId) {
            $id = (int) $permissionId;

            if ($id < 0) {
                continue;
            }

            $byteIndex = intdiv($id, self::BITS_PER_BYTE);
            $bitIndex = $id % self::BITS_PER_BYTE;

            $bytes[$byteIndex] = ($bytes[$byteIndex] ?? 0) | (1 << $bitIndex);
            $highestByte = max($highestByte, $byteIndex);
        }

        if ($bytes === []) {
            return self::EMPTY_MASK;
        }

        $hex = '';

        // Se escribe del byte más significativo al menos significativo.
        for ($byteIndex = $highestByte; $byteIndex >= 0; $byteIndex--) {
            $hex .= str_pad(dechex($bytes[$byteIndex] ?? 0), 2, '0', STR_PAD_LEFT);
        }

        return ltrim($hex, '0') ?: self::EMPTY_MASK;
    }

    /** @return array<int, int> */
    public static function decode(string $mask): array
    {
        $hex = ltrim(strtolower(trim($mask)), '0');

        if ($hex === '') {
            return [];
        }

        if (strlen($hex) % 2 !== 0) {
            $hex = '0'.$hex;
        }

        $permissionIds = [];
        $bytes = array_reverse(str_split($hex, 2));

        foreach ($bytes as $byteIndex => $byteHex) {
            $byte = (int) hexdec($byteHex);

            for ($bitIndex = 0; $bitIndex < self::BITS_PER_BYTE; $bitIndex++) {
                if (($byte & (1 << $bitIndex)) !== 0) {
                    $permissionIds[] = $byteIndex * self::BITS_PER_BYTE + $bitIndex;
                }
            }
        }

        sort($permissionIds);

        return $permissionIds;
    }
}
