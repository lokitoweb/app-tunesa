<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Finance\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Proveedores en el panel (Registro Proveedor del ERP de escritorio):
 * listado con búsqueda, alta/edición con sus cuentas bancarias y la
 * consulta de RUC en Migo para autocompletar razón social y dirección.
 */
class SupplierWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search'));
        $status = $request->query('status');

        $suppliers = Supplier::with('bankAccounts:id,supplier_id,bank,account_number,account_type')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('ruc', 'like', "{$search}%")->orWhere('dni', 'like', "{$search}%")))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(50)->withQueryString()
            ->through(fn (Supplier $s) => $s->only(['id', 'name', 'ruc', 'dni', 'address', 'phone', 'email', 'contact', 'city', 'category', 'is_active'])
                + ['bank_accounts' => $s->bankAccounts->map->only(['bank', 'account_number', 'account_type'])->values()]);

        return Inertia::render('Finance/Pages/Suppliers/Index', [
            'suppliers'   => $suppliers,
            'filters'     => ['search' => $search, 'status' => $status],
            'can_manage'  => $request->user()->hasPermission('suppliers.manage'),
            'has_tenant'  => (bool) current_tenant_id_or_null(),
            'ruc_lookup'  => (bool) TenantSetting::forTenant(app(TenantContext::class)->getOrNull())->migo()['token'],
            'categories'  => Supplier::whereNotNull('category')->distinct()->orderBy('category')->limit(100)->pluck('category'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa para registrar el proveedor.');
        $data = $this->validated($request);

        // El RUC identifica al proveedor (índice único por empresa, también entre los eliminados):
        // si se había eliminado, se reactiva el mismo con los datos nuevos (conserva su historial)
        $deleted = filled($data['ruc'] ?? null) ? Supplier::onlyTrashed()->where('ruc', $data['ruc'])->first() : null;
        if ($deleted) {
            $deleted->restore();
            $supplier = $this->save($deleted, $data);
            $this->audit->log('supplier.restored', $supplier, ['name' => $supplier->name, 'ruc' => $supplier->ruc]);

            return back()->with('success', 'Ese RUC era de un proveedor eliminado: se reactivó con los datos nuevos.');
        }

        $supplier = $this->save(new Supplier(), $data);
        $this->audit->log('supplier.created', $supplier, ['name' => $supplier->name, 'ruc' => $supplier->ruc]);

        return back()->with('success', 'Proveedor registrado.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $supplier = Supplier::findOrFail($id);
        $this->save($supplier, $this->validated($request, $supplier->id));
        $this->audit->log('supplier.updated', $supplier, ['name' => $supplier->name, 'ruc' => $supplier->ruc]);

        return back()->with('success', 'Proveedor actualizado.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->delete();
        $this->audit->log('supplier.deleted', $supplier, ['name' => $supplier->name, 'ruc' => $supplier->ruc]);

        return back()->with('success', 'Proveedor eliminado.');
    }

    /** Razón social y dirección desde Migo (POST {ruc, token}, como el ERP de escritorio) */
    public function lookupRuc(string $ruc): JsonResponse
    {
        // Credenciales de la empresa (o de la plataforma) administradas en Integraciones
        ['token' => $token, 'ruc_url' => $url] = TenantSetting::forTenant(app(TenantContext::class)->getOrNull())->migo();
        abort_unless($token, 503, 'La consulta de RUC no está configurada.');

        try {
            $data = Http::timeout(10)->acceptJson()
                ->withHeaders(['Authorization' => "Token token={$token}"])
                ->post($url, ['ruc' => $ruc, 'token' => $token])
                ->json();
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Migo no respondió. Completa los datos a mano.'], 502);
        }

        if (! is_array($data) || empty($data['nombre_o_razon_social'])) {
            return response()->json(['success' => false, 'message' => 'No se encontró el RUC en SUNAT.'], 404);
        }

        return response()->json(['success' => true, 'data' => [
            'name'    => $data['nombre_o_razon_social'],
            'address' => trim(($data['direccion'] ?? '') ?: ''),
            'city'    => $data['distrito'] ?? $data['provincia'] ?? null,
        ]]);
    }

    // ------------------------------------------------------------------ privado

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'                          => ['required', 'string', 'max:250'],
            // Al registrar, un RUC de un proveedor eliminado se reactiva (store); al editar, no puede
            // tomar el RUC de otro, ni siquiera eliminado (el índice único de la base no lo permite)
            'ruc'                           => ['nullable', 'digits:11', $ignoreId
                ? Rule::unique('suppliers')->where('tenant_id', current_tenant_id_or_null())->ignore($ignoreId)
                : Rule::unique('suppliers')->where('tenant_id', current_tenant_id_or_null())->whereNull('deleted_at')],
            'dni'                           => ['nullable', 'digits:8'],
            'address'                       => ['nullable', 'string', 'max:100'],
            'phone'                         => ['nullable', 'string', 'max:50'],
            'email'                         => ['nullable', 'email', 'max:100'],
            'contact'                       => ['nullable', 'string', 'max:50'],
            'city'                          => ['nullable', 'string', 'max:50'],
            'category'                      => ['nullable', 'string', 'max:100'],
            'is_active'                     => ['boolean'],
            'bank_accounts'                 => ['array', 'max:20'],
            'bank_accounts.*.bank'          => ['required', 'string', 'max:50'],
            'bank_accounts.*.account_number' => ['required', 'string', 'max:100', 'distinct'],
            'bank_accounts.*.account_type'  => ['nullable', 'string', 'max:50'],
        ], [
            // Los mensajes genéricos vienen de lang/es/validation.php; aquí solo los propios del caso
            'bank_accounts.*.account_number.distinct' => 'Esa cuenta está repetida.',
            'ruc.unique'                              => 'Ya hay un proveedor con ese RUC (o lo tuvo uno eliminado).',
        ], [
            'name' => 'la razón social', 'ruc' => 'el RUC', 'dni' => 'el DNI', 'address' => 'la dirección',
            'bank_accounts.*.bank' => 'el banco', 'bank_accounts.*.account_number' => 'el número de cuenta',
        ]);
    }

    /** Guarda el proveedor y reemplaza sus cuentas en una sola transacción */
    private function save(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data) {
            $accounts = $data['bank_accounts'] ?? [];
            unset($data['bank_accounts']);
            $supplier->fill($data + ['is_active' => true])->save();

            $supplier->bankAccounts()->delete();
            foreach ($accounts as $account) {
                $supplier->bankAccounts()->create($account + ['tenant_id' => $supplier->tenant_id]);
            }

            return $supplier;
        });
    }
}
