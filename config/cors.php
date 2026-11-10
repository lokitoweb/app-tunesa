<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
| La app móvil no usa CORS (no es un navegador) y el panel llama a su propio dominio, así que la
| API solo acepta navegadores del dominio del sistema (APP_URL) y de los que se agreguen en
| CORS_ALLOWED_ORIGINS (separados por coma). Antes regía el valor por defecto de Laravel: "*".
*/

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_merge(
        [rtrim((string) env('APP_URL', ''), '/')],
        array_map(fn ($o) => rtrim(trim($o), '/'), explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
