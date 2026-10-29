<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Core\Support\SpreadsheetRows;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Services\PayrollPaymentIssuer;
use App\Modules\Finance\Services\PettyCashImport;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Pagos de RRHH (FrmIplanilla + FrmRegrrhh). La planilla del periodo se importa desde Excel
 * y cada colaborador (por DNI) queda como una orden de pago PL-… en Tesorería: misma
 * programación, autorización de la persona designada, voucher y amortización que el resto.
 * Se paga a la cuenta verificada de su legajo (la CTS para el concepto CTS).
 */
class PayrollPaymentWebController extends Controller
{
    public const MAX_ROWS = 2000;

    public const COLUMNS = [
        'DNI'                 => ['dni', ['dni', 'documento']],
        'Colaborador'         => ['name', ['colaborador', 'nombre', 'trabajador']],
        'Monto neto a pagar'  => ['amount', ['monto', 'neto', 'importe']],
    ];

    public function __construct(private readonly AuditLogger $audit, private readonly PayrollPaymentIssuer $issuer) {}

    public function index(Request $request): Response
    {
        $period = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('period')) ? $request->query('period') : now(tenant_timezone())->format('Y-m');

        $user = $request->user();
        // Cuenta completa para quien opera con ella, enmascarada para el resto (misma regla que Tesorería)
        $mask = fn (?string $n) => EmployeeProfile::accountFor($n, $user);
        $payments = Requisition::with(['employee:id,first_name,last_name,document_number,position,scope_branch_id', 'employee.profile',
            'employee.primaryBranch:branches.id,name,city', 'employee.assignedBranch:id,name,city',
            'paymentInstallments:id,requisition_id,installment,amount,paid_amount,status,scheduled_for,authorized_at,paid_at'])
            ->whereNotNull('payroll_concept')->where('payroll_period', $period)
            ->orderBy('payroll_concept')->orderBy('id')->get()
            ->map(function (Requisition $r) use ($mask) {
                $p = $r->paymentInstallments->first();
                $account = $r->payrollAccount();
                $branch = $r->employee?->primaryBranch->first() ?? $r->employee?->assignedBranch;

                // Columnas de sisgere FrmRegrrhh › Planilla: # Operación, Colaborador, Orden Pago, F. Prog, F. Pago, Ciudad, Cargo, Monto a depositar, Banco, CCI
                return [
                    'id' => $r->id, 'code' => $r->code(), 'concept' => $r->payroll_concept, 'amount' => (float) $r->amount,
                    'employee' => $r->employee?->full_name, 'dni' => $r->employee?->document_number,
                    'order' => $p?->scheduled_for ? sprintf('OP-%s-%02d', $r->orderNumber(), $p->installment) : null, 'installment_id' => $p?->id,
                    'scheduled_for' => $p?->scheduled_for?->format('d/m/Y'), 'paid_at' => $p?->paid_at?->format('d/m/Y'),
                    'city' => $branch?->city ?: $branch?->name, 'position' => $r->employee?->position,
                    'bank' => $account['bank'], 'cci' => $mask($account['cci'] ?: $account['number']),
                    'account' => $account['number'] ? trim($account['bank'] . ' ' . EmployeeProfile::mask($account['number'])) : null, 'verified' => $account['verified'],
                    'paid' => (float) ($p?->paid_amount ?? 0),
                    'status' => match (true) {
                        $r->stage === 'paid' => 'Pagado',
                        (float) ($p?->paid_amount ?? 0) > 0 => 'Amortizado',
                        (bool) $p?->authorized_at => 'Autorizado',
                        (bool) $p?->scheduled_for => 'Programado',
                        default => 'Por programar',
                    },
                ];
            });

        // Como el ERP de escritorio: activos que no están en la planilla de haberes del periodo (posibles bajas)
        $missing = $payments->where('concept', 'HABERES')->isEmpty() ? collect() : User::where('status', 'active')
            ->whereNotIn('role', ['super_admin'])->whereNotNull('document_number')
            ->whereNotIn('id', Requisition::where('payroll_period', $period)->where('payroll_concept', 'HABERES')->select('employee_id'))
            ->orderBy('first_name')->limit(100)->get(['id', 'first_name', 'last_name', 'document_number'])
            ->map(fn (User $u) => ['name' => $u->full_name, 'dni' => $u->document_number]);

