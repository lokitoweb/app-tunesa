<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\UniformDelivery;
use App\Modules\Core\Models\User;
use App\Modules\HR\Services\UniformReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dotación: tallas del personal, resumen por talla para compras,
 * exportación para logística y registro de entregas.
 */
class UniformWebController extends Controller
{
    public function __construct(private readonly UniformReportService $report) {}

    public function index(Request $request): View
    {
        $branchId = $request->integer('branch_id') ?: null;

        return view('uniforms.index', [
            'rows'     => $this->report->rows($branchId),
            'summary'  => $this->report->summary($branchId),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'branchId' => $branchId,
            'recent'   => UniformDelivery::with('user:id,first_name,last_name')->latest('delivered_at')->latest('id')->limit(10)->get(),
        ]);
    }

    public function export(Request $request)
    {
        return $this->report->download($request->integer('branch_id') ?: null, 'tallas_dotacion_' . now()->format('Ymd') . '.csv');
    }

    public function storeDelivery(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id'      => ['required', 'integer'],
            'item'         => ['required', Rule::in(array_keys(UniformDelivery::ITEMS))],
            'size'         => ['nullable', 'string', 'max:10'],
            'quantity'     => ['required', 'integer', 'between:1,50'],
            'delivered_at' => ['required', 'date', 'before_or_equal:today'],
            'notes'        => ['nullable', 'string', 'max:255'],
        ]);

        $employee = User::findOrFail($validated['user_id']);

        UniformDelivery::create($validated + ['tenant_id' => $employee->tenant_id, 'delivered_by' => Auth::id()]);

        return back()->with('success', "Entrega registrada para {$employee->full_name}.");
    }
}
