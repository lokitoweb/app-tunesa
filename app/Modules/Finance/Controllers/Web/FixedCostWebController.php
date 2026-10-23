<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\FixedCost;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Costos fijos (Frmcostos del ERP de escritorio): arrendamientos, leasing,
 * seguros, préstamos... Un registro genera N cuotas mensuales del mismo lote;
 * cada cuota se ajusta, anula o paga por separado.
 */
class FixedCostWebController extends Controller
{
    private const MESSAGES = [
        'required'        => 'Falta :attribute.',
        'numeric'         => ':Attribute debe ser un número.',
        'integer'         => ':Attribute debe ser un número entero.',
        'min'             => ':Attribute es muy bajo.',
        'max'             => ':Attribute es muy alto o muy largo.',
        'date'            => ':Attribute no es una fecha válida.',
        'in'              => ':Attribute no es válido.',
        'exists'          => ':Attribute no es válido.',
        'before_or_equal' => 'La fecha de pago no puede ser futura.',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : now(tenant_timezone())->format('Y-m');
        $class = in_array($request->query('classification'), FixedCost::CLASSIFICATIONS, true) ? $request->query('classification') : null;
        $start = Carbon::parse("{$month}-01");
        $query = fn () => FixedCost::whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateTimeString()]) // fin del día: SQLite guarda la fecha con hora
            ->when($class, fn ($q) => $q->where('classification', $class));

        $rows = $query()->with('companyAccount:id,bank,account_number,currency,alias')->orderBy('date')->orderBy('id')->get();
        $sum = fn ($items) => $items->groupBy('currency')->map(fn ($g) => round((float) $g->sum('amount'), 2));
        $active = $rows->where('is_active', true);
        $ofBatch = FixedCost::whereIn('batch', $rows->pluck('batch')->unique())->toBase()
            ->selectRaw('batch, count(*) as total')->groupBy('batch')->pluck('total', 'batch');

        return Inertia::render('Finance/Pages/FixedCosts/Index', [
            'costs'      => $rows->map(fn (FixedCost $c) => [
                ...$c->only(['id', 'classification', 'supplier_name', 'bank', 'account_number', 'detail', 'currency', 'installment', 'batch', 'is_active', 'operation_number']),
                'amount'   => (float) $c->amount,
                'date'     => $c->date->toDateString(),
                'of'       => (int) ($ofBatch[$c->batch] ?? 1),
                'paid_at'  => $c->paid_at?->format('d/m/Y'),
                'account'  => $c->companyAccount?->label(),
            ]),
            'totals'     => [
                'all'     => $sum($active),
                'paid'    => $sum($active->whereNotNull('paid_at')),
                'pending' => $sum($active->whereNull('paid_at')),
            ],
            'filters'    => ['month' => $month, 'classification' => $class],
            'options'    => ['classifications' => FixedCost::CLASSIFICATIONS, 'currencies' => Requisition::CURRENCIES],
            'suppliers'  => Supplier::where('is_active', true)->orderBy('name')->limit(1000)->get(['id', 'name']),
            'accounts'   => CompanyBankAccount::where('is_active', true)->orderBy('bank')->get()->map(fn ($a) => ['id' => $a->id, 'label' => $a->label()]),
            'today'      => now(tenant_timezone())->toDateString(),
        ]);
    }

    /** Registra el costo y genera sus cuotas mensuales (DateAdd Month del ERP) */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa.');
        $data = $request->validate([
            'classification' => ['required', Rule::in(FixedCost::CLASSIFICATIONS)],
            'supplier_id'    => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', current_tenant_id_or_null())->whereNull('deleted_at')],
            'supplier_name'  => ['required_without:supplier_id', 'nullable', 'string', 'max:250'],
            'bank'           => ['nullable', 'string', 'max:50'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'detail'         => ['required', 'string', 'max:250'],
            'date'           => ['required', 'date'],
            'amount'         => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'currency'       => ['required', Rule::in(array_keys(Requisition::CURRENCIES))],
            'installments'   => ['required', 'integer', 'min:1', 'max:60'],
        ], self::MESSAGES + ['supplier_name.required_without' => 'Elige un proveedor o escribe a quién se paga.'], [
            'classification' => 'la clasificación', 'detail' => 'el detalle', 'date' => 'la primera fecha', 'amount' => 'el monto por cuota',
            'installments' => 'el número de cuotas',
        ]);

        if ($data['supplier_id'] ?? null) {
            $data['supplier_name'] = Supplier::find($data['supplier_id'])->name;
        }

        $batch = DB::transaction(function () use ($data) {
            $batch = (int) FixedCost::lockForUpdate()->max('batch') + 1;
            $first = Carbon::parse($data['date']);
            for ($i = 0; $i < (int) $data['installments']; $i++) {
                $date = $first->copy()->addMonthsNoOverflow($i);
                FixedCost::create(array_merge(collect($data)->except('installments')->all(), [
                    'date' => $date->toDateString(), 'period' => $date->format('Y-m'), 'installment' => $i + 1,
                    'frequency' => 30, 'batch' => $batch, 'is_active' => true,
                ]));
            }

            return $batch;
        });
        $this->audit->log('fixed_cost.created', null, ['batch' => $batch, 'detail' => $data['detail'], 'installments' => $data['installments']]);

        return back()->with('success', $data['installments'] > 1 ? "Costo fijo registrado con {$data['installments']} cuotas mensuales." : 'Costo fijo registrado.');
    }

    /** Ajusta una cuota (ACTUALIZAR_cf) */
    public function update(Request $request, int $id): RedirectResponse
    {
        $cost = $this->unpaid($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'date'   => ['required', 'date'],
            'detail' => ['required', 'string', 'max:250'],
        ], self::MESSAGES, ['amount' => 'el monto', 'date' => 'la fecha', 'detail' => 'el detalle']);

        $cost->update($data + ['period' => Carbon::parse($data['date'])->format('Y-m')]);
        $this->audit->log('fixed_cost.updated', $cost, ['batch' => $cost->batch, 'installment' => $cost->installment]);

        return back()->with('success', 'Cuota actualizada.');
    }

    /** Anula la cuota o las que faltan del lote (ANULAR_COSTOFIJO) */
    public function cancel(Request $request, int $id): RedirectResponse
    {
        $cost = $this->unpaid($id);
        $rest = $request->boolean('rest');

        $count = FixedCost::where('batch', $cost->batch)->whereNull('paid_at')->where('is_active', true)
            ->when($rest, fn ($q) => $q->where('installment', '>=', $cost->installment), fn ($q) => $q->whereKey($cost->id))
            ->update(['is_active' => false]);
        $this->audit->log('fixed_cost.cancelled', $cost, ['batch' => $cost->batch, 'from' => $cost->installment, 'count' => $count]);

        return back()->with('success', $count > 1 ? "{$count} cuotas anuladas." : 'Cuota anulada.');
    }

    public function pay(Request $request, int $id): RedirectResponse
    {
        $cost = $this->unpaid($id);
        abort_unless($cost->is_active, 422, 'La cuota está anulada.');
        $data = $request->validate([
            'paid_at'                 => ['required', 'date', 'before_or_equal:today'],
            'company_bank_account_id' => ['required', Rule::exists('company_bank_accounts', 'id')->where('tenant_id', $cost->tenant_id)->where('is_active', true)],
            'operation_number'        => ['required', 'string', 'max:50'],
        ], self::MESSAGES, ['paid_at' => 'la fecha de pago', 'company_bank_account_id' => 'la cuenta de origen', 'operation_number' => 'el número de operación']);

        $cost->update($data);
        $this->audit->log('fixed_cost.paid', $cost, ['batch' => $cost->batch, 'installment' => $cost->installment, 'operation' => $data['operation_number']]);

        return back()->with('success', 'Cuota de costo fijo pagada.');
    }

    private function unpaid(int $id): FixedCost
    {
        $cost = FixedCost::findOrFail($id);
        abort_if($cost->paid_at, 422, 'La cuota ya está pagada.');

        return $cost;
    }
}
