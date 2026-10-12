<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use App\Modules\Attendance\Services\ManualAttendanceEdit;
use App\Modules\Attendance\Services\Reports\CalendarExporter;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Position;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\Zone;
use App\Modules\HR\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Asistencia > Calendario: matriz mensual con semáforo por colaborador, vista
 * del día por sede y turno (esperados vs. marcados) y el mes de una persona.
 * La ven RRHH (toda la empresa) y supervisores (su equipo).
 */
class CalendarWebController extends Controller
{
    public function __construct(
        private readonly AttendanceCalendarService $calendar,
        private readonly CalendarExporter $exporter,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeCalendar($request);
        $tenantId = current_tenant_id_or_null();
        $filters  = CalendarFilters::fromRequest($request, $tenantId);

        return view('attendance.calendar', [
            'filters'     => $filters,
            'rows'        => $this->calendar->paginate($filters),
            'days'        => $this->calendar->days($filters),
            'states'      => AttendanceCalendarService::STATES,
            'today'       => now(tenant_timezone())->toDateString(),
            'branches'    => Branch::forTenant($tenantId)->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'zones'       => Zone::forTenant($tenantId)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::forTenant($tenantId)->orderBy('name')->get(['id', 'name']),
            'positions'   => Position::forTenant($tenantId)->orderBy('name')->get(['id', 'name']),
            'canOpen'     => $request->user()->can('attendance.view_all'),
        ]);
    }

    /**
     * Vista del día: quién debía venir (por turno), quién marcó, a qué hora,
     * su selfie y a cuántos metros del punto de la sede. Hoy se recarga cada minuto.
     */
    public function day(Request $request): View
    {
        $this->authorizeCalendar($request);
        $tenantId = current_tenant_id_or_null();
        $filters  = CalendarFilters::fromRequest($request, $tenantId, daily: true);
        $date     = $filters->day;

        $page = $this->calendar->paginate($filters, 200);

        // Solo quienes tenían algo ese día: turno, permiso o marcación
        $rows = collect($page->items())
            ->map(fn (array $r) => $r + ['cell' => $r['cells'][$date] ?? null])
            ->filter(fn (array $r) => $r['cell'] && ($r['cell']['state'] !== 'rest' || $r['cell']['attendance_id']));

        $attendances = Attendance::forTenant($tenantId)
            ->whereIn('id', $rows->pluck('cell.attendance_id')->filter()->all())
            ->with('branch:id,name')
            ->get(['id', 'tenant_id', 'user_id', 'branch_id', 'work_date', 'clock_in_at', 'clock_out_at', 'clock_in_photo',
                'clock_in_distance_meters', 'clock_in_within_geofence'])
            ->keyBy('id');

        $now = CarbonImmutable::now(tenant_timezone());
        $isToday = $date === $now->toDateString();

        $rows = $rows->map(function (array $r) use ($attendances, $isToday, $now) {
            $cell = $r['cell'];
            $attendance = $cell['attendance_id'] ? $attendances->get($cell['attendance_id']) : null;

            // Hoy, sin marcar: "por llegar" hasta la hora de entrada más la tolerancia
            $waiting = $isToday && $cell['state'] === 'scheduled' && $cell['shift_start']
                && $now->format('H:i') <= CarbonImmutable::parse($cell['shift_start'])->addMinutes($cell['shift_grace'])->format('H:i');

            return $r + [
                'attendance' => $attendance,
                'outcome'    => match (true) {
                    $cell['clock_in'] !== null       => 'marked',
                    $cell['state'] === 'excused'     => 'excused',
                    $cell['state'] === 'rest'        => 'marked',
                    $waiting                         => 'upcoming',
                    default                          => 'missing',
                },
            ];
        });

        $counters = [
            'expected' => $rows->count(),
            'marked'   => $rows->where('outcome', 'marked')->count(),
            'late'     => $rows->filter(fn ($r) => $r['cell']['state'] === 'late')->count(),
            'missing'  => $rows->where('outcome', 'missing')->count(),
            'upcoming' => $rows->where('outcome', 'upcoming')->count(),
            'excused'  => $rows->where('outcome', 'excused')->count(),
        ];

        return view('attendance.calendar-day', [
            'filters'  => $filters,
            'page'     => $page,
            'groups'   => $rows->sortBy(fn ($r) => [$r['cell']['shift_start'] ?? '99:99', $r['last_name'], $r['first_name']])
                ->groupBy(fn ($r) => $r['cell']['shift'] ?? 'Sin turno asignado'),
            'counters' => $counters,
            'isToday'  => $isToday,
            'branches' => Branch::forTenant($tenantId)->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'shifts'   => Shift::forTenant($tenantId)->orderBy('start_time')->get(['id', 'name', 'start_time']),
            'canOpen'  => $request->user()->can('attendance.view_all'),
            'canEdit'  => $request->user()->can('attendance.edit'),
        ]);
    }

    /** Calendario de una persona: su mes en cuadrícula con el detalle de cada día */
    public function person(Request $request, int $id): View
    {
        $this->authorizeCalendar($request);
        $filters = CalendarFilters::fromRequest($request, current_tenant_id_or_null(), userId: $id);

        // Fuera del alcance de quien mira (otro equipo u otra empresa): no existe
        $row = $this->calendar->paginate($filters, 1)->first();
        abort_unless($row, 404);

        $first = CarbonImmutable::createFromFormat('!Y-m', $filters->month);

        return view('attendance.calendar-person', [
            'filters'  => $filters,
            'row'      => $row,
            'employee' => $employee = User::forTenant(current_tenant_id_or_null())->findOrFail($id),
            'weeks'    => array_chunk(array_merge(array_fill(0, $first->dayOfWeekIso - 1, null), array_keys($row['cells'])), 7),
            'states'   => AttendanceCalendarService::STATES,
            'today'    => now(tenant_timezone())->toDateString(),
            'canOpen'  => $request->user()->can('attendance.view_all'),
            'canEdit'  => app(ManualAttendanceEdit::class)->canEdit($request->user(), $employee),
        ]);
    }

    public function excel(Request $request): BinaryFileResponse
    {
        $this->authorizeCalendar($request);
        $filters = CalendarFilters::fromRequest($request, current_tenant_id_or_null());

        $path = tempnam(sys_get_temp_dir(), 'cal') . '.xlsx';
        $this->exporter->xlsx($filters, $path);

        return response()->download($path, "calendario-asistencia-{$filters->month}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private function authorizeCalendar(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->can('attendance.view_team') || $user->can('attendance.view_all'), 403);
    }
}
