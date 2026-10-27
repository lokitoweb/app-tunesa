<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Finance\Models\Product;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\RequisitionItem;
use App\Modules\Finance\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de productos y servicios (sisgere FrmReq: Modificar_producto / eliminar_producto).
 * Se crean solos al pedirlos en un requerimiento; aquí se corrigen. Uno ya usado no se elimina:
 * su historial de compras y kardex lo necesita.
 */
class ProductWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search'));
        $products = Product::when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->withCount(['items as uses'])->orderBy('name')->paginate(30)->withQueryString()
            ->through(fn (Product $p) => $p->only(['id', 'name', 'unit', 'classification']) + ['uses' => $p->uses]);

        return Inertia::render('Finance/Pages/Products/Index', [
            'products' => $products,
            'filters'  => ['search' => $search],
            'options'  => ['units' => RequisitionWebController::options()['units'], 'classifications' => Requisition::CLASSIFICATIONS],
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $product = Product::findOrFail($id);
        $data = $request->validate([
            // Sin duplicados aunque cambien mayúsculas (MySQL ya compara así; SQLite no)
            'name'           => ['required', 'string', 'max:100', fn ($attr, $value, $fail) => Product::where('tenant_id', $product->tenant_id)->whereKeyNot($product->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])->exists() ? $fail('Ya existe un producto con ese nombre.') : null],
            // Un producto migrado del sistema anterior puede conservar su unidad o clasificación de entonces
            'unit'           => ['required', Rule::in([...RequisitionWebController::options()['units'], $product->unit])],
            'classification' => ['nullable', Rule::in([...Requisition::CLASSIFICATIONS, $product->classification])],
        ], ['name.unique' => 'Ya existe un producto con ese nombre.'], ['name' => 'el nombre', 'unit' => 'la unidad', 'classification' => 'la clasificación']);

        $before = $product->only(['name', 'unit', 'classification']);
        $product->update(['name' => trim($data['name'])] + $data);
        $this->audit->log('product.updated', $product, ['before' => $before, 'after' => $product->only(['name', 'unit', 'classification'])]);

        return back()->with('success', 'Producto actualizado.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $product = Product::findOrFail($id);
        abort_if(RequisitionItem::where('product_id', $product->id)->exists() || StockMovement::where('product_id', $product->id)->exists(), 422,
            'El producto ya se usó en requerimientos o en el almacén: no se elimina (corrígelo si el nombre está mal).');
        $this->audit->log('product.deleted', null, $product->only(['id', 'name', 'unit']));
        $product->delete();

        return back()->with('success', 'Producto eliminado.');
    }
}
