<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Attendance\Services\AbsenceService;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Control de faltas: quién tenía turno y no marcó, en un rango de fechas.
 * Mismo cálculo que el calendario; desde aquí se regulariza (corrección
 * manual) o se revisa la persona en el calendario.
 */
class AbsenceWebController extends Controller
{
    public function __construct(private readonly AbsenceService $absences) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->can('attendance.view_team') || $user->can('attendance.view_all'), 403);

        $today = CarbonImmutable::now(tenant_timezone());
        $defaults = ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->toDateString()];

        $data = $request->validate([
            'from'      => ['nullable', 'date_format:Y-m-d'],
            'to'        => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer'],
            'q'         => ['nullable', 'string', 'max:80'],
        ]);

        $from = $data['from'] ?? $defaults['from'];
        $to   = min($data['to'] ?? $defaults['to'], $today->toDateString()); // el futuro aún no tiene faltas
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // Rango acotado: la consulta recorre a cada persona día por día
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > AbsenceService::MAX_DAYS) {
            $from = CarbonImmutable::parse($to)->subDays(AbsenceService::MAX_DAYS)->toDateString();
        }

        $tenantId = current_tenant_id_or_null();
        $result = $this->absences->between($from, $to, $tenantId, $user, $data['branch_id'] ?? null, $data['q'] ?? null);

        return view('attendance.absences', [
            'absences' => $this->absences->paginate($result['items'], max(1, $request->integer('page', 1))),
            'total'    => $result['total'],
            'people'   => $result['people'],
            'filters'  => ['from' => $from, 'to' => $to, 'branch_id' => $data['branch_id'] ?? null, 'q' => $data['q'] ?? ''],
            'defaults' => $defaults,
            'branches' => Branch::forTenant($tenantId)->visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'canEdit'  => $user->can('attendance.edit'),
        ]);
    }
}
