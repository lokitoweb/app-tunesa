<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cruce de asistencia con datos de planilla para un periodo:
 * horas trabajadas, horas extra, tardanzas, faltas, licencias aprobadas,
 * cuenta de abono, régimen previsional, régimen laboral y CTS.
 *
 * Se usa en GET /api/admin/payroll/export y en el panel (/payroll).
 */
class PayrollExportService
{
    public const COLUMNS = [
        'company'            => 'Empresa',
        'employee_code'      => 'Código',
        'document_type'      => 'Tipo doc.',
        'document_number'    => 'N° documento',
        'full_name'          => 'Colaborador',
        'position'           => 'Cargo',
        'department'         => 'Área',
        'hire_date'          => 'Fecha de ingreso',
        'labor_regime'       => 'Régimen laboral',
        'base_salary'        => 'Sueldo base',
        'bonus'              => 'Bono',
        'total_salary'       => 'Remuneración (sueldo + bono)',
        'days_worked'        => 'Días trabajados',
        'worked_hours'       => 'Horas trabajadas',
        'overtime_hours'     => 'Horas extra aprobadas',
        'overtime_pending_hours' => 'Horas extra por aprobar',
        'payable_hours'      => 'Horas a pagar',
        'late_count'         => 'Tardanzas',
        'late_minutes'       => 'Minutos de tardanza',
        'absences'           => 'Faltas injustificadas',
        'excused'            => 'Faltas justificadas',
        'vacation_days'      => 'Días de vacaciones',
        'medical_leave_days' => 'Días de licencia médica',
        'permission_days'    => 'Días de permiso',
        'bank_name'          => 'Banco',
        'bank_account_type'  => 'Tipo de cuenta',
        'currency'           => 'Moneda',
        'account_number'     => 'N° de cuenta',
        'interbank_code'     => 'CCI / CLABE / CBU',
        'payment_status'     => 'Estado datos de pago',
        'pension_system'     => 'Sistema previsional',
        'pension_fund'       => 'AFP / Fondo',
        'pension_code'       => 'CUSPP / NSS',
        'health_insurance'   => 'Seguro de salud',
        'cts_bank_name'      => 'Banco CTS',
        'cts_account_number' => 'Cuenta CTS',
    ];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int $year, int $month, ?int $branchId = null): Collection
    {
        $from = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $to   = $from->endOfMonth();

        $employees = User::query()
            ->whereIn('role', ['employee', 'supervisor'])
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $from->toDateString()))
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->with(['profile', 'tenant:id,company_name'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $ids = $employees->pluck('id');

        $payableOvertime = Attendance::payableOvertimeSql();

        $attendance = Attendance::query()
            ->whereIn('user_id', $ids)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("user_id,
                SUM(CASE WHEN clock_in_at IS NOT NULL THEN 1 ELSE 0 END) AS days_worked,
                COALESCE(SUM(worked_hours), 0) AS worked_hours,
                COALESCE(SUM({$payableOvertime}), 0) AS overtime_hours,
                COALESCE(SUM(CASE WHEN overtime_status = 'pending' THEN overtime_hours ELSE 0 END), 0) AS overtime_pending_hours,
                COALESCE(SUM(COALESCE(worked_hours, 0) - COALESCE(overtime_hours, 0) + {$payableOvertime}), 0) AS payable_hours,
                SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) AS late_count,
                COALESCE(SUM(late_minutes), 0) AS late_minutes,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absences,
                SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) AS excused")
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $leaves = LeaveRequest::query()
            ->whereIn('user_id', $ids)
            ->where('status', 'approved')
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get()
            ->groupBy('user_id');

        return $employees->map(function (User $user) use ($attendance, $leaves, $from, $to) {
            $a = $attendance->get($user->id);
            $p = $user->profile ?? new EmployeeProfile();

            $leaveDays = fn (string $type) => ($leaves->get($user->id) ?? collect())
                ->where('type', $type)
                ->sum(fn (LeaveRequest $l) => $l->daysWithin($from, $to));

            return [
                'tenant_id'          => $user->tenant_id,
                'company'            => $user->tenant?->company_name,
                'employee_code'      => $user->employee_code,
                'document_type'      => strtoupper((string) $user->document_type),
                'document_number'    => $user->document_number,
                'full_name'          => $user->full_name,
                'position'           => $user->position,
                'department'         => $user->department,
                'hire_date'          => $user->hire_date?->toDateString(),
                'labor_regime'       => EmployeeProfile::LABOR_REGIMES[$p->labor_regime] ?? null,
                'base_salary'        => $user->base_salary,
                'bonus'              => $user->bonus,
                'total_salary'       => round((float) $user->base_salary + (float) $user->bonus, 2),
                'days_worked'        => (int) ($a->days_worked ?? 0),
                'worked_hours'       => round((float) ($a->worked_hours ?? 0), 2),
                'overtime_hours'     => round((float) ($a->overtime_hours ?? 0), 2),
                'overtime_pending_hours' => round((float) ($a->overtime_pending_hours ?? 0), 2),
                // Horas reales hasta la jornada base + solo las extra aprobadas
                'payable_hours'      => round((float) ($a->payable_hours ?? 0), 2),
                'late_count'         => (int) ($a->late_count ?? 0),
                'late_minutes'       => (int) ($a->late_minutes ?? 0),
                'absences'           => (int) ($a->absences ?? 0),
                'excused'            => (int) ($a->excused ?? 0),
                'vacation_days'      => $leaveDays('vacation'),
                'medical_leave_days' => $leaveDays('medical_leave'),
                'permission_days'    => $leaveDays('permission'),
                'bank_name'          => $p->bank_name,
                'bank_account_type'  => EmployeeProfile::BANK_ACCOUNT_TYPES[$p->bank_account_type] ?? null,
                'currency'           => $p->bank_account_currency,
                'account_number'     => $p->bank_account_number,
                'interbank_code'     => $p->interbank_code,
                'payment_status'     => EmployeeProfile::PAYMENT_STATUSES[$p->payment_info_status ?? 'incomplete'],
                'pension_system'     => EmployeeProfile::PENSION_SYSTEMS[$p->pension_system] ?? null,
                'pension_fund'       => $p->pension_fund,
                'pension_code'       => $p->pension_code,
                'health_insurance'   => EmployeeProfile::HEALTH_INSURANCES[$p->health_insurance] ?? null,
                'cts_bank_name'      => $p->cts_bank_name,
                'cts_account_number' => $p->cts_account_number,
            ];
        });
    }

    /** CSV UTF-8 con BOM (se abre bien en Excel) */
    public function download(Collection $rows, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values(self::COLUMNS));

            foreach ($rows as $row) {
                // Las cuentas van como texto para que Excel no las convierta a notación científica
                $line = [];
                foreach (array_keys(self::COLUMNS) as $key) {
                    $value = $row[$key] ?? '';

                    if (in_array($key, ['account_number', 'interbank_code', 'cts_account_number', 'document_number', 'pension_code'], true) && $value !== '') {
                        $value = '="' . str_replace('"', '', (string) $value) . '"';
                    } elseif (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
                        // Evita inyección de fórmulas en Excel (CSV injection)
                        $value = "'" . $value;
                    }

                    $line[] = $value;
                }
                fputcsv($out, $line);
            }

            fclose($out);
        }, $fileName, [
            'Content-Type'  => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
