<?php

namespace App\Support\Auth;

/**
 * Access token recién emitido junto a los datos que el resto del sistema
 * necesita conocer de él: su jti (identificador de la sesión en Redis) y el
 * bitmask de permisos que quedó firmado dentro.
 */
final class AccessToken
{
    public function __construct(
        public readonly string $token,
        public readonly string $jti,
        public readonly string $permissionsMask,
        public readonly int $expiresInSeconds,
    ) {}
}
