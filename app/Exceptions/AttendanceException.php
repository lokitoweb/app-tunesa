<?php

namespace App\Exceptions;

use Exception;

/**
 * Error de dominio de la marcación (AttendanceService) con un código estable
 * para la app (`code` en el JSON) y el HTTP status a devolver.
 *
 * Códigos: MOCK_LOCATION_DETECTED, GPS_ACCURACY_TOO_LOW, FACE_NOT_DETECTED,
 * OFFLINE_NOT_ALLOWED, OFFLINE_TOO_OLD, OFFLINE_BEFORE_CLOCK_IN,
 * OFFLINE_OUT_OF_ORDER, GEOFENCE_VIOLATION (solo distancia), NO_BRANCH_ASSIGNED,
 * ALREADY_CLOCKED_IN (409), NO_OPEN_ATTENDANCE (404), BREAK_NOT_ALLOWED,
 * BREAK_ALREADY_STARTED (409), BREAK_NOT_STARTED (409), BREAK_ALREADY_ENDED (409),
 * CLIENT_UUID_CONFLICT (409). Ver docs/api/mobile-endpoints.md.
 *
 * $context lleva datos internos (sucursal, distancia...) para avisos y
 * auditoría; no se devuelve a la app.
 */
class AttendanceException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
