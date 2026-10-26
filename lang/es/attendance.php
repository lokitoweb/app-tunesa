<?php

// Etiquetas de asistencia: único lugar de los textos de estado (QA Ronda 2, RP-03).
// Calendario, reportes, exportaciones y API los leen con App\Modules\Attendance\Support\DayStatus.
return [
    'status' => [
        'normal'        => 'Normal',
        'late'          => 'Tardanza',
        'absent'        => 'Faltó',
        'incomplete'    => 'Marcación incompleta',
        'rest'          => 'Descanso',
        'vacation'      => 'Vacaciones',
        'permission'    => 'Permiso',
        'medical_leave' => 'Descanso médico',
        'excused'       => 'Falta justificada',
        'holiday'       => 'Feriado',
        'half_day'      => 'Media jornada',
        'scheduled'     => 'Programado',
    ],
];
