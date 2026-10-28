<?php

namespace App\Modules\Integrations\Console;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Integrations\Jobs\SendPushNotification;
use App\Modules\Integrations\Services\Push\FcmSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnóstico de las notificaciones push (Firebase), eslabón por eslabón, sin mostrar secretos:
 * credenciales de la plataforma, push activo por empresa, celulares registrados, cola (pendientes y
 * fallidos) y, con --user, todo lo que decide si a esa persona le llega y un push de prueba real.
 */
class DiagnosePush extends Command
{
    protected $signature = 'push:diagnose {--user= : Correo de una persona para revisar su caso y enviarle un push de prueba}';

    protected $description = 'Revisa por qué no llegan las notificaciones push (Firebase)';

    public function handle(FcmSender $sender): int
    {
        $problems = 0;
        $check = function (string $label, bool $ok, string $detail = '', string $fix = '') use (&$problems) {
            $this->line(($ok ? '<info>  OK </info> ' : '<error> FALLA </error> ') . $label . ($detail !== '' ? " — {$detail}" : ''));
            if (! $ok && $fix !== '') {
                $this->line("        → {$fix}");
            }
            $problems += $ok ? 0 : 1;
        };

        $this->info('1. Credenciales de Firebase (plataforma)');
        $account = $sender->credentials();
        $check('Cuenta de servicio cargada y válida', $account !== null, $account ? "proyecto {$account['project_id']}" : '',
            'Super Admin › Marca y app › Integraciones: pega el JSON de la cuenta de servicio de Firebase.');
        if ($account) {
            try {
                $sender->accessToken($account, fresh: true);
                $check('Google acepta la cuenta de servicio', true);
            } catch (\Throwable $e) {
                $check('Google acepta la cuenta de servicio', false, $e->getMessage(),
                    'Genera una clave nueva en Firebase (Configuración › Cuentas de servicio) y cárgala; revisa que la hora del servidor sea correcta.');
            }
        }

        $this->newLine();
        $this->info('2. Push activo y celulares registrados por empresa');
        Tenant::where('status', 'active')->orderBy('company_name')->get()->each(function (Tenant $tenant) use ($check) {
            $users = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'active');
            $total = (clone $users)->count();
            $withToken = (clone $users)->whereNotNull('fcm_token')->where('fcm_token', '!=', '')->count();
            $last = (clone $users)->max('fcm_token_updated_at');
            $check("{$tenant->company_name}: push activo", TenantSetting::forTenant($tenant)->fcmEnabled(), '',
                'Marca y app de la empresa › activa las notificaciones push (y que la plataforma tenga la cuenta de servicio).');
            $check("{$tenant->company_name}: celulares registrados", $withToken > 0, "{$withToken} de {$total} persona(s)" . ($last ? ", último registro {$last}" : ''),
                'Nadie registró su celular: la app debe tener Firebase configurado en su compilación y la persona debe iniciar sesión y permitir notificaciones.');
        });

        $this->newLine();
        $this->info('3. Cola de envío');
        $connection = (string) config('queue.default');
        $fromScheduler = (bool) config('queue.work_from_scheduler');
        $check('Conexión de la cola', true, $connection . ($connection === 'sync' ? ' (se envía al momento)' : ($fromScheduler ? ' (la procesa el cron cada minuto)' : ' (requiere un queue:work permanente)')));
        if ($connection === 'database') {
            $pending = DB::table('jobs')->where('payload', 'like', '%SendPushNotification%');
            $count = (clone $pending)->count();
            $oldest = (clone $pending)->min('created_at');
            $minutes = $oldest ? (int) floor((time() - (int) $oldest) / 60) : 0;
            $check('Push en espera en la cola', $count === 0 || $minutes < 5, "{$count} pendiente(s)" . ($oldest ? ", el más antiguo hace {$minutes} min" : ''),
                'La cola no se está procesando: revisa el cron (crontab -u <usuario> -l debe tener «schedule:run») o el queue:work.');
        }
        $failed = DB::table('failed_jobs')->where('payload', 'like', '%SendPushNotification%');
        $failedCount = (clone $failed)->count();
        $lastError = $failedCount ? strtok((string) (clone $failed)->latest('failed_at')->value('exception'), "\n") : '';
        $check('Push fallidos en la cola', $failedCount === 0, $failedCount ? "{$failedCount}; último: " . mb_substr($lastError, 0, 160) : '');

