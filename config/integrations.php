<?php

/*
|--------------------------------------------------------------------------
| Integraciones de "Marca y app" (webhook y endpoint de notificaciones)
|--------------------------------------------------------------------------
|
| allow_private_urls: por defecto no se envía nada a direcciones privadas,
| locales o reservadas (127.0.0.1, 10.x, 192.168.x, 169.254.x, ::1…): el
| historial de envíos muestra la respuesta, y un administrador de empresa no
| debe poder usarlo para leer servicios internos del servidor. Actívalo solo
| en desarrollo local o si el ERP vive en la misma red privada.
|
*/

return [
    'allow_private_urls' => (bool) env('INTEGRATIONS_ALLOW_PRIVATE_URLS', false),

    // Tiempo máximo de cada envío (segundos)
    'timeout' => (int) env('INTEGRATIONS_TIMEOUT', 10),
];
