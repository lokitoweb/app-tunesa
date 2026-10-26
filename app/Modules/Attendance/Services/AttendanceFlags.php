<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Banderas de asistencia por empresa (tenants.settings.flags.*): una sola forma de
 * cambiarlas, para el comando attendance:flag y para el panel. Cada cambio sube la
 * versión de caché (las pantallas lo ven al instante) y queda en la bitácora.
 */
class AttendanceFlags
{
    /** schedule_v2: horario por fecha (HQ-01/02/05). hours_v2: el reporte descuenta el break igual que la jornada guardada (RP-01) */
    public const FLAGS = [ScheduleResolver::FLAG, 'hours_v2'];

    public function __construct(private readonly AuditLogger $audit, private readonly TenantContext $context) {}

    public static function enabled(Tenant $tenant, string $flag): bool
    {
        return (bool) $tenant->setting("flags.{$flag}", false);
    }

    /** @return bool true si cambió; false si ya estaba en ese estado */
    public function set(Tenant $tenant, string $flag, bool $on, string $reason, string $operator): bool
    {
        if (! in_array($flag, self::FLAGS, true)) {
            throw new \InvalidArgumentException("Bandera desconocida: {$flag}");
        }
        $before = self::enabled($tenant, $flag);
        if ($before === $on) {
            return false;
        }

        $settings = (array) $tenant->settings;
        data_set($settings, "flags.{$flag}", $on);
        $tenant->forceFill(['settings' => $settings])->save();
        Cache::add(ScheduleResolver::flagsVersionKey($tenant->id), 0);
        Cache::increment(ScheduleResolver::flagsVersionKey($tenant->id));

        // La empresa de la request (otra instancia) también ve el cambio desde ya
        $previous = $this->context->getOrNull();
        if ($previous && $previous->id === $tenant->id && $previous !== $tenant) {
            $previous->forceFill(['settings' => $settings])->syncOriginal();
        }

        // La bitácora es de la empresa: se registra en su contexto y se deja como estaba
        $this->context->set($tenant);
        $this->audit->logChanges('tenant.flag_changed', $tenant, ["flags.{$flag}" => $before], ["flags.{$flag}" => $on], [
            'reason'   => mb_substr($reason, 0, 500),
            'operator' => $operator,
        ]);
        $previous ? $this->context->set($previous) : $this->context->clear();

        return true;
    }
}
