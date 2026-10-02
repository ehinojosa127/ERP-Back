<?php

return [

    'paths' => ['api/*', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173')),
    ))),

    // Orígenes de la WebView Capacitor (Android https://localhost, iOS capacitor://localhost).
    // En el VPS: no hardcodees Access-Control-Allow-Origin en nginx del API;
    // deja que Laravel echoee el Origin permitido.
    'allowed_origins_patterns' => [
        '#^https?://localhost$#',
        '#^https?://localhost:\d+$#',
        '#^capacitor://localhost$#',
        '#^ionic://localhost$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
