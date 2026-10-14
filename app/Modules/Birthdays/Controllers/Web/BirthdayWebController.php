<?php

namespace App\Modules\Birthdays\Controllers\Web;

use App\Modules\Birthdays\Models\BirthdayGreeting;
use App\Modules\Birthdays\Services\BirthdayService;
use App\Modules\Birthdays\Services\BirthdaySettings;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cumpleaños en el panel: calendario por mes, quiénes no tienen fecha cargada
 * y a quién avisa la app el día del cumpleaños.
 */
class BirthdayWebController extends Controller
{
    public function __construct(private readonly BirthdayService $birthdays) {}

    public function index(Request $request): Response
    {
        $tenantId = current_tenant_id_or_null();
        $today    = now(tenant_timezone());
        $month    = max(1, min(12, $request->integer('month') ?: $today->month));
        $branch   = $request->integer('branch') ?: null;
        $search   = trim((string) $request->query('search'));

        $people = $tenantId ? $this->birthdays->month($tenantId, $month, $today, null, 'company', $search) : collect();
        if ($branch) {
            $ids = User::whereHas('branches', fn ($q) => $q->whereKey($branch))->pluck('id');
            $people = $people->whereIn('id', $ids)->values();
        }

        $countsByMonth = $tenantId
            ? User::where('status', 'active')->whereNotNull('birth_md')->where('role', '!=', 'super_admin')
                ->selectRaw('(birth_md - (birth_md % 100)) / 100 as m, COUNT(*) as total')->groupByRaw('(birth_md - (birth_md % 100)) / 100')->pluck('total', 'm')
                ->mapWithKeys(fn ($total, $m) => [(int) $m => (int) $total])
            : collect();

        return Inertia::render('Birthdays/Pages/Birthdays/Index', [
            'people'   => $people,
            'month'    => $month,
            'months'   => collect(BirthdayService::MONTHS)->map(fn ($name, $m) => ['value' => $m, 'label' => ucfirst($name), 'count' => $countsByMonth[$m] ?? 0])->values(),
            'todayPeople' => $tenantId ? $this->birthdays->upcoming($tenantId, $today, 0)->values() : [],
            'upcoming' => $tenantId ? $this->birthdays->upcoming($tenantId, $today, 7)->reject(fn ($p) => $p['is_today'])->values() : [],
            'missing'  => $tenantId ? User::where('status', 'active')->whereNull('birth_date')->whereIn('role', User::CLOCKING_ROLES)->count() : 0,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'settings' => BirthdaySettings::for($tenantId ? Tenant::find($tenantId) : null),
            'scopes'   => BirthdaySettings::SCOPES,
            'sentThisYear' => $tenantId ? BirthdayGreeting::where('year', $today->year)->count() : 0,
            'filters'  => ['branch' => $branch, 'search' => $search],
            'canEdit'  => $tenantId !== null && $request->user()->can('company.configure'),
            'needsTenant' => $tenantId === null,
        ]);
    }

    public function settings(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('company.configure'), 403);
        $tenant = Tenant::findOrFail(current_tenant_id_or_null());
        $data = $request->validate([
            'notify_colleagues' => ['required', Rule::in(array_keys(BirthdaySettings::SCOPES))],
            'greet_celebrant'   => ['boolean'],
            'hour'              => ['required', 'integer', 'between:0,23'],
        ]);
        BirthdaySettings::save($tenant, $data);
        $audit->log('birthdays.settings_updated', $tenant, $data);

        return back()->with('success', 'Ajustes de cumpleaños guardados.');
    }

    /** CSV del mes (para RRHH: tortas, saludos, presupuesto) */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($tenantId = current_tenant_id_or_null(), 422, 'Elige una empresa en el selector para exportar sus cumpleaños.');
        $month  = max(1, min(12, $request->integer('month') ?: now()->month));
        $people = $this->birthdays->month($tenantId, $month, now(tenant_timezone()));

        return response()->streamDownload(function () use ($people) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha', 'Colaborador', 'Cargo', 'Área', 'Sucursal']);
            foreach ($people as $p) {
                fputcsv($out, [$p['date_label'], $p['name'], $p['position'], $p['department'], $p['branch']]);
            }
            fclose($out);
        }, 'cumpleanos_' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
