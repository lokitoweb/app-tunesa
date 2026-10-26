<?php

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Banderas por empresa de la ronda 2 de QA (tenants.settings.flags.*). Apagadas por
 * defecto: el comportamiento anterior sigue igual hasta encenderlas. Cada cambio queda
 * en la bitácora con el motivo y el usuario del sistema que corrió el comando.
 *
 *   php artisan attendance:flag empresa-demo schedule_v2               # estado actual
 *   php artisan attendance:flag empresa-demo schedule_v2 on --reason="Piloto Ag. Trujillo"
 *   php artisan attendance:flag 3 schedule_v2 off --reason="Vuelta atrás"
 *
 * Plan de retiro en docs/qa/BANDERAS.md.
 */
class AttendanceFlag extends Command
{
    /** schedule_v2: horario por fecha (HQ-01/02/05). hours_v2: el reporte descuenta el break igual que la jornada guardada (RP-01) */
    public const FLAGS = [ScheduleResolver::FLAG, 'hours_v2'];

    protected $signature = 'attendance:flag
                            {tenant : Id o slug de la empresa}
                            {flag : schedule_v2 o hours_v2}
                            {state? : on u off (sin valor: muestra el estado)}
                            {--reason= : Motivo del cambio (obligatorio al cambiarla)}';

    protected $description = 'Muestra, enciende o apaga una bandera de asistencia (QA Ronda 2) en una empresa';

    public function handle(AuditLogger $audit, TenantContext $context): int
    {
        $flag = (string) $this->argument('flag');
        if (! in_array($flag, self::FLAGS, true)) {
            $this->error('Bandera desconocida. Opciones: ' . implode(', ', self::FLAGS) . '.');

            return self::INVALID;
        }

        $key = (string) $this->argument('tenant');
        $tenant = Tenant::where('slug', $key)->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key))->first();
        if (! $tenant) {
            $this->error("No existe la empresa {$key}.");

            return self::FAILURE;
        }

        $before = (bool) $tenant->setting("flags.{$flag}", false);
        $state = $this->argument('state');
        if ($state === null) {
            $this->line("{$tenant->slug} · {$flag}: " . ($before ? 'on' : 'off'));

            return self::SUCCESS;
        }
        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('El estado debe ser on u off.');

            return self::INVALID;
        }
        $after = $state === 'on';
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 5) {
            $this->error('Indica el motivo con --reason (mínimo 5 caracteres).');

            return self::INVALID;
        }
        if ($after === $before) {
            $this->info("{$tenant->slug} · {$flag} ya estaba en {$state}. Sin cambios.");

            return self::SUCCESS;
        }

        $settings = (array) $tenant->settings;
        data_set($settings, "flags.{$flag}", $after);
        $tenant->forceFill(['settings' => $settings])->save();
        Cache::add(ScheduleResolver::flagsVersionKey($tenant->id), 0);
        Cache::increment(ScheduleResolver::flagsVersionKey($tenant->id));

        $context->set($tenant);
        $audit->logChanges('tenant.flag_changed', $tenant, ["flags.{$flag}" => $before], ["flags.{$flag}" => $after], [
            'reason'   => mb_substr($reason, 0, 500),
            'operator' => get_current_user(),
        ]);
        $context->clear();

        $this->info("{$tenant->slug} · {$flag}: " . ($before ? 'on' : 'off') . " → {$state}. Quedó en la bitácora.");

        return self::SUCCESS;
    }
}
