<?php

namespace App\Modules\Attendance\Services\Attendance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\User;
use App\Modules\Attendance\Notifications\AttendanceMockRepeated;
use App\Modules\Attendance\Notifications\AttendanceOutsideGeofence;
use App\Modules\Attendance\Notifications\AttendanceOutsideSchedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Avisos de marcaciones anómalas:
 *  - attendance.outside_schedule (aceptada, fuera de la ventana del turno)
 *  - attendance.outside_geofence (rechazada o aceptada con "flag")
 *  - attendance.mock_repeated (MOCK_ATTEMPTS_ALERT intentos con GPS falso en el día)
 *
 * Al colaborador: in-app + push. Al supervisor de la sucursal: solo in-app.
 * Los intentos rechazados se avisan como máximo una vez cada 10 minutos por
 * persona y tipo (la app puede reintentar varias veces seguidas). Un fallo
 * al notificar nunca afecta a la marcación.
 */
class AttendanceAlertNotifier
{
    public const REJECTED_THROTTLE_SECONDS = 600;

    /** Intentos con GPS falso en un día que disparan el aviso a supervisor y RRHH */
    public const MOCK_ATTEMPTS_ALERT = 3;

    public function outsideSchedule(Attendance $attendance, AttendancePunch $punch): void
    {
        $this->safely(function () use ($attendance, $punch) {
            $employee = $this->activeUser($attendance->user_id);
            if (! $employee) {
                return;
            }

            $attendance->loadMissing('shift');
            $employee->notify(new AttendanceOutsideSchedule($attendance, $punch, $employee));

            $this->supervisorOf($punch->branch_id, $employee)
                ?->notify((new AttendanceOutsideSchedule($attendance, $punch, $employee, forSupervisor: true))->withoutPush());
        });
    }

    /**
     * @param  array{branch_id?: ?int, distance_meters?: ?int, allowed_radius?: ?int, attendance_id?: ?int, punch_id?: ?int}  $info
     */
    public function outsideGeofence(?User $employee, array $info, string $punchType, bool $rejected): void
    {
        if (! $employee) {
            return;
        }

        $this->safely(function () use ($employee, $info, $punchType, $rejected) {
            if ($rejected && ! Cache::add("attendance-alert:geofence:{$employee->id}:{$punchType}", true, self::REJECTED_THROTTLE_SECONDS)) {
                return;
            }

            $branch = ! empty($info['branch_id'])
                ? Branch::withoutGlobalScope(TenantScope::class)->find($info['branch_id'])
                : null;
            $info['branch_name']    = $branch?->name;
            $info['allowed_radius'] = $info['allowed_radius'] ?? $branch?->allowed_radius_meters;

            $employee->notify(new AttendanceOutsideGeofence($info, $punchType, $rejected, $employee));

            $this->supervisorOf($branch?->id, $employee)
                ?->notify((new AttendanceOutsideGeofence($info, $punchType, $rejected, $employee, forSupervisor: true))->withoutPush());
        });
    }

    /**
     * Aviso único del día al llegar a MOCK_ATTEMPTS_ALERT intentos con GPS
     * falso: al supervisor de la sede y a administradores y RRHH (in-app).
     */
    public function mockRepeated(User $employee, int $attempts, ?int $branchId): void
    {
        $this->safely(function () use ($employee, $attempts, $branchId) {
            $day = now(tenant_timezone())->toDateString();
            if (! Cache::add("attendance-alert:mock:{$employee->id}:{$day}", true, now()->addDay())) {
                return;
            }

            $branchName = $branchId ? Branch::withoutGlobalScope(TenantScope::class)->whereKey($branchId)->value('name') : null;

            $recipients = User::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $employee->tenant_id)
                ->where('status', 'active')
                ->whereIn('role', ['company_admin', 'hr_manager'])
                ->get();

            if ($supervisor = $this->supervisorOf($branchId, $employee)) {
                $recipients->push($supervisor);
            }

            $recipients->unique('id')
                ->reject(fn (User $u) => (int) $u->id === (int) $employee->id)
                ->each(fn (User $u) => $u->notify((new AttendanceMockRepeated($employee, $attempts, $branchName))->withoutPush()));
        });
    }

    private function supervisorOf(?int $branchId, User $employee): ?User
    {
        if (! $branchId) {
            return null;
        }

        $supervisorId = Branch::withoutGlobalScope(TenantScope::class)->whereKey($branchId)->value('supervisor_id');

        if (! $supervisorId || (int) $supervisorId === (int) $employee->id) {
            return null;
        }

        $supervisor = $this->activeUser((int) $supervisorId);

        return $supervisor && (int) $supervisor->tenant_id === (int) $employee->tenant_id ? $supervisor : null;
    }

    private function activeUser(int $id): ?User
    {
        return User::withoutGlobalScope(TenantScope::class)->where('status', 'active')->find($id);
    }

    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el aviso de marcación: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
