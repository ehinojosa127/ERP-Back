<?php

return [
    /*
    | Access token: corta duración. Tras expirar, el cliente usa el refresh.
    */
    'access_ttl_minutes' => (int) env('JWT_TTL', 20),

    /*
    | Refresh token: ventana de INACTIVIDAD (minutos). Cada refresh exitoso
    | rota el token y reinicia esta ventana. No es un tope absoluto de sesión:
    | un usuario activo puede renovar indefinidamente.
    */
    'refresh_ttl_minutes' => (int) env('JWT_REFRESH_TOKEN_TTL_MINUTES', 40),

    /*
    | Sesión en Redis. La llave es "session-{userId}-{jti}", de modo que cada
    | access token emitido tiene su propia entrada y cerrar sesión en un
    | dispositivo no afecta a los demás. La llave de índice guarda el conjunto
    | de jti vigentes de un usuario para poder invalidarlos todos de golpe.
    |
    | La construcción de ambas llaves vive en App\Support\Auth\SessionKey.
    */
    'session_key_prefix' => env('SESSION_KEY_PREFIX', 'session-'),
    'session_index_prefix' => env('SESSION_INDEX_PREFIX', 'session-index-'),
    'redis_connection' => env('SESSION_REDIS_CONNECTION', 'default'),
];