        return Inertia::render('Finance/Pages/PayrollPayments/Index', [
            'period'   => $period,
            'payments' => $payments,
            // Cabecera de FrmRegrrhh: NRO COLABORADORES, SUELDO ACUMULADO y SUELDOS A PAGAR (lo que aún falta pagar)
            'summary'  => ['employees' => $payments->pluck('dni')->unique()->count(), 'accumulated' => round($payments->sum('amount'), 2),
                'to_pay' => round($payments->where('status', '!=', 'Pagado')->sum(fn ($p) => $p['amount'] - $p['paid']), 2)],
            'totals'   => $payments->groupBy('concept')->map(fn ($g) => ['count' => $g->count(), 'total' => round($g->sum('amount'), 2), 'paid' => round($g->sum('paid'), 2)]),
            'missing'  => $missing,
            'concepts' => Requisition::PAYROLL_CONCEPTS,
            'periods'  => Requisition::whereNotNull('payroll_concept')->distinct()->orderByDesc('payroll_period')->limit(24)->pluck('payroll_period'),
            'today'    => now(tenant_timezone())->toDateString(),
            'can'      => ['employees' => $user->hasPermission('users.manage'), 'requisitions' => $user->hasPermission('requisitions.create'), 'treasury' => $user->hasPermission('treasury.view')],
        ]);
    }

    /** Importa la planilla del periodo: todo o nada, colaboradores por DNI, sin pagar dos veces */
    public function import(Request $request): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getOrNull();
        abort_unless($tenant, 422, 'Elige una empresa.');
        $data = $request->validate([
            'file'     => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
            'period'   => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'concept'  => ['required', Rule::in(array_keys(Requisition::PAYROLL_CONCEPTS))],
            'due_date' => ['required', 'date'],
        ], ['file.mimes' => 'Sube la planilla en Excel (.xlsx, .xls) o CSV.', 'period.regex' => 'El periodo no es válido.'],
            ['file' => 'el archivo', 'period' => 'el periodo', 'concept' => 'el concepto', 'due_date' => 'la fecha de pago']);

        try {
            $rows = SpreadsheetRows::read($request->file('file')->getRealPath(), self::COLUMNS);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo. Usa la plantilla.']);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'La planilla no tiene filas.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'Máximo ' . self::MAX_ROWS . ' filas por archivo.']);
        }

        // Colaboradores por DNI y pagos ya emitidos del periodo/concepto, en dos consultas
        $dnis = array_map(fn ($r) => trim((string) ($r['dni'] ?? '')), $rows);
        $employees = User::with(['profile', 'primaryBranch', 'assignedBranch'])->whereIn('document_number', array_filter($dnis))->get()->keyBy('document_number');
        // withTrashed: el índice único (empresa, colaborador, periodo, concepto) también cuenta los eliminados
        $already = Requisition::withTrashed()->where(['payroll_period' => $data['period'], 'payroll_concept' => $data['concept']])->pluck('employee_id')->flip();
        $liquidation = $data['concept'] === 'LIQUIDACION';

        $errors = [];
        $seen = [];
        $valid = [];
        foreach ($rows as $line => $row) {
            $dni = trim((string) ($row['dni'] ?? ''));
            $amount = PettyCashImport::amount($row['amount'] ?? null);
            $employee = $employees[$dni] ?? null;
            $error = match (true) {
                ! preg_match('/^\d{8}$/', $dni)                       => "el DNI «{$dni}» debe tener 8 dígitos",
                isset($seen[$dni])                                   => "el DNI {$dni} está repetido en la planilla",
                ! $employee                                          => "no hay un colaborador con DNI {$dni}: regístralo primero en Colaboradores",
                ! $liquidation && $employee->status !== 'active'     => "{$employee->full_name} no está activo (usa el concepto Liquidación)",
                ! is_numeric($amount) || (float) $amount <= 0        => 'el monto debe ser mayor a cero',
                isset($already[$employee->id])                       => "{$employee->full_name} ya tiene este pago en el periodo",
                default                                              => null,
            };
            $error ??= $this->issuer->accountError($employee, $data['concept']);
            $seen[$dni] = true;
            if ($error) {
                $errors[] = "Fila {$line}: {$error}.";
                if (count($errors) >= 20) {
                    break;
                }
                continue;
            }
            $valid[] = [$employee, round((float) $amount, 2)];
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => implode("\n", $errors)]); // Inertia envía un mensaje por campo
        }

        $hr = $request->user();
        $label = Requisition::PAYROLL_CONCEPTS[$data['concept']];
        $total = DB::transaction(function () use ($valid, $tenant, $data, $hr) {
            $sum = 0;
            foreach ($valid as [$employee, $amount]) {
                $this->issuer->issue($tenant, $employee, $amount, $data['period'], $data['concept'], $data['due_date'], $hr);
                $sum += $amount;
            }

            return round($sum, 2);
        });
        $this->audit->log('payroll.imported', null, ['period' => $data['period'], 'concept' => $data['concept'], 'rows' => count($valid), 'total' => $total]);

        return redirect()->route('payroll-payments.index', ['period' => $data['period']])
            ->with('success', count($valid) . " pagos de {$label} enviados a Tesorería por S/ " . number_format($total, 2) . '.');
    }

    public function template(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'pl') . '.xlsx';
        SpreadsheetRows::template($path, 'Planilla', self::COLUMNS, ['45871236', 'Ana Ruiz Pérez', 2350.75]);

        return response()->download($path, 'plantilla-planilla.xlsx')->deleteFileAfterSend();
    }
}
