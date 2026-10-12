<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use App\Modules\Attendance\Services\Reports\CalendarExporter;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Position;
use App\Modules\Core\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Asistencia > Calendario: matriz mensual con semáforo por colaborador.
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
