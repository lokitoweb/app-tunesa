<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\Requisition;

/**
 * Orden de pago de RR. HH. (PL-…) a Tesorería para un colaborador: misma programación,
 * autorización, voucher y amortización que el resto. La usan la importación de planilla y la
 * liquidación al cese. Se paga a la cuenta verificada del legajo (la CTS para el concepto CTS).
 */
class PayrollPaymentIssuer
{
    /** Por qué no se le puede pagar (sin cuenta o sin verificar), o null si se puede */
    public function accountError(User $employee, string $concept): ?string
    {
        $probe = new Requisition(['payroll_concept' => $concept]);
        $probe->setRelation('employee', $employee);
        $account = $probe->payrollAccount();

        return match (true) {
            ! $account['number']   => "{$employee->full_name} no tiene cuenta " . ($concept === 'CTS' ? 'CTS ' : '') . 'en su legajo',
            ! $account['verified'] => "la cuenta del legajo de {$employee->full_name} no está verificada por RRHH",
            default                => null,
        };
    }

    /** Ya tiene ese pago en el periodo (cuenta los eliminados: el índice único también) */
    public function exists(User $employee, string $period, string $concept): bool
    {
        return Requisition::withTrashed()->where(['employee_id' => $employee->id, 'payroll_period' => $period, 'payroll_concept' => $concept])->exists();
    }

    /** Llamar dentro de una transacción */
    public function issue(Tenant $tenant, User $employee, float $amount, string $period, string $concept, string $dueDate, User $hr): Requisition
    {
        [$year, $month] = explode('-', $period);
        $label = Requisition::PAYROLL_CONCEPTS[$concept];
        $branch = $employee->primaryBranch->first() ?? $employee->assignedBranch;
        // ponytail: un DocumentSequence::next por pago (bloqueo corto); reservar el rango de golpe si planillas de miles tardan
        $series = DocumentSequence::seriesFrom($branch?->document_series ?: $branch?->code);
        [$number, $code] = DocumentSequence::next($tenant, 'PL', $series, (int) now()->year);

        $r = Requisition::create([
            'series' => $series, 'number' => $number, 'code' => $code, 'year' => (int) now()->year,
            'requested_at' => now(), 'detail' => "{$label} correspondiente al periodo {$month}-{$year}", 'area' => 'RRHH',
            'requested_by' => $hr->full_name, 'user_id' => $hr->id, 'classification' => 'GASTOS FIJOS', 'priority' => 'NORMAL', 'cash_flow_concept' => config('finance.cash_flow_defaults.payroll'),
            'stage' => 'ordered', 'ordered_at' => now(), 'reviewed_by' => $hr->full_name, 'reviewed_by_id' => $hr->id, 'reviewed_at' => now(),
            'employee_id' => $employee->id, 'payroll_period' => $period, 'payroll_concept' => $concept,
            'amount' => $amount, 'amount_payable' => $amount, 'currency' => 'PEN', 'installments' => 1,
            'payment_terms' => 'CONTADO', 'payment_method' => 'TRANSFERENCIA', 'payment_origin' => 'TRANSFERENCIA',
        ]);
        $r->paymentInstallments()->create([
            'tenant_id' => $r->tenant_id, 'installment' => 1, 'due_date' => $dueDate, 'amount' => $amount,
            'status' => 'pending', 'payment_method' => 'TRANSFERENCIA', 'registered_by' => $hr->full_name,
            // sisgere FrmRegrrhh: la planilla queda programada al generarse (generar_programacion con Now)
            'scheduled_for' => now(tenant_timezone())->toDateString(), 'scheduled_by' => $hr->full_name,
        ]);

        return $r;
    }
}
