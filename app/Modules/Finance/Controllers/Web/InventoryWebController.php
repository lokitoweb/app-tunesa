<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Finance\Models\Product;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\StockMovement;
use App\Modules\Finance\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Almacén (el almacén es la sede): stock por producto, kardex valorizado por costo promedio,
 * entradas al recibir una OC y entradas/salidas manuales (consumo por vehículo).
 */
class InventoryWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $branchId = $request->integer('branch') ?: null;
        $search = trim((string) $request->query('search'));

        // Saldo vigente = último movimiento de cada producto/almacén (una sola consulta)
        $latest = StockMovement::query()->selectRaw('max(id)')->groupBy('product_id', 'branch_id');
        $stock = StockMovement::with(['product:id,name,unit', 'branch:id,name'])->whereIn('id', $latest)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($search !== '', fn ($q) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")))
            ->get()->sortBy(fn ($m) => $m->product?->name)->values()
            ->map(fn (StockMovement $m) => [
                'product_id' => $m->product_id, 'product' => $m->product?->name, 'unit' => $m->product?->unit,
                'branch_id' => $m->branch_id, 'branch' => $m->branch?->name, 'quantity' => (float) $m->balance_quantity,
                'unit_cost' => (float) $m->balance_unit_cost, 'total' => (float) $m->balance_total, 'last_date' => $m->date?->format('d/m/Y'),
            ]);

        $kardex = null;
        if ($request->filled(['product', 'branch'])) {
            $kardex = StockMovement::with(['requisition:id,code,series,number,purchase_order_number', 'vehicle:id,plate', 'user:id,first_name,last_name'])
                ->where(['product_id' => $request->integer('product'), 'branch_id' => $branchId])->orderBy('id')->limit(500)->get()
                ->map(fn (StockMovement $m) => [
                    ...$m->only(['id', 'type', 'reference', 'notes']), 'date' => $m->date?->format('d/m/Y'),
                    'quantity' => (float) $m->quantity, 'unit_cost' => (float) $m->unit_cost, 'total_cost' => (float) $m->total_cost,
                    'balance_quantity' => (float) $m->balance_quantity, 'balance_unit_cost' => (float) $m->balance_unit_cost, 'balance_total' => (float) $m->balance_total,
                    'order' => $m->requisition?->purchase_order_number, 'requisition_id' => $m->requisition_id, 'plate' => $m->vehicle?->plate, 'user' => $m->user?->full_name,
                ]);
        }

        return Inertia::render('Finance/Pages/Inventory/Index', [
            'stock'    => $stock,
            'kardex'   => $kardex,
            'filters'  => ['branch' => $branchId, 'product' => $request->integer('product') ?: null, 'search' => $search],
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('name')->limit(2000)->get(['id', 'name', 'unit']),
            'vehicles' => Vehicle::where('is_active', true)->orderBy('plate')->get(['id', 'plate']),
            'pending'  => Requisition::where('stage', 'ordered')->whereNotNull('purchase_order_number')->whereNull('received_at')
                ->orderBy('ordered_at')->limit(100)->get(['id', 'code', 'series', 'number', 'purchase_order_number', 'detail'])
                ->map(fn (Requisition $r) => ['id' => $r->id, 'code' => $r->code(), 'order' => $r->purchase_order_number, 'detail' => $r->detail]),
        ]);
    }

    /** Entrada o salida manual (salida: consumo, opcionalmente por vehículo) */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa.');
        $tenant = current_tenant_id_or_null();
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $tenant)],
            'branch_id'  => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'type'       => ['required', Rule::in(['in', 'out'])],
            'date'       => ['required', 'date', 'before_or_equal:today'],
            'quantity'   => ['required', 'numeric', 'min:0.001', 'max:99999999'],
            'unit_cost'  => ['nullable', 'required_if:type,in', 'numeric', 'min:0', 'max:99999999'],
            'vehicle_id' => ['nullable', Rule::exists('vehicles', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'reference'  => ['nullable', 'string', 'max:100'],
            'notes'      => ['nullable', 'string', 'max:250'],
        ], ['unit_cost.required_if' => 'Indica el costo unitario de la entrada.'],
            ['product_id' => 'el producto', 'branch_id' => 'el almacén', 'quantity' => 'la cantidad', 'unit_cost' => 'el costo unitario']);

        $movement = StockMovement::record($data + ['user_id' => $request->user()->id]);
        $this->audit->log("stock.{$data['type']}", $movement, ['product_id' => $data['product_id'], 'quantity' => $data['quantity']]);

        return back()->with('success', $data['type'] === 'in' ? 'Entrada registrada.' : 'Salida registrada.');
    }

    /** Recepción (total o parcial) de una OC en un almacén: genera las entradas al kardex */
    public function receive(Request $request, int $id): RedirectResponse
    {
        $r = Requisition::with('items.product')->findOrFail($id);
        abort_unless($r->stage === 'ordered' && $r->purchase_order_number, 422, 'Solo se recibe una orden de compra emitida y no pagada.');
        abort_if($r->received_at, 422, 'La orden ya se recibió completa.');
        $tenant = $r->tenant_id;

        $data = $request->validate([
            'branch_id'          => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'date'               => ['required', 'date', 'before_or_equal:today'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.id'         => ['required', 'integer', Rule::in($r->items->pluck('id')->all())],
            'items.*.quantity'   => ['required', 'numeric', 'min:0', 'max:99999999'],
            'items.*.unit_cost'  => ['required', 'numeric', 'min:0', 'max:99999999'],
        ], [], ['branch_id' => 'el almacén', 'items.*.quantity' => 'la cantidad', 'items.*.unit_cost' => 'el costo unitario']);

        $received = DB::transaction(function () use ($r, $data, $request) {
            $count = 0;
            foreach ($data['items'] as $line) {
                $item = $r->items()->lockForUpdate()->findOrFail($line['id']);
                $qty = round((float) $line['quantity'], 3);
                if ($qty <= 0) {
                    continue;
                }
                $pending = round((float) $item->quantity - (float) $item->received_quantity, 3);
                abort_if($qty > $pending + 0.0005, 422, "Se recibe más de lo pedido en {$item->product?->name} (pendiente: {$pending}).");

                StockMovement::record([
                    'tenant_id' => $r->tenant_id, 'product_id' => $item->product_id, 'branch_id' => $data['branch_id'], 'type' => 'in',
                    'date' => $data['date'], 'quantity' => $qty, 'unit_cost' => $line['unit_cost'], 'requisition_id' => $r->id,
                    'vehicle_id' => $r->vehicle_id, 'reference' => $r->purchase_order_number, 'user_id' => $request->user()->id,
                ]);
                $item->increment('received_quantity', $qty);
                $count++;
            }
            abort_unless($count, 422, 'Indica la cantidad recibida de al menos un producto.');

            if ($r->items()->whereColumn('received_quantity', '<', 'quantity')->doesntExist()) {
                $r->update(['received_at' => now()]);
            }

            return $count;
        });
        $this->audit->log('requisition.received', $r, ['code' => $r->code(), 'order' => $r->purchase_order_number, 'lines' => $received]);

        return back()->with('success', $r->fresh()->received_at ? 'Orden recibida completa en almacén.' : 'Recepción parcial registrada.');
    }
}
