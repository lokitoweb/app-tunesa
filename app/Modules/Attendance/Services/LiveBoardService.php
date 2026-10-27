<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Estado en vivo del personal para el "Tablero en vivo" (/live).
 *
 * Estados (hoy, en la zona horaria de cada empresa):
 *  - working:     marcó entrada y no salió (incluye turnos nocturnos de ayer aún abiertos)
 *  - on_break:    en descanso
 *  - left:        ya marcó salida
 *  - on_leave:    solicitud aprobada que cubre hoy
 *  - not_arrived: debía trabajar hoy, pasó la hora de entrada + tolerancia y no marcó
 *  - upcoming:    debía trabajar hoy y aún no llega su hora (más tolerancia)
 * Quien descansa hoy (turno o feriado) y no marcó no aparece.
 */
class LiveBoardService
{
    public const STATUS_LABELS = [
        'working'     => 'Trabajando',
        'on_break'    => 'En descanso',
        'not_arrived' => 'No ha llegado',
        'upcoming'    => 'Por llegar',
        'left'        => 'Salió',
        'on_leave'    => 'Con permiso',
    ];

    /** Cada cuántos segundos se renuevan las URLs firmadas aunque nada cambie */
    private const URL_REFRESH_SECONDS = 300;

    private ?bool $suspiciousColumn = null;

    /** La columna attendances.is_suspicious la agrega otro módulo: solo se usa si existe */
    public function tracksSuspicious(): bool
    {
        return $this->suspiciousColumn ??= Schema::hasColumn('attendances', 'is_suspicious');
    }

