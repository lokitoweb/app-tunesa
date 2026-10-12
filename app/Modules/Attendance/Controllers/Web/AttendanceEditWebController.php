<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Attendance\Services\ManualAttendanceEdit;
use App\Modules\Core\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Corrección manual de una jornada (Calendario > persona > día).
 * Permiso attendance.edit; supervisores, solo su equipo.
 */
class AttendanceEditWebController extends Controller
{
    public function __construct(private readonly ManualAttendanceEdit $edits) {}

    public function edit(Request $request, int $id, string $date): View
    {
        $employee = $this->employee($request, $id);

        return view('attendance.manual-edit', [
            'employee'   => $employee,
            'date'       => $date,
            'attendance' => $this->edits->find($employee, $date)?->load(['shift', 'manualEditor']),
        ]);
    }

    public function update(Request $request, int $id, string $date): RedirectResponse
    {
        $employee = $this->employee($request, $id);

        $data = $request->validate([
            'clock_in'      => ['nullable', 'date_format:H:i'],
            'clock_out'     => ['nullable', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'between:0,480'],
            'reason'        => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'clock_in.date_format'  => 'Escribe la entrada en formato 24 h, por ejemplo 08:00.',
            'clock_out.date_format' => 'Escribe la salida en formato 24 h, por ejemplo 17:30.',
            'reason.required'       => 'Indica el motivo de la corrección.',
            'reason.min'            => 'Explica un poco más el motivo de la corrección.',
        ]);

        $this->edits->save($request->user(), $employee, $date, $data['clock_in'] ?? null, $data['clock_out'] ?? null,
            isset($data['break_minutes']) ? (int) $data['break_minutes'] : null, $data['reason']);

        return redirect()->route('attendance.calendar.person', ['id' => $employee->id, 'month' => substr($date, 0, 7)])
            ->with('success', 'Asistencia del ' . \Carbon\Carbon::parse($date)->format('d/m/Y') . ' corregida. Quedó registrada en la bitácora.');
    }

    private function employee(Request $request, int $id): User
    {
        $employee = User::forTenant(current_tenant_id_or_null())->findOrFail($id);
        abort_unless($this->edits->canEdit($request->user(), $employee), 403);

        return $employee;
    }
}
