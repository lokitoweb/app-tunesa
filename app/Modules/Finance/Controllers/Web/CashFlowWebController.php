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

        // concepto => [mes => monto]; ponytail: agrupado en PHP (SQLite y MySQL), volumen de meses acotado
        $actual = [];
        $projected = [];
        $add = function (array &$bucket, string $concept, string $month, float $amount) {
            $bucket[$concept][$month] = round(($bucket[$concept][$month] ?? 0) + $amount, 2);
        };

        // foreach y no ->each(fn): las arrow functions capturan $actual por valor y no lo llenarían
        $payments = PaymentCancellation::with('installment.requisition:id,currency,classification')->whereBetween('paid_at', [$start, $end])->get();
        foreach ($payments as $c) {
            $r = $c->installment?->requisition;
            if ($r?->currency === $currency) {
                $add($actual, 'Proveedores · ' . ($r->classification ?: 'Otros'), $c->paid_at->format('Y-m'), (float) $c->amount);
            }
        }

        $pending = PaymentInstallment::with('requisition:id,currency,classification')->where('status', 'pending')->whereBetween('due_date', [$start, $end])->get();
        foreach ($pending as $p) {
            if ($p->requisition?->currency === $currency) {
                $add($projected, 'Proveedores · ' . ($p->requisition->classification ?: 'Otros'), $p->due_date->format('Y-m'), (float) $p->amount - (float) $p->paid_amount);
            }
        }

        $fixed = FixedCost::where('is_active', true)->where('currency', $currency)
            ->where(fn ($q) => $q->whereBetween('paid_at', [$start, $end])->orWhere(fn ($w) => $w->whereNull('paid_at')->whereBetween('date', [$start, $end])))
            ->get();
        foreach ($fixed as $c) {
            if ($c->paid_at) {
                $add($actual, 'Costos fijos · ' . $c->classification, $c->paid_at->format('Y-m'), (float) $c->amount);
            } else {
                $add($projected, 'Costos fijos · ' . $c->classification, $c->date->format('Y-m'), (float) $c->amount);
            }
        }

        if ($currency === 'PEN') { // caja chica siempre en soles
            foreach (PettyCashExpense::whereBetween('date', [$start, $end])->get() as $e) {
                $add($actual, 'Caja chica · ' . $e->cash_box, $e->date->format('Y-m'), (float) $e->amount);
            }
        }

        $overdue = PaymentInstallment::with('requisition:id,currency')->where('status', 'pending')->where('due_date', '<', $today)->get()
            ->filter(fn ($p) => $p->requisition?->currency === $currency)->sum(fn ($p) => (float) $p->amount - (float) $p->paid_amount)
            + (float) FixedCost::where('is_active', true)->where('currency', $currency)->whereNull('paid_at')->where('date', '<', $today)->sum('amount');

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