        if ($email = $this->option('user')) {
            $this->newLine();
            $this->info("4. Caso de {$email}");
            $user = User::withoutGlobalScopes()->where('email', $email)->first();
            if (! $user) {
                $check('La persona existe', false, '', 'Revisa el correo.');
            } else {
                $check('Celular registrado', filled($user->fcm_token), $user->fcm_token_updated_at ? "registrado {$user->fcm_token_updated_at}" : '',
                    'Que cierre sesión en la app, vuelva a entrar y permita notificaciones (Perfil › Notificaciones muestra el estado).');
                $check('Puede recibir push (empresa activa + credenciales + celular)', $sender->canSendTo($user));
                $off = collect($user->notification_preferences ?? [])->filter(fn ($v) => $v === false)->keys();
                $check('Categorías de aviso encendidas', $off->isEmpty(), $off->isEmpty() ? '' : 'apagó: ' . $off->implode(', '),
                    'La persona apagó esas categorías en la app (Perfil › Notificaciones).');
                $this->reminders($user, $check);
                if (filled($user->fcm_token) && $account) {
                    $result = $sender->test($user);
                    $check('Push de prueba aceptado por Firebase', $result['ok'], $result['ok'] ? "en {$result['ms']} ms: revisa el celular" : ($result['error'] ?? ''),
                        'Si Firebase lo acepta pero no aparece: permiso de notificaciones del celular, ahorro de batería o la app sin el canal de avisos.');
                }
            }
        }

        $this->newLine();
        $problems ? $this->warn("{$problems} punto(s) por corregir (arriba, con →).") : $this->info('Todo en orden en el servidor.');

        return $problems ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Recordatorios de marcación (attendance:remind-entry): 5 min antes de la entrada a quien no
     * marcó y 5 min después de la salida esperada a quien no marcó salida. Solo con turno ese día.
     */
    private function reminders(User $user, \Closure $check): void
    {
        $this->line('  <comment>Recordatorios de marcación</comment>');
        $beat = (int) cache()->get('scheduler:last_run', 0);
        $check('Tareas automáticas corriendo (cron)', $beat > time() - 180, $beat ? 'último latido hace ' . (int) floor((time() - $beat) / 60) . ' min' : 'sin latido',
            'El cron no corre: sudo bash scripts/server-setup.sh lo reinstala.');
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'attendance:remind-entry'));
        $check('Tarea de recordatorios programada y libre', $event && ! $event->mutex->exists($event), $event ? '' : 'no está en el scheduler',
            'Quedó un bloqueo de una corrida anterior: php artisan schedule:clear-cache lo libera.');

        $tenant = Tenant::find($user->tenant_id);
        if (! $tenant) {
            return;
        }
        app(\App\Modules\Core\Services\TenantContext::class)->set($tenant);
        $today = now($tenant->timezone ?: config('app.timezone'));
        $status = app(\App\Modules\Attendance\Services\ScheduleGate::class)->statusFor($user, $today->toDateString());
        $shift = $status['shift'] ?? null;
        $check('Turno hoy', (bool) $shift,
            $shift ? "{$shift['name']} {$shift['start_time']} – {$shift['end_time']}: recordatorio de entrada desde las "
                . $today->copy()->setTimeFromTimeString($shift['start_time'])->subMinutes(\App\Modules\Attendance\Console\SendEntryReminders::LEAD_MINUTES)->format('H:i')
                . ', de salida desde las ' . $today->copy()->setTimeFromTimeString($shift['end_time'])->addMinutes(\App\Modules\Attendance\Console\SendEntryReminders::LEAD_MINUTES)->format('H:i')
                : 'sin turno programado hoy',
            'Sin turno no hay a qué hora recordar: asígnale turno (Horarios) o prográmale el día (Programación de horarios).');
        $attendance = \App\Modules\Attendance\Models\Attendance::where('user_id', $user->id)->where('work_date', $today->toDateString())->first();
        $this->line('        Hoy marcó: entrada ' . ($attendance?->clock_in_at?->format('H:i') ?? '—') . ', salida ' . ($attendance?->clock_out_at?->format('H:i') ?? '—')
            . ' (si ya marcó, no se le recuerda)');
        $last = fn (string $type) => $user->notifications()->where('type', $type)->latest()->value('created_at');
        $this->line('        Último recordatorio de entrada: ' . ($last('attendance.entry_reminder') ?? 'nunca') . ' · de salida: ' . ($last('attendance.exit_reminder') ?? 'nunca'));
        app(\App\Modules\Core\Services\TenantContext::class)->clear();
    }
}
