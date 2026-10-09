<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\View\View;

/**
 * Feriados de la empresa. El control de faltas no registra ausencias en
 * estas fechas. Botón para cargar los feriados nacionales del Perú del año.
 */
class HolidayWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $year = (int) $request->input('year', now(tenant_timezone())->year);
        abort_unless($year >= 2000 && $year <= 2100, 404);

        $holidays = Holiday::forTenant(current_tenant_id_or_null())
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get();

        return view('holidays.index', compact('holidays', 'year'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:150'],
        ], [], ['date' => 'fecha', 'name' => 'nombre']);

        // Una por fecha en la empresa (índice unique tenant_id + date)
        $validated['date'] = Carbon::parse($validated['date'])->toDateString();
        if (Holiday::where('date', $validated['date'])->exists()) {
            return back()->withInput()->withErrors(['date' => 'Ya hay un feriado registrado en esa fecha.']);
        }

        $holiday = Holiday::create($validated);
        $this->audit->log('holiday.created', $holiday, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

        return redirect()->route('holidays.index', ['year' => $holiday->date->year])
            ->with('success', "Feriado \"{$holiday->name}\" agregado.");
    }

    public function destroy(int $id): RedirectResponse
    {
        $holiday = Holiday::findOrFail($id);
        $holiday->delete();

        $this->audit->log('holiday.deleted', $holiday, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

        return back()->with('success', 'Feriado eliminado.');
    }

    /** Carga los feriados nacionales del Perú del año (no duplica fechas existentes) */
    public function loadPeru(Request $request): RedirectResponse
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

        return redirect()->route('holidays.index', ['year' => $year])->with(
            $added ? 'success' : 'info',
            $added ? "Se cargaron {$added} feriados nacionales de {$year}." : "Los feriados nacionales de {$year} ya estaban cargados."
        );
    }
}
