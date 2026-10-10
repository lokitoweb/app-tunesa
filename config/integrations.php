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

    // Tiempo máximo de los botones "Probar Webhook Ahora" / "Enviar Notificación de Prueba"
    'test_timeout' => (int) env('INTEGRATIONS_TEST_TIMEOUT', 5),

    /*
    | verify_ssl: en producción el certificado HTTPS del destino SIEMPRE se
    | verifica (este valor se ignora). Fuera de producción (local, testing,
    | staging) no se verifica por defecto, para probar contra túneles de
    | Cloudflare o certificados propios desde equipos sin certificados raíz al
    | día; INTEGRATIONS_VERIFY_SSL=true lo vuelve a activar.
    */
    'verify_ssl' => (bool) env('INTEGRATIONS_VERIFY_SSL', false),
];
