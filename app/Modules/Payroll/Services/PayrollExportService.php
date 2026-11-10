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
        'early_exit_minutes' => 'Minutos de salida anticipada',
        'justified_minutes_paid'   => 'Min. justificados con goce',
        'justified_minutes_unpaid' => 'Min. justificados sin goce',
        'absences'           => 'Faltas injustificadas',
        'excused_paid'       => 'Faltas justificadas con goce',
        'excused_unpaid'     => 'Faltas justificadas sin goce',
        'vacation_days'      => 'Días de vacaciones',
        'medical_leave_days' => 'Días de licencia médica',
        'permission_paid_days'   => 'Días de permiso con goce',
        'permission_unpaid_days' => 'Días de permiso sin goce',
        'unclassified'       => 'Justificaciones sin clasificar (revisar)',
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
            // Recibo por honorarios no va en planilla
            ->regime('planilla')
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $from->toDateString()))
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->with(['profile', 'tenant:id,company_name'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $ids = $employees->pluck('id');

        $payableOvertime = Attendance::payableOvertimeSql();
        // Justificación aprobada por RR. HH. (sus minutos o el día salen de tardanzas/faltas y van a su columna con / sin goce)
        $justified = "(justification_status = 'approved')";

        $attendance = Attendance::query()
            ->whereIn('user_id', $ids)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("user_id,
                SUM(CASE WHEN clock_in_at IS NOT NULL THEN 1 ELSE 0 END) AS days_worked,
                COALESCE(SUM(worked_hours), 0) AS worked_hours,
                COALESCE(SUM({$payableOvertime}), 0) AS overtime_hours,
                COALESCE(SUM(CASE WHEN overtime_status = 'pending' THEN overtime_hours ELSE 0 END), 0) AS overtime_pending_hours,
                COALESCE(SUM(COALESCE(worked_hours, 0) - COALESCE(overtime_hours, 0) + {$payableOvertime}), 0) AS payable_hours,
                SUM(CASE WHEN late_minutes > 0 AND NOT {$justified} THEN 1 ELSE 0 END) AS late_count,
                COALESCE(SUM(CASE WHEN {$justified} THEN 0 ELSE late_minutes END), 0) AS late_minutes,
                COALESCE(SUM(CASE WHEN {$justified} THEN 0 ELSE early_exit_minutes END), 0) AS early_exit_minutes,
                COALESCE(SUM(CASE WHEN {$justified} AND clock_in_at IS NOT NULL AND justification_paid = 1 THEN late_minutes + early_exit_minutes ELSE 0 END), 0) AS justified_minutes_paid,
                COALESCE(SUM(CASE WHEN {$justified} AND clock_in_at IS NOT NULL AND justification_paid = 0 THEN late_minutes + early_exit_minutes ELSE 0 END), 0) AS justified_minutes_unpaid,
                SUM(CASE WHEN status = 'absent' AND NOT {$justified} THEN 1 ELSE 0 END) AS absences,
                SUM(CASE WHEN clock_in_at IS NULL AND {$justified} AND justification_paid = 1 THEN 1 ELSE 0 END) AS excused_paid,
                SUM(CASE WHEN clock_in_at IS NULL AND {$justified} AND justification_paid = 0 THEN 1 ELSE 0 END) AS excused_unpaid,
                SUM(CASE WHEN {$justified} AND justification_paid IS NULL AND (clock_in_at IS NULL OR late_minutes + early_exit_minutes > 0) THEN 1 ELSE 0 END) AS unclassified")
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

            $leaveDays = fn (string $type, ?bool $withPay = null, bool $anyPay = true) => ($leaves->get($user->id) ?? collect())
                ->where('type', $type)
                ->filter(fn (LeaveRequest $l) => $anyPay || $l->with_pay === $withPay)
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
                'labor_regime'       => \App\Modules\HR\Models\LaborRegime::label((int) $user->tenant_id, $p->labor_regime),
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
                'early_exit_minutes' => (int) ($a->early_exit_minutes ?? 0),
                'justified_minutes_paid'   => (int) ($a->justified_minutes_paid ?? 0),
                'justified_minutes_unpaid' => (int) ($a->justified_minutes_unpaid ?? 0),
                'absences'           => (int) ($a->absences ?? 0),
                'excused_paid'       => (int) ($a->excused_paid ?? 0),
                'excused_unpaid'     => (int) ($a->excused_unpaid ?? 0),
                'vacation_days'      => $leaveDays('vacation'),
                'medical_leave_days' => $leaveDays('medical_leave'),
                'permission_paid_days'   => $leaveDays('permission', true, false),
                'permission_unpaid_days' => $leaveDays('permission', false, false),
                'unclassified'       => round((int) ($a->unclassified ?? 0) + (float) $leaveDays('permission', null, false), 2),
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
