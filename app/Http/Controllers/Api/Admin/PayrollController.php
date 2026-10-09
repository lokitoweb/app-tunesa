<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\PayrollExportService;
use Illuminate\Http\Request;

/**
 * Cruce con Planilla / Nómina: consolidado del periodo con asistencia,
 * cuentas bancarias y régimen previsional.
 */
class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollExportService $payroll,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/admin/payroll/export?year=2026&month=9[&branch_id=][&format=json|csv]
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'year'      => ['required', 'integer', 'between:2020,2100'],
            'month'     => ['required', 'integer', 'between:1,12'],
            'branch_id' => ['nullable', 'integer'],
            'format'    => ['nullable', 'in:json,csv'],
        ]);

        $rows = $this->payroll->rows((int) $validated['year'], (int) $validated['month'], $validated['branch_id'] ?? null);

        $this->audit->log('payroll.exported', null, [
            'period' => sprintf('%04d-%02d', $validated['year'], $validated['month']),
            'rows'   => $rows->count(),
            'format' => $validated['format'] ?? 'csv',
        ]);

        if (($validated['format'] ?? 'csv') === 'json') {
            return response()->json(['success' => true, 'data' => ['columns' => PayrollExportService::COLUMNS, 'rows' => $rows]]);
        }

        return $this->payroll->download(
            $rows,
            sprintf('planilla_%04d_%02d.csv', $validated['year'], $validated['month']),
        );
    }
}
