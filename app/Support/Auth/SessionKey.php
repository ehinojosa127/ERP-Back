<?php

namespace App\Support\Auth;

/**
 * Único lugar donde se construyen las llaves de sesión en Redis.
 *
 * Formato: "session-{userId}-{jti}". El jti es el identificador único del
 * access token, así que cada token emitido tiene su propia sesión.
 */
final class SessionKey
{
    public static function for(int $userId, string $jti): string
    {
        return config('auth_tokens.session_key_prefix').$userId.'-'.$jti;
    }

    /** Set con los jti vigentes de un usuario; permite invalidarlos todos. */
    public static function indexFor(int $userId): string
    {
        return config('auth_tokens.session_index_prefix').$userId;
    }
}
