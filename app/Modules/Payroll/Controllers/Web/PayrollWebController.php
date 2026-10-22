<?php

namespace App\Modules\Payroll\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Payroll\Services\PayrollExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cruce con planilla: vista previa del consolidado del periodo y descarga CSV.
 * En pantalla las cuentas se ven enmascaradas; el CSV las trae completas.
 */
class PayrollWebController extends Controller
{
    public function __construct(
        private readonly PayrollExportService $payroll,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        [$year, $month, $branchId] = $this->period($request);

        $rows = $this->payroll->rows($year, $month, $branchId)
            ->map(fn ($r) => array_merge($r, [
                'account_number' => EmployeeProfile::mask($r['account_number']),
                'interbank_code' => EmployeeProfile::mask($r['interbank_code']),
            ]));

        $totals = [
            'employees'     => $rows->count(),
            'worked_hours'  => round($rows->sum('worked_hours'), 1),
            'late_minutes'  => $rows->sum('late_minutes'),
            'absences'      => $rows->sum('absences'),
            'missing_bank'  => $rows->whereNull('bank_name')->count(),
            'not_verified'  => $rows->where('payment_status', '!=', 'Verificado')->count(),
        ];

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'rows'     => $rows->values(),
                'totals'   => $totals,
                'year'     => $year,
                'month'    => $month,
                'branchId' => $branchId,
            ]);
        }

        return view('payroll.index', [
            'rows'     => $rows,
            'totals'   => $totals,
            'year'     => $year,
            'month'    => $month,
            'branchId' => $branchId,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request)
    {
        [$year, $month, $branchId] = $this->period($request);

        $rows = $this->payroll->rows($year, $month, $branchId);
        $this->audit->log('payroll.exported', null, ['period' => sprintf('%04d-%02d', $year, $month), 'rows' => $rows->count(), 'format' => 'csv']);

        return $this->payroll->download($rows, sprintf('planilla_%04d_%02d.csv', $year, $month));
    }

    private function period(Request $request): array
    {
        $request->validate([
            'year'      => ['nullable', 'integer', 'between:2020,2100'],
            'month'     => ['nullable', 'integer', 'between:1,12'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $default = now()->subMonthNoOverflow();

        return [
            (int) $request->input('year', $default->year),
            (int) $request->input('month', $default->month),
            $request->integer('branch_id') ?: null,
        ];
    }
}
