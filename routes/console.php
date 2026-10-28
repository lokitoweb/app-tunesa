<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Señal de vida del scheduler: app:check-production avisa si el cron no está corriendo
// (lo instala scripts/server-setup.sh). Las tareas nuevas van aquí, nunca como otra línea de cron.
Schedule::call(fn () => cache()->forever('scheduler:last_run', now()->timestamp))->everyMinute()->name('scheduler-heartbeat');

// Control de faltas: cada madrugada registra las faltas del día anterior
// (requiere el scheduler: `* * * * * php artisan schedule:run`)
Schedule::command('attendance:mark-absences')->dailyAt('01:30')->withoutOverlapping();

// Limpia los tokens de Sanctum vencidos hace más de 24 h
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Historial de envíos a integraciones (webhook / notificaciones): borra lo de más de 30 días
Schedule::command('model:prune', ['--model' => [\App\Modules\Integrations\Models\WebhookDelivery::class]])->dailyAt('03:15');

// Intentos de marcación rechazados: se guardan 12 meses
Schedule::command('model:prune', ['--model' => [\App\Modules\Attendance\Models\AttendancePunchAttempt::class]])->dailyAt('03:30');

// Selfies de marcación con más de 12 meses (la bitácora conserva ruta y huella)
Schedule::command('attendance:purge-photos')->dailyAt('03:45')->withoutOverlapping()->onOneServer();

// Reporte mensual de horas y faltas: el día 1 a las 07:00 genera el Excel del
// mes anterior de cada empresa (disco privado) y notifica a administradores y RRHH
Schedule::command('reports:send-monthly')->monthlyOn(1, '07:00')->withoutOverlapping()->onOneServer();

// Recordatorio push de entrada: a quien empezó su turno (más la tolerancia) y no marcó
Schedule::command('attendance:remind-entry')->everyMinute()->withoutOverlapping()->onOneServer();

// Cola (push, webhooks, avisos de nueva versión): con solo el cron del scheduler
// se procesa cada minuto, sin un `queue:work` aparte. Si ya tienes un worker
// permanente (Supervisor, systemd), pon QUEUE_WORK_FROM_SCHEDULER=false.
if (config('queue.work_from_scheduler')) {
    // La geocodificación va en su cola, detrás de la de siempre (push primero)
    Schedule::command('queue:work', array_merge(['--stop-when-empty', '--max-time=50', '--sleep=1'],
        config('services.nominatim.queue') ? ['--queue=default,' . config('services.nominatim.queue')] : []))
        ->everyMinute()->withoutOverlapping(5)->runInBackground();
} elseif (config('services.nominatim.queue')) {
    // Con un worker permanente que solo escucha "default", las direcciones se llenan igual: el
    // scheduler vacía la cola de geocodificación cada minuto. Si el worker ya la escucha, no estorba
    // (cada trabajo lo toma uno solo). Sin tocar el servicio de systemd.
    Schedule::command('queue:work', ['--queue=' . config('services.nominatim.queue'), '--stop-when-empty', '--max-time=50', '--sleep=1'])
        ->name('geocoding-queue')->everyMinute()->withoutOverlapping(5)->runInBackground();
}

// Vencimientos (legajo, contratos, capacitaciones, SOAT y revisión técnica): aviso por correo y Telegram
Schedule::command('hr:expiry-alerts')->dailyAt('07:30')->withoutOverlapping()->onOneServer();

// Capacitaciones: asigna cursos a quien le corresponden y avisa los que vencen pronto
Schedule::command('training:sync')->dailyAt('06:00')->withoutOverlapping()->onOneServer();

// Cumpleaños: saludo y aviso a los compañeros (cada empresa a su hora local; un aviso por persona y año)
Schedule::command('birthdays:notify')->hourlyAt(5)->withoutOverlapping()->onOneServer();

// Comunicados programados: salen en el minuto indicado
Schedule::command('announcements:dispatch')->everyMinute()->withoutOverlapping()->onOneServer();
