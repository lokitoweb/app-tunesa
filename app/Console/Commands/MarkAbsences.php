<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\EmployeeShift;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Control de faltas.
 *
 * Para cada empresa activa revisa el día indicado (por defecto, ayer en la
 * zona horaria de la empresa): todo colaborador con turno vigente que debía
 * trabajar ese día (fijo o rotativo) y no marcó entrada queda registrado como
 * falta ('absent'), o como 'excused' si tenía vacaciones/permiso/licencia
 * aprobada. Es idempotente: no toca días que ya tienen registro.
 *
 *   php artisan attendance:mark-absences
 *   php artisan attendance:mark-absences --date=2026-10-08 --tenant=empresa-demo
 */
class MarkAbsences extends Command
{
    protected $signature = 'attendance:mark-absences
                            {--date= : Fecha a procesar (Y-m-d). Por defecto: ayer en la zona horaria de cada empresa}
                            {--tenant= : Slug de una empresa concreta}';

    protected $description = 'Registra faltas de colaboradores que tenían turno y no marcaron asistencia';

    public function handle(TenantContext $context): int
    {
        $tenants = Tenant::where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        foreach ($tenants as $tenant) {
            $context->set($tenant);

            $date = $this->option('date')
                ? Carbon::parse($this->option('date'))
                : Carbon::now($tenant->timezone)->subDay();

            [$absent, $excused] = $this->processTenant($date);

            $this->info("{$tenant->slug} {$date->toDateString()}: {$absent} faltas, {$excused} justificadas por solicitud aprobada.");
        }

        $context->clear();

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function processTenant(Carbon $date): array
    {
        $day = $date->toDateString();
        $absent = $excused = 0;

        $assignments = EmployeeShift::current($day)
            ->with(['shift', 'user:id,status,hire_date,termination_date'])
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->filter(fn (EmployeeShift $a) => $a->worksOn($date))
            ->unique('user_id');

        $alreadyMarked = Attendance::where('work_date', $day)
            ->whereIn('user_id', $assignments->pluck('user_id'))
            ->pluck('user_id')
            ->flip();

        $onLeave = LeaveRequest::where('status', 'approved')
            ->overlapping($day, $day)
            ->whereIn('user_id', $assignments->pluck('user_id'))
            ->pluck('user_id')
            ->flip();

        foreach ($assignments as $assignment) {
            if (isset($alreadyMarked[$assignment->user_id])) {
                continue;
            }

            $user = $assignment->user;
            if (($user->hire_date && $user->hire_date->gt($date)) || ($user->termination_date && $user->termination_date->lt($date))) {
                continue;
            }

            $isExcused = isset($onLeave[$assignment->user_id]);

            Attendance::create([
                'user_id'   => $assignment->user_id,
                'branch_id' => $assignment->branch_id,
                'shift_id'  => $assignment->shift_id,
                'work_date' => $day,
                'status'    => $isExcused ? 'excused' : 'absent',
                'notes'     => $isExcused ? 'Ausencia cubierta por solicitud aprobada.' : 'Falta registrada automáticamente: no marcó entrada.',
            ]);

            $isExcused ? $excused++ : $absent++;
        }

        return [$absent, $excused];
    }
}
