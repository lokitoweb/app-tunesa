<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Control de faltas: cada madrugada registra las faltas del día anterior
// (requiere el scheduler: `* * * * * php artisan schedule:run`)
Schedule::command('attendance:mark-absences')->dailyAt('01:30')->withoutOverlapping();

// Limpia los tokens de Sanctum vencidos hace más de 24 h
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Reporte mensual de horas y faltas: el día 1 a las 07:00 genera el Excel del
// mes anterior de cada empresa (disco privado) y notifica a administradores y RRHH
Schedule::command('reports:send-monthly')->monthlyOn(1, '07:00')->withoutOverlapping()->onOneServer();
