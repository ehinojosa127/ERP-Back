<?php

return [
    /*
    | Tamaño de página por defecto de todos los listados de la API. Ningún
    | endpoint debe repetir este número: se lee siempre desde aquí.
    */
    'default_page_size' => (int) env('API_DEFAULT_PAGE_SIZE', 10),

    /*
    | Techo de `per_page` para que un cliente no pueda pedir la tabla completa.
    */
    'max_page_size' => (int) env('API_MAX_PAGE_SIZE', 100),
];
