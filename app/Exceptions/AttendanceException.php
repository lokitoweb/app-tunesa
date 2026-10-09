<?php

namespace App\Exceptions;

use Exception;

/**
 * Error de dominio de la marcación (AttendanceService) con un código estable
 * para la app (`code` en el JSON) y el HTTP status a devolver.
 *
 * Códigos: GEOFENCE_VIOLATION (solo distancia), NO_BRANCH_ASSIGNED,
 * ALREADY_CLOCKED_IN (409), NO_OPEN_ATTENDANCE (404), BREAK_NOT_ALLOWED,
 * BREAK_ALREADY_STARTED (409), BREAK_NOT_STARTED (409), BREAK_ALREADY_ENDED (409),
 * OFFLINE_BEFORE_CLOCK_IN. Ver docs/api/mobile-endpoints.md.
 */
class AttendanceException extends Exception
{
    public function __construct(string $message, public readonly string $errorCode, int $status = 422)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
