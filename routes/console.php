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

// Historial de envíos a integraciones (webhook / notificaciones): borra lo de más de 30 días
Schedule::command('model:prune', ['--model' => [\App\Modules\Integrations\Models\WebhookDelivery::class]])->dailyAt('03:15');

// Intentos de marcación rechazados: se guardan 12 meses
Schedule::command('model:prune', ['--model' => [\App\Modules\Attendance\Models\AttendancePunchAttempt::class]])->dailyAt('03:30');

// Reporte mensual de horas y faltas: el día 1 a las 07:00 genera el Excel del
// mes anterior de cada empresa (disco privado) y notifica a administradores y RRHH
Schedule::command('reports:send-monthly')->monthlyOn(1, '07:00')->withoutOverlapping()->onOneServer();

// Recordatorio push de entrada: a quien empezó su turno (más la tolerancia) y no marcó
Schedule::command('attendance:remind-entry')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Cola (push, webhooks, avisos de nueva versión): con solo el cron del scheduler
// se procesa cada minuto, sin un `queue:work` aparte. Si ya tienes un worker
// permanente (Supervisor, systemd), pon QUEUE_WORK_FROM_SCHEDULER=false.
if (config('queue.work_from_scheduler')) {
    Schedule::command('queue:work', ['--stop-when-empty', '--max-time=50', '--sleep=1'])
        ->everyMinute()->withoutOverlapping(5)->runInBackground();
}

// Capacitaciones: asigna cursos a quien le corresponden y avisa los que vencen pronto
Schedule::command('training:sync')->dailyAt('06:00')->withoutOverlapping()->onOneServer();
