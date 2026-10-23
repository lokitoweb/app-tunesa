<?php

namespace App\Modules\Finance\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Finance\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Proveedores de la empresa con sus cuentas (búsqueda por nombre o RUC) */
class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        $suppliers = Supplier::with('bankAccounts:id,supplier_id,bank,account_number,account_type')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('ruc', 'like', "{$q}%")))
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'ruc', 'dni', 'phone', 'email', 'contact', 'city', 'category', 'is_active']);

        return response()->json(['success' => true, 'data' => $suppliers]);
    }
}
