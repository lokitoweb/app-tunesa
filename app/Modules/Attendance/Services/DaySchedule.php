<?php

namespace App\Modules\Attendance\Services;

use App\Modules\HR\Models\EmployeeShift;

/** Programación de una persona en una fecha, según ScheduleResolver. */
final class DaySchedule
{
    public function __construct(
        /** Asignación con la que se calcula el día (tardanza, descanso, jornada base) */
        public readonly ?EmployeeShift $assignment,
        /** Asignación de referencia para la sede y para mostrar el turno; puede no trabajar ese día */
        public readonly ?EmployeeShift $reference,
        /** ¿Le toca trabajar ese día? */
        public readonly bool $works,
        /** ¿Tiene alguna asignación vigente? Sin ninguna: sin programación */
        public readonly bool $hasAssignments,
    ) {}
}
