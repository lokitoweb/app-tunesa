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
