<?php

/**
 * Reglas anti-fraude de la marcación (selfie + GPS).
 */
return [
    // Rechazar marcaciones que la app reporta con ubicación simulada (mock GPS)
    'reject_mock_location' => env('ATTENDANCE_REJECT_MOCK_LOCATION', true),

    // Precisión GPS máxima aceptada en metros (0 = sin límite). Una lectura
    // con error mayor no permite asegurar que el colaborador está en la geocerca.
    'max_gps_accuracy_meters' => (int) env('ATTENDANCE_MAX_GPS_ACCURACY', 100),

    // Valor de fábrica de "Marcar solo con turno programado" (cada empresa lo
    // cambia en Configuración › Reglas). Las pruebas lo apagan (phpunit.xml) y
    // lo encienden solo donde prueban la regla.
    'punch_requires_schedule' => (bool) env('ATTENDANCE_PUNCH_REQUIRES_SCHEDULE', true),
];