    /**
     * Estado actual del equipo visible para $viewer, con un ETag estable
     * mientras nada cambie (sin URLs firmadas, que cambian en cada llamada).
     */
    public function snapshot(?int $tenantId, User $viewer, ?int $branchId = null): array
    {
        $users = User::forTenant($tenantId)
            ->teamOf($viewer)
            ->when($branchId, fn ($q, $id) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $id)))
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['users.id', 'users.tenant_id', 'first_name', 'last_name', 'employee_code', 'position', 'avatar_path']);

        $ids     = $users->pluck('id')->all();
        $tenants = Tenant::whereIn('id', $users->pluck('tenant_id')->unique()->all())->get(['id', 'timezone', 'company_name'])->keyBy('id');

        // "Hoy" y "ahora" por empresa (hora de pared local)
        $clock = $tenants->map(function (Tenant $t) {
            $now = Carbon::now($t->timezone ?: config('app.timezone'));

            return ['now' => $now, 'today' => $now->toDateString(), 'yesterday' => $now->copy()->subDay()->toDateString()];
        });
        $dates = $clock->flatMap(fn ($c) => [$c['today'], $c['yesterday']])->unique()->values()->all();
        $todays = $clock->pluck('today')->unique()->values()->all();

        if (! $ids || ! $todays) {
            $ids = [];
            $todays = [Carbon::now(tenant_timezone())->toDateString()];
            $dates = $todays;
        }

        $attendances = $ids ? Attendance::forTenant($tenantId)
            ->whereIn('user_id', $ids)
            ->whereIn('work_date', $dates)
            ->with('branch:id,name', 'shift')   // shift: se lee por fila más abajo (era una consulta por persona)
            ->orderByDesc('clock_in_at')
            ->get()
            ->groupBy('user_id') : collect();

        $assignments = $ids ? EmployeeShift::forTenant($tenantId)
            ->whereIn('user_id', $ids)
            ->where('is_active', true)
            ->where('effective_from', '<=', max($todays))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', min($todays)))
            ->with('shift', 'branch:id,name')
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('user_id') : collect();

        $leaves = $ids ? LeaveRequest::forTenant($tenantId)
            ->whereIn('user_id', $ids)
            ->where('status', 'approved')
            ->overlapping(min($todays), max($todays))
            ->get(['user_id', 'type', 'start_date', 'end_date'])
            ->groupBy('user_id') : collect();

        $holidays = $ids ? Holiday::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenants->keys()->all())
            ->whereIn('date', $todays)
            ->get(['tenant_id', 'date'])
            ->mapWithKeys(fn (Holiday $h) => [$h->tenant_id . '|' . $h->date->toDateString() => true]) : collect();

        $branchNames = $ids ? DB::table('employee_branch')
            ->join('branches', 'branches.id', '=', 'employee_branch.branch_id')
            ->whereIn('employee_branch.user_id', $ids)
            ->orderByDesc('employee_branch.is_primary')
            ->get(['employee_branch.user_id', 'branches.name'])
            ->groupBy('user_id')->map(fn ($r) => $r->first()->name) : collect();

        $rows = [];
        $models = [];
        $schedules = app(ScheduleResolver::class);
        foreach ($users->groupBy('tenant_id') as $tenantId => $group) {
            $schedules->preload((int) $tenantId, $group->pluck('id')->all(), min($todays), max($todays));
        }

        foreach ($users as $user) {
            $c = $clock->get($user->tenant_id);
            if (! $c) {
                continue;
            }

            $records = $attendances->get($user->id, collect());
            $current = $records->first(fn (Attendance $a) => $a->work_date->toDateString() === $c['today'] && $a->clock_in_at)
                ?? $records->first(fn (Attendance $a) => $a->work_date->toDateString() === $c['yesterday'] && $a->clock_in_at && ! $a->clock_out_at);

            $resolved = $schedules->resolve($assignments->get($user->id, collect()), Carbon::parse($c['today']), ScheduleResolver::LEGACY_LATEST,
                userId: $user->id, tenantId: $user->tenant_id);
            $assignment = $resolved->reference;
            $shift = ($current?->shift ?? $assignment?->shift)?->forDate($current?->work_date ?? $c['today']);

            $row = [
                'user_id'         => $user->id,
                'attendance_id'   => $current?->id,
                'tenant_id'       => $user->tenant_id,
                'company'         => $tenants->get($user->tenant_id)?->company_name,
                'name'            => trim("{$user->first_name} {$user->last_name}"),
                'employee_code'   => $user->employee_code,
                'position'        => $user->position,
                'avatar_path'     => $user->avatar_path,
                'avatar_url'      => $user->avatar_path ? asset('storage/' . $user->avatar_path) : null,
                'branch'          => $current?->branch?->name ?? $branchNames->get($user->id),
                'shift'           => $shift?->name,
                'shift_start'     => hora($shift?->start_time),
                'clock_in'        => hora($current?->clock_in_at),
                'clock_out'       => hora($current?->clock_out_at),
                'break_start'     => hora($current?->break_start_at),
                'late_minutes'    => (int) ($current?->late_minutes ?? 0),
                'within_geofence' => $current ? (bool) $current->clock_in_within_geofence : null,
                'distance_meters' => $current?->clock_in_distance_meters,
                'latitude'        => $current?->clock_in_latitude !== null && $current ? (float) $current->clock_in_latitude : null,
                'longitude'       => $current?->clock_in_longitude !== null && $current ? (float) $current->clock_in_longitude : null,
                'has_photo'       => (bool) $current?->clock_in_photo,
                'suspicious'      => $current && $this->tracksSuspicious() ? (bool) $current->getAttribute('is_suspicious') : false,
            ];

            if ($current) {
                $row['status'] = match (true) {
                    $current->clock_out_at !== null => 'left',
                    $current->isOnBreak()           => 'on_break',
                    default                         => 'working',
                };
                $models[$current->id] = $current;
            } else {
                $todayRecord = $records->first(fn (Attendance $a) => $a->work_date->toDateString() === $c['today']);
                $onLeave = $leaves->get($user->id, collect())->contains(fn (LeaveRequest $l) => $l->start_date->toDateString() <= $c['today'] && $l->end_date->toDateString() >= $c['today'])
                    || $todayRecord?->status === 'excused';

                $worksToday = $resolved->works
                    && ! $holidays->has($user->tenant_id . '|' . $c['today']);

                if ($onLeave) {
                    $row['status'] = 'on_leave';
                } elseif ($worksToday && $shift?->start_time) {
                    $due = Carbon::parse($c['today'] . ' ' . $shift->start_time, $c['now']->getTimezone())
                        ->addMinutes((int) ($shift->grace_period_minutes ?? 0));
                    $row['status'] = $c['now']->gt($due) ? 'not_arrived' : 'upcoming';
                    $row['branch'] ??= $assignment->branch?->name;
                } else {
                    continue; // descansa hoy
                }
            }

            $row['status_label'] = self::STATUS_LABELS[$row['status']];
            $rows[] = $row;
        }

        $counters = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($rows as $row) {
            $counters[$row['status']]++;
        }
        if ($this->tracksSuspicious()) {
            $counters['suspicious'] = count(array_filter($rows, fn ($r) => $r['suspicious']));
        }

        $etag = sha1(json_encode([$rows, $counters, intdiv(now()->getTimestamp(), self::URL_REFRESH_SECONDS)]));

        return ['rows' => $rows, 'counters' => $counters, 'etag' => $etag, 'models' => $models];
    }

    /** Respuesta JSON: agrega URLs firmadas de selfies (vencen a los 15 min) */
    public function present(array $snapshot): array
    {
        $rows = array_map(function (array $row) use ($snapshot) {
            $model = $row['attendance_id'] ? ($snapshot['models'][$row['attendance_id']] ?? null) : null;
            $row['photo_url'] = $model && $row['has_photo'] ? attendance_photo_url($model, 'in') : null;
            $row['detail_url'] = $row['attendance_id'] && Route::has('attendance.show') && auth()->user()?->can('attendance.view_all')
                ? route('attendance.show', $row['attendance_id'])
                : null;

            return $row;
        }, $snapshot['rows']);

        return [
            'success'      => true,
            'generated_at' => now()->toIso8601String(),
            'etag'         => $snapshot['etag'],
            'counters'     => $snapshot['counters'],
            'labels'       => self::STATUS_LABELS,
            'rows'         => $rows,
        ];
    }
}
