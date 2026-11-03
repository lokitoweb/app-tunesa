<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\HR\Models\Holiday;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Feriados de la empresa. El control de faltas no registra ausencias en
 * estas fechas. Botón para cargar los feriados nacionales del Perú del año.
 */
class HolidayWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response|JsonResponse
    {
        $year = (int) $request->input('year', now(tenant_timezone())->year);
        abort_unless($year >= 2000 && $year <= 2100, 404);

        $tenantId = current_tenant_id_or_null();

        $holidays = Holiday::forTenant($tenantId)
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $h) => $h->present());

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'holidays'      => $holidays,
                'year'          => $year,
                'loadPeruLabel' => "Cargar feriados nacionales de Perú {$year}",
                'today'         => now(tenant_timezone())->toDateString(),
            ]);
        }

        return Inertia::render('HR/Pages/Holidays/Index', [
            'holidays'      => $holidays,
            'year'          => $year,
            'loadPeruLabel' => "Cargar feriados nacionales de Perú {$year}",
            'today'         => now(tenant_timezone())->toDateString(),
            'kinds'         => Holiday::KINDS,
            'overtimeRules' => Holiday::OVERTIME_RULES,
        ]);
    }

    /** Tipo de día y regla de horas extra (lo comparten alta y edición) */
    private function ruleFields(Request $request): array
    {
        $data = $request->validate([
            'kind'          => ['sometimes', Rule::in(array_keys(Holiday::KINDS))],
            'overtime_rule' => ['sometimes', Rule::in(array_keys(Holiday::OVERTIME_RULES))],
            'overtime_from' => ['nullable', 'required_if:overtime_rule,after', 'date_format:H:i'],
        ], ['overtime_from.required_if' => 'Indica desde qué hora es hora extra.'], ['overtime_from' => 'hora']);

        if (($data['overtime_rule'] ?? null) && $data['overtime_rule'] !== 'after') {
            $data['overtime_from'] = null;
        }

        return $data;
    }

    /** Cambia el tipo de día o su regla de horas extra */
    public function update(int $id, Request $request): JsonResponse
    {
        $holiday = Holiday::forTenant(current_tenant_id_or_null())->findOrFail($id);
        $holiday->update($this->ruleFields($request));
        $this->audit->log('holiday.updated', $holiday, $holiday->only(['kind', 'overtime_rule', 'overtime_from']));

        return response()->json(['success' => true, 'holiday' => $holiday->present(), 'message' => 'Regla del día guardada.']);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:150'],
        ], [], ['date' => 'fecha', 'name' => 'nombre']);

        // Una por fecha en la empresa (índice unique tenant_id + date)
        $validated['date'] = Carbon::parse($validated['date'])->toDateString();
        if (Holiday::where('date', $validated['date'])->exists()) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'errors' => ['date' => ['Ya hay un feriado registrado en esa fecha.']]
                ], 422);
            }
            return back()->withInput()->withErrors(['date' => 'Ya hay un feriado registrado en esa fecha.']);
        }

        $holiday = Holiday::create($validated + $this->ruleFields($request));
        $this->audit->log('holiday.created', $holiday, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'holiday' => $holiday->fresh()->present(),
                'message' => "Feriado \"{$holiday->name}\" agregado.",
            ]);
        }

        return redirect()->route('holidays.index', ['year' => $holiday->date->year])
            ->with('success', "Feriado \"{$holiday->name}\" agregado.");
    }

    public function destroy(int $id, Request $request): RedirectResponse|JsonResponse
    {
        $holiday = Holiday::forTenant(current_tenant_id_or_null())->findOrFail($id);
        $holiday->delete();

        $this->audit->log('holiday.deleted', $holiday, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Feriado eliminado.',
            ]);
        }

        return back()->with('success', 'Feriado eliminado.');
    }

    /** Carga los feriados nacionales del Perú del año (no duplica fechas existentes) */
    public function loadPeru(Request $request): RedirectResponse|JsonResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];

        $existing = Holiday::whereYear('date', $year)->get()->map(fn (Holiday $h) => $h->date->toDateString())->flip();
        $added = 0;

        foreach (Holiday::peruNational($year) as $date => $name) {
            if (! isset($existing[$date])) {
                Holiday::create(['date' => $date, 'name' => $name]);
                $added++;
            }
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'added'   => $added,
                'message' => $added ? "Se cargaron {$added} feriados nacionales de {$year}." : "Los feriados nacionales de {$year} ya estaban cargados.",
                'year'    => $year,
            ]);
        }

        return redirect()->route('holidays.index', ['year' => $year])->with(
            $added ? 'success' : 'info',
            $added ? "Se cargaron {$added} feriados nacionales de {$year}." : "Los feriados nacionales de {$year} ya estaban cargados."
        );
    }
}
