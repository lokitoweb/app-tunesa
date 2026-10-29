<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\SpreadsheetRows;
use App\Modules\Finance\Models\FixedCost;
use App\Modules\Finance\Models\FuelLoad;
use App\Modules\Finance\Models\PaymentCancellation;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\PettyCashExpense;
use App\Modules\Finance\Models\Requisition;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes del sistema anterior (Crystal Reports de sisgere) en Excel, por rango de fechas:
 * cuotas programadas y sin programar, cancelaciones por banco y por orden, anticipos, costos fijos,
 * centro de costos por placa, caja chica, colaboradores y resumen general de requerimientos.
 * Cada uno respeta la empresa elegida (los modelos llevan el filtro de empresa).
 */
class ReportWebController extends Controller
{
    /** clave => [título, descripción, reporte de sisgere, permiso] */
    public const REPORTS = [
        'scheduled'        => ['Pagos programados', 'Cuotas pendientes con fecha de pago, autorizadas o no.', 'CRprogramados / CRprogpagos', 'treasury.view'],
        'unscheduled'      => ['Pagos sin programar', 'Cuotas pendientes que aún no tienen fecha de pago.', 'CRprogpagosreq', 'treasury.view'],
        'payments_by_bank' => ['Cancelaciones por banco', 'Pagos registrados agrupados por la cuenta de origen.', 'CRcancelaciones_banco', 'treasury.view'],
        'payments'         => ['Cancelaciones por requerimiento', 'Cada pago con su orden, cuota, operación y cuenta.', 'CRcancelaciones / CRcancelacionesreq', 'treasury.view'],
        'advances'         => ['Anticipos', 'Anticipos a proveedores y la orden a la que se aplicaron.', 'CRANTICIPOS / cradelantocf', 'treasury.view'],
        'fixed_costs'      => ['Costos fijos', 'Cuotas de costos fijos por clasificación y estado.', 'CRgastofijo', 'treasury.view'],
        'cost_center'      => ['Centro de costos por placa', 'Órdenes y combustible por vehículo.', 'crcentro_c / Crcentroc_gen', 'treasury.view'],
        'petty_cash'       => ['Estadística de caja chica', 'Gastos de caja chica por caja y categoría.', 'CRestcajachica', 'treasury.view'],
        'summary'          => ['Resumen general de requerimientos', 'Requerimientos del periodo con su etapa, orden y montos.', 'CRresumen', 'treasury.view'],
        'employees'        => ['Colaboradores', 'Personal activo con cargo, sede y cuenta del legajo.', 'Crcolaboradores', 'users.manage'],
    ];

    public function index(Request $request): Response
    {
        $reports = collect(self::REPORTS)->filter(fn ($r) => $request->user()->hasPermission($r[3]));
        abort_if($reports->isEmpty(), 403);

        return Inertia::render('Finance/Pages/Reports/Index', [
            'reports' => $reports->map(fn ($r, $key) => ['key' => $key, 'title' => $r[0], 'description' => $r[1], 'legacy' => $r[2]])->values(),
            'from' => now(tenant_timezone())->startOfMonth()->toDateString(),
            'to'   => now(tenant_timezone())->toDateString(),
        ]);
    }

