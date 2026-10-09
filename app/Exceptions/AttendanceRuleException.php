<?php

namespace App\Exceptions;

use Exception;

/**
 * Regla de marcación incumplida con un código propio para la app
 * (p. ej. OFFLINE_BEFORE_CLOCK_IN). El código HTTP va en getCode().
 */
class AttendanceRuleException extends Exception
{
    public function __construct(string $message, int $status, public readonly string $errorCode)
    {
        parent::__construct($message, $status);
    }
}
