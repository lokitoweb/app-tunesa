<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Finance\Models\FixedCost;
use App\Modules\Finance\Models\PaymentCancellation;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\PettyCashExpense;
use App\Modules\Finance\Models\Requisition;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flujo de caja (MOSTRAR_FLUJO_EFECTIVO del ERP de escritorio): egresos por
 * mes y concepto. Lo real son los pagos registrados; lo proyectado, lo que
 * vence y aún no se paga. Una moneda a la vez: no se suman soles con dólares.
 */
class CashFlowWebController extends Controller
{
    public function index(Request $request): Response
    {
        $currency = array_key_exists($request->query('currency'), Requisition::CURRENCIES) ? $request->query('currency') : 'PEN';
        $from = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('from')) ? Carbon::parse($request->query('from') . '-01') : now(tenant_timezone())->startOfMonth()->subMonths(2);
        $months = collect(range(0, 5))->map(fn ($i) => $from->copy()->addMonths($i)->format('Y-m'));
        $start = $from->toDateString();
        $end = $from->copy()->addMonths(5)->endOfMonth()->toDateTimeString(); // con hora: SQLite guarda las fechas con hora
        $today = now(tenant_timezone())->toDateString();

        // concepto => [mes => monto]. Se suma en la base (GROUP BY concepto, mes) y no fila por fila
        // en PHP: con miles de cuotas el flujo superaba 2 s por petición bajo carga (k6).
        // substr(fecha, 1, 7) = 'YYYY-MM' en SQLite y MySQL. Los modelos mantienen el filtro de empresa.
        $actual = [];
        $projected = [];
        $collect = function (array &$bucket, $rows, string $prefix) {
            foreach ($rows as $row) {
                $concept = $prefix . ' · ' . ($row->concept ?: 'Otros');
                $bucket[$concept][$row->month] = round(($bucket[$concept][$row->month] ?? 0) + (float) $row->total, 2);
            }
        };
        $byRequisition = fn ($query) => $query->join('requisitions', 'requisitions.id', '=', 'payment_installments.requisition_id')
            ->whereNull('requisitions.deleted_at')->where('requisitions.currency', $currency);

        $collect($actual, $byRequisition(PaymentCancellation::query()->join('payment_installments', 'payment_installments.id', '=', 'payment_cancellations.payment_installment_id'))
            ->whereBetween('payment_cancellations.paid_at', [$start, $end])
            ->selectRaw('requisitions.classification as concept, substr(payment_cancellations.paid_at, 1, 7) as month, sum(payment_cancellations.amount) as total')
            ->groupBy('concept', 'month')->toBase()->get(), 'Proveedores');

        $collect($projected, $byRequisition(PaymentInstallment::query())->where('payment_installments.status', 'pending')
            ->whereBetween('payment_installments.due_date', [$start, $end])
            ->selectRaw('requisitions.classification as concept, substr(payment_installments.due_date, 1, 7) as month, sum(payment_installments.amount - payment_installments.paid_amount) as total')
            ->groupBy('concept', 'month')->toBase()->get(), 'Proveedores');

        $fixed = fn () => FixedCost::where('is_active', true)->where('currency', $currency);
        $collect($actual, $fixed()->whereBetween('paid_at', [$start, $end])
            ->selectRaw('classification as concept, substr(paid_at, 1, 7) as month, sum(amount) as total')->groupBy('concept', 'month')->toBase()->get(), 'Costos fijos');
        $collect($projected, $fixed()->whereNull('paid_at')->whereBetween('date', [$start, $end])
            ->selectRaw('classification as concept, substr(date, 1, 7) as month, sum(amount) as total')->groupBy('concept', 'month')->toBase()->get(), 'Costos fijos');

        if ($currency === 'PEN') { // caja chica siempre en soles
            $collect($actual, PettyCashExpense::whereBetween('date', [$start, $end])
                ->selectRaw('cash_box as concept, substr(date, 1, 7) as month, sum(amount) as total')->groupBy('concept', 'month')->toBase()->get(), 'Caja chica');
        }

        $overdue = (float) $byRequisition(PaymentInstallment::query())->where('payment_installments.status', 'pending')
            ->where('payment_installments.due_date', '<', $today)->sum(\Illuminate\Support\Facades\DB::raw('payment_installments.amount - payment_installments.paid_amount'))
            + (float) $fixed()->whereNull('paid_at')->where('date', '<', $today)->sum('amount');

        $table = fn (array $bucket) => collect($bucket)->sortKeys()->map(fn ($byMonth, $concept) => [
            'concept' => $concept,
            'months'  => $months->mapWithKeys(fn ($m) => [$m => $byMonth[$m] ?? 0]),
            'total'   => round(array_sum($byMonth), 2),
        ])->values();

        return Inertia::render('Finance/Pages/CashFlow/Index', [
            'months'     => $months->map(fn ($m) => ['key' => $m, 'label' => ucfirst(Carbon::parse("{$m}-01")->locale('es')->isoFormat('MMM YYYY')), 'is_current' => $m === now(tenant_timezone())->format('Y-m')]),
            'actual'     => $table($actual),
            'projected'  => $table($projected),
            'overdue'    => round($overdue, 2),
            'filters'    => ['currency' => $currency, 'from' => $from->format('Y-m')],
            'currencies' => Requisition::CURRENCIES,
        ]);
    }
}
