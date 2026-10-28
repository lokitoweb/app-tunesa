<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Geocodificación (App\Services\Geocoding). Nominatim: máx. 1 petición por segundo
    // (política de uso de OpenStreetMap); el User-Agent lleva APP_URL.
    'nominatim' => [
        'url'             => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'countrycodes'    => env('NOMINATIM_COUNTRYCODES'), // p. ej. "pe" o "pe,cl"
        'email'           => env('NOMINATIM_EMAIL'),
        'min_interval_ms' => (int) env('NOMINATIM_MIN_INTERVAL_MS', 1000),
        'timeout'         => (int) env('NOMINATIM_TIMEOUT', 8),
        // Cola de la geocodificación de marcaciones. Vacío = la cola por defecto (como siempre).
        // Cola propia de baja prioridad por defecto: una hora pico de marcaciones no retrasa los
        // avisos push. El scheduler la procesa siempre (routes/console.php), con o sin worker
        // permanente. GEOCODING_QUEUE= (vacío) la vuelve a la cola de siempre.
        'queue'           => env('GEOCODING_QUEUE', 'geocoding') ?: null,
    ],

    'google_geocoding' => [
        'url'     => env('GOOGLE_GEOCODING_URL', 'https://maps.googleapis.com/maps/api/geocode/json'),
        'timeout' => (int) env('GOOGLE_GEOCODING_TIMEOUT', 8),
    ],

    // Push FCM (App\Modules\Integrations\Services\Push\FcmSender). La cuenta de servicio se carga en Marca y app.
    'fcm' => [
        'android_channel_id' => env('FCM_ANDROID_CHANNEL_ID', 'tunesa_default'),
    ],

    // Valores por defecto de la plataforma. Se administran en Integraciones (SuperAdmin),
    // cifrados en tenant_settings; .env solo los siembra la primera vez y es el respaldo.
    // Consulta RUC/DNI (mismo acceso que el ERP de escritorio)
    'migo' => [
        'token'   => env('MIGO_API_TOKEN'),
        'ruc_url' => env('MIGO_RUC_URL', 'https://api.migo.pe/api/v1/ruc'),
        'dni_url' => env('MIGO_DNI_URL', 'https://api.migo.pe/api/v1/dni'),
    ],

    // Facturación electrónica
    'nubefact' => [
        'url'   => env('NUBEFACT_URL'),
        'token' => env('NUBEFACT_TOKEN'),
    ],

    // Bot de Telegram para alertas (de @BotFather). También se configura en Integraciones (cifrado)
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'api_url'   => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
    ],

];