    public function download(Request $request, string $report): BinaryFileResponse
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        abort_unless($request->user()->hasPermission(self::REPORTS[$report][3]), 403);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']],
            ['to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.']);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        [$headers, $rows] = $this->{\Illuminate\Support\Str::camel($report)}($from, $to);
        $path = tempnam(sys_get_temp_dir(), 'rep') . '.xlsx';
        SpreadsheetRows::export($path, self::REPORTS[$report][0], $headers, $rows);

        return response()->download($path, str_replace('_', '-', $report) . "-{$from->format('Ymd')}-{$to->format('Ymd')}.xlsx")->deleteFileAfterSend();
    }

    // ------------------------------------------------------------------ reportes

    private function scheduled(Carbon $from, Carbon $to): array
    {
        return $this->installments(PaymentInstallment::where('status', 'pending')->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [$from->toDateString(), $to->toDateTimeString()])->orderBy('scheduled_for'));
    }

    private function unscheduled(Carbon $from, Carbon $to): array
    {
        return $this->installments(PaymentInstallment::where('status', 'pending')->whereNull('scheduled_for')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateTimeString()])->orderBy('due_date'));
    }

    private function installments($query): array
    {
        return [['Orden', 'Proveedor / beneficiario', 'Detalle', 'Cuota', 'Vence', 'Programada', 'Autorizada', 'Moneda', 'Saldo'],
            $query->with(['requisition.supplier:id,name', 'requisition.employee:id,first_name,last_name'])->limit(10000)->get()->map(fn ($p) => [
                $p->requisition?->orderNumber(), $p->requisition?->supplier?->name ?? $p->requisition?->employee?->full_name, $p->requisition?->detail,
                "{$p->installment} de {$p->requisition?->installments}", $p->due_date?->format('d/m/Y'), $p->scheduled_for?->format('d/m/Y'),
                $p->authorized_at ? 'Sí' : 'No', $p->requisition?->currency, round((float) $p->amount - (float) $p->paid_amount, 2),
            ])];
    }

    private function paymentsQuery(Carbon $from, Carbon $to)
    {
        return PaymentCancellation::whereBetween('paid_at', [$from->toDateString(), $to->toDateTimeString()])
            ->with(['installment.requisition.supplier:id,name', 'installment.requisition.employee:id,first_name,last_name']);
    }

    private function paymentsByBank(Carbon $from, Carbon $to): array
    {
        $rows = $this->paymentsQuery($from, $to)->get()->sortBy(fn ($c) => "{$c->bank} {$c->account_number} {$c->paid_at}");

        return [['Banco', 'Cuenta de origen', 'Fecha', 'Orden', 'Proveedor / beneficiario', 'N.º operación', 'Moneda', 'Monto'],
            $rows->map(function ($c) {
                $r = $c->installment?->requisition;

                return [$c->bank, $c->account_number, $c->paid_at?->format('d/m/Y'), $r?->orderNumber(), $r?->supplier?->name ?? $r?->employee?->full_name,
                    $c->operation_number, $r?->currency, (float) $c->amount];
            })->concat($rows->groupBy(fn ($c) => "{$c->bank} {$c->account_number}|" . $c->installment?->requisition?->currency)
                ->map(fn ($g, $k) => ['TOTAL ' . explode('|', $k)[0], null, null, null, null, null, explode('|', $k)[1], round((float) $g->sum('amount'), 2)])->values())];
    }

    private function payments(Carbon $from, Carbon $to): array
    {
        return [['Fecha', 'Orden', 'Requerimiento', 'Proveedor / beneficiario', 'Cuota', 'Tipo', 'Cuenta de origen', 'N.º operación', 'Registró', 'Moneda', 'Monto'],
            $this->paymentsQuery($from, $to)->orderBy('paid_at')->get()->map(function ($c) {
                $r = $c->installment?->requisition;

                return [$c->paid_at?->format('d/m/Y'), $r?->orderNumber(), $r?->code(), $r?->supplier?->name ?? $r?->employee?->full_name, $c->installment?->installment,
                    $c->is_amortization ? 'Amortización' : 'Cancelación', trim("{$c->bank} {$c->account_number}"), $c->operation_number, $c->registered_by, $r?->currency, (float) $c->amount];
            })];
    }

    private function advances(Carbon $from, Carbon $to): array
    {
        return [['Anticipo', 'Proveedor', 'Detalle', 'Moneda', 'Monto', 'Estado', 'Pagado el', 'Aplicado a'],
            PaymentInstallment::where('is_advance', true)->whereHas('requisition', fn ($q) => $q->whereBetween('ordered_at', [$from, $to]))
                ->with(['requisition.supplier:id,name'])->get()->tap(function ($rows) use (&$appliedTo) {
                    // Las órdenes a las que se aplicaron, en una sola consulta (antes una por fila)
                    $appliedTo = Requisition::whereKey($rows->pluck('advance_applied_to_id')->filter()->unique())->get()->keyBy('id');
                })->map(function ($p) use (&$appliedTo) {
                    $applied = $p->advance_applied_to_id ? $appliedTo->get($p->advance_applied_to_id) : null;

                    return [$p->requisition->code(), $p->requisition->supplier?->name, $p->requisition->detail, $p->requisition->currency, (float) $p->amount,
                        $applied ? 'Aplicado' : ($p->status === 'paid' ? 'Pagado, por aplicar' : 'Por pagar'), $p->paid_at?->format('d/m/Y'), $applied?->orderNumber()];
                })];
    }

    private function fixedCosts(Carbon $from, Carbon $to): array
    {
        return [['Fecha', 'Clasificación', 'A quién se paga', 'Detalle', 'Cuota', 'Moneda', 'Monto', 'Estado', 'Pagado el', 'N.º operación'],
            FixedCost::whereBetween('date', [$from->toDateString(), $to->toDateTimeString()])->orderBy('classification')->orderBy('date')->get()->map(fn ($c) => [
                $c->date->format('d/m/Y'), $c->classification, $c->supplier_name, $c->detail, $c->installment, $c->currency, (float) $c->amount,
                ! $c->is_active ? 'Anulada' : ($c->paid_at ? 'Pagada' : 'Pendiente'), $c->paid_at?->format('d/m/Y'), $c->operation_number,
            ])];
    }

    private function costCenter(Carbon $from, Carbon $to): array
    {
        // Órdenes con placa (sin anuladas ni sin orden) y cargas de combustible, por vehículo y moneda
        $orders = Requisition::whereIn('stage', ['ordered', 'paid'])->whereBetween('ordered_at', [$from, $to])->whereNotNull('plate')->where('plate', '!=', '')
            ->toBase()->selectRaw('upper(plate) as plate, currency, count(*) as n, sum(amount) as total')->groupBy('plate', 'currency')->get();
        $fuel = FuelLoad::whereBetween('date', [$from->toDateString(), $to->toDateTimeString()])->with('vehicle:id,plate')->get()
            ->groupBy(fn ($f) => $f->vehicle?->plate ?? 'SIN PLACA');

        $rows = $orders->map(fn ($o) => [$o->plate, 'Órdenes (OC/OS)', (int) $o->n, null, $o->currency, round((float) $o->total, 2)])
            ->concat($fuel->map(fn ($g, $plate) => [$plate, 'Combustible', $g->count(), round((float) $g->sum('gallons'), 3), 'PEN', round((float) $g->sum('total'), 2)])->values())
            ->sortBy(fn ($r) => "{$r[0]} {$r[1]}")->values();

        return [['Placa', 'Concepto', 'Cantidad', 'Galones', 'Moneda', 'Total'], $rows];
    }

    private function pettyCash(Carbon $from, Carbon $to): array
    {
        return [['Caja', 'Categoría', 'Mes', 'N.º de gastos', 'Total S/'],
            PettyCashExpense::whereBetween('date', [$from->toDateString(), $to->toDateTimeString()])->toBase()
                ->selectRaw('cash_box, category, substr(date, 1, 7) as month, count(*) as n, sum(amount) as total')
                ->groupBy('cash_box', 'category', 'month')->orderBy('cash_box')->orderBy('month')->get()
                ->map(fn ($r) => [$r->cash_box, $r->category ?: 'Sin categoría', $r->month, (int) $r->n, round((float) $r->total, 2)])];
    }

    private function summary(Carbon $from, Carbon $to): array
    {
        return [['Requerimiento', 'Fecha', 'Solicitado por', 'Área', 'Clasificación', 'Detalle', 'Placa', 'Etapa', 'Orden', 'Proveedor', 'Moneda', 'Monto', 'Neto a pagar'],
            Requisition::whereBetween('requested_at', [$from, $to])->with('supplier:id,name')->orderBy('requested_at')->limit(10000)->get()->map(fn ($r) => [
                $r->code(), $r->requested_at?->format('d/m/Y'), $r->requested_by, $r->area, $r->classification, $r->detail, $r->plate,
                Requisition::STAGES[$r->stage][0] ?? $r->stage, $r->orderNumber(), $r->supplier?->name, $r->currency,
                $r->amount !== null ? (float) $r->amount : null, $r->amount_payable !== null ? (float) $r->amount_payable : null,
            ])];
    }

    private function employees(Carbon $from, Carbon $to): array
    {
        // Personal activo (el rango no aplica: es la foto actual, como Crcolaboradores)
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa.');
        // Para inspección: por defecto solo planilla; ?regime=honorarios o ?regime=all para los demás
        $regime = request('regime', 'planilla');
        $regime = $regime === 'all' ? null : (array_key_exists($regime, User::PAYROLL_REGIMES) ? $regime : 'planilla');

        // Con las columnas de Crcolaboradores (CODIGO, DIRECCION, TIPO, CCI); las cuentas, enmascaradas: el archivo sale del sistema
        $last4 = fn (?string $n) => \App\Modules\HR\Models\EmployeeProfile::mask($n);

        return [['DNI', 'Apellidos y nombres', 'Código', 'Régimen', 'Cargo', 'Sede principal', 'Ingreso', 'Dirección', 'Correo', 'Teléfono', 'Banco', 'Tipo de cuenta', 'Cuenta (últimos 4)', 'CCI (últimos 4)', 'Cuenta verificada'],
            User::where('tenant_id', current_tenant_id_or_null())->where('status', 'active')->whereNotIn('role', ['super_admin'])->regime($regime)
                ->with(['profile', 'branches'])->orderBy('last_name')->limit(10000)->get()->map(fn ($u) => [
                    $u->document_number, "{$u->last_name}, {$u->first_name}", $u->employee_code, User::PAYROLL_REGIMES[$u->isFeeEarner() ? 'honorarios' : 'planilla'],
                    $u->position, $u->branches->firstWhere('pivot.is_primary', true)?->name,
                    $u->hire_date?->format('d/m/Y'), $u->profile?->address, $u->email, $u->phone, $u->profile?->bank_name, $u->profile?->bank_account_type,
                    $last4($u->profile?->bank_account_number), $last4($u->profile?->interbank_code),
                    $u->profile?->payment_info_status === 'verified' ? 'Sí' : 'No',
                ])];
    }
}
