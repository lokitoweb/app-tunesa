<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\Product;
use App\Modules\Finance\Models\Quotation;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Requerimientos y compras (FrmReq del ERP de escritorio): quien solicita
 * registra el requerimiento con sus productos; gerencia lo aprueba o
 * rechaza; logística cotiza con proveedores y emite la OC u OS, que deja
 * las cuotas listas para tesorería.
 */
class RequisitionWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $stage  = $request->query('stage');
        $search = trim((string) $request->query('search'));

        $requisitions = $this->visible($request)
            ->with(['supplier:id,name', 'user:id,first_name,last_name'])
            ->withCount('items')
            ->when(array_key_exists($stage, Requisition::STAGES), fn ($q) => $q->where('stage', $stage))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('detail', 'like', "%{$search}%")
                ->orWhere('requested_by', 'like', "%{$search}%")->orWhere('area', 'like', "%{$search}%")
                ->orWhere('purchase_order_number', 'like', "%{$search}%")->orWhere('service_order_number', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->when(ctype_digit($search), fn ($n) => $n->orWhere('number', (int) $search))))
            ->latest('requested_at')->latest('id')
            ->paginate(30)->withQueryString()
            ->through(fn (Requisition $r) => [
                'id'             => $r->id,
                'code'           => $r->code(),
                'requested_at'   => $r->requested_at?->format('d/m/Y'),
                'requested_by'   => $r->requested_by ?: $r->user?->full_name,
                'area'           => $r->area,
                'detail'         => $r->detail,
                'classification' => $r->classification,
                'stage'          => $r->stage,
                'is_express'     => $r->is_express,
                'items_count'    => $r->items_count,
                'supplier'       => $r->supplier?->name,
                'order_number'   => $r->orderNumber(),
                'amount'         => $r->amount,
                'currency'       => $r->currency,
            ]);

        $totals = $this->visible($request)->toBase()->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');

        return Inertia::render('Finance/Pages/Requisitions/Index', [
            'requisitions' => $requisitions,
            'stages'       => Requisition::STAGES,
            'totals'       => $totals,
            'filters'      => ['stage' => $stage, 'search' => $search],
            'can_create'   => $request->user()->hasPermission('requisitions.create'),
            'company_code' => app(TenantContext::class)->getOrNull()?->code,
            'can_edit_code' => $request->user()->hasPermission('company.document_code'),
        ]);
    }

    /** Abreviatura [EMPRESA] de los correlativos: editable, única; los códigos ya emitidos no cambian */
    public function updateCompanyCode(Request $request): RedirectResponse
    {
        $tenant = app(TenantContext::class)->getOrNull();
        abort_unless($tenant, 422, 'Elige una empresa en el selector.');
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Z0-9]{2}$/', Rule::unique('tenants', 'code')->ignore($tenant->id)],
        ], [
            'code.regex'  => 'La abreviatura debe tener exactamente 2 letras o dígitos.',
            'code.unique' => 'Otra empresa ya usa esa abreviatura.',
        ], ['code' => 'la abreviatura']);

        $old = $tenant->code;
        Tenant::findOrFail($tenant->id)->update(['code' => $data['code']]); // saved: invalida la caché del tenant
        $tenant->code = $data['code'];
        $this->audit->log('tenant.document_code_updated', $tenant, ['from' => $old, 'to' => $data['code']]);

        return back()->with('success', "Abreviatura de la empresa: {$data['code']}.");
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if (! current_tenant_id_or_null()) {
            return redirect()->route('requisitions.index')->with('error', 'Elige una empresa en el selector para registrar el requerimiento.');
        }
        $user = $request->user();

        return $this->form(new Requisition([
            'requested_at' => now(tenant_timezone()),
            'requested_by' => $user->full_name,
            'email'        => $user->email,
            'area'         => $user->department?->name,
            'priority'     => 'NORMAL',
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa para registrar el requerimiento.');
        $data = $this->validated($request);

        $requisition = DB::transaction(function () use ($data, $request) {
            // RQ-[EMPRESA]-[SERIE]-[N]-[AÑO]: la serie es la sede de quien solicita
            $series = DocumentSequence::seriesFrom($request->user()->assignedBranch?->code);
            $year = (int) now()->year;
            [$number, $code] = DocumentSequence::next(app(TenantContext::class)->get(), 'RQ', $series, $year);
            $requisition = Requisition::create($this->header($data) + [
                'series' => $series, 'number' => $number, 'code' => $code, 'year' => $year,
                'stage' => 'requested', 'user_id' => $request->user()->id,
            ]);
            $this->syncItems($requisition, $data['items']);

            return $requisition;
        });
        $this->audit->log('requisition.created', $requisition, ['code' => $requisition->code()]);

        return redirect()->route('requisitions.show', $requisition->id)->with('success', "Requerimiento {$requisition->code()} registrado.");
    }

    public function show(Request $request, int $id): Response
    {
        $r = $this->visible($request)->with(['items.product', 'quotations.supplier:id,name,ruc', 'supplier:id,name,ruc', 'paymentInstallments'])->findOrFail($id);
        $user = $request->user();

        return Inertia::render('Finance/Pages/Requisitions/Show', [
            'requisition' => [
                ...$r->only(['id', 'detail', 'area', 'requested_by', 'email', 'plate', 'km', 'agency', 'site', 'delivery_place',
                    'classification', 'stage', 'priority', 'notes', 'reviewed_by', 'payment_terms', 'payment_method', 'payment_origin',
                    'currency', 'amount', 'installments', 'purchase_order_number', 'service_order_number', 'is_express']),
                'code'         => $r->code(),
                'requested_at' => $r->requested_at?->format('d/m/Y'),
                'reviewed_at'  => $r->reviewed_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'ordered_at'   => $r->ordered_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'is_open'      => $r->isOpen(),
                'is_mine'      => $r->user_id === $user->id,
                'supplier'     => $r->supplier?->only(['id', 'name', 'ruc']),
                'items'        => $r->items->map(fn ($i) => [
                    'id' => $i->id, 'product' => $i->product?->name, 'unit' => $i->product?->unit, 'quantity' => (float) $i->quantity, 'justification' => $i->justification,
                ]),
                'quotations'   => $r->quotations->sortByDesc('is_selected')->values()->map(fn (Quotation $q) => [
                    ...$q->only(['id', 'number', 'amount', 'currency', 'contact', 'phone', 'notes', 'is_selected']),
                    'supplier'   => $q->supplier?->only(['id', 'name', 'ruc']),
                    'quoted_at'  => $q->quoted_at?->format('d/m/Y'),
                    'expires_at' => $q->expires_at?->format('d/m/Y'),
                ]),
                'installments_list' => $r->paymentInstallments->map(fn ($p) => [
                    'installment' => $p->installment, 'due_date' => $p->due_date?->format('d/m/Y'), 'amount' => $p->amount, 'status' => $p->status,
                ]),
            ],
            'stages'      => Requisition::STAGES,
            'suppliers'   => Supplier::where('is_active', true)->orderBy('name')->limit(1000)->get(['id', 'name', 'ruc']),
            'options'     => self::options(),
            'can'         => [
                'edit'     => $r->stage === 'requested' && ($r->user_id === $user->id || $user->hasPermission('requisitions.view_all')),
                'approve'  => $this->canReview($r, $user),
                'purchase' => $user->hasPermission('purchasing.manage'),
            ],
        ]);
    }

    public function edit(Request $request, int $id): Response|RedirectResponse
    {
        $r = $this->editable($request, $id);

        return $this->form($r->load('items.product'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $r = $this->editable($request, $id);
        $data = $this->validated($request);

        DB::transaction(function () use ($r, $data) {
            $r->update($this->header($data));
            $this->syncItems($r, $data['items']);
        });
        $this->audit->log('requisition.updated', $r, ['code' => $r->code()]);

        return redirect()->route('requisitions.show', $r->id)->with('success', 'Requerimiento actualizado.');
    }

    /** Aprobar o rechazar (gerencia) */
    public function review(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'notes'    => ['nullable', 'required_if:decision,rejected', 'string', 'max:100'],
        ], ['notes.required_if' => 'Indica el motivo del rechazo.']);

        $r = $this->visible($request)->findOrFail($id);
        abort_unless($this->canReview($r, $request->user()), 403);
        abort_unless($r->stage === 'requested', 422, 'El requerimiento ya fue revisado.');

        $r->update([
            'stage' => $data['decision'], 'notes' => $data['notes'] ?? $r->notes,
            'reviewed_by' => $request->user()->full_name, 'reviewed_by_id' => $request->user()->id, 'reviewed_at' => now(),
        ]);
        $this->audit->log("requisition.{$data['decision']}", $r, ['code' => $r->code(), 'notes' => $data['notes'] ?? null]);

        return back()->with('success', $data['decision'] === 'approved' ? 'Requerimiento aprobado: ya se puede cotizar.' : 'Requerimiento rechazado.');
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $r = $this->visible($request)->findOrFail($id);
        $user = $request->user();
        abort_unless($r->isOpen(), 422, 'Solo se anulan requerimientos sin orden emitida.');
        abort_unless($r->user_id === $user->id || $user->hasPermission('requisitions.approve') || $user->hasPermission('purchasing.manage'), 403);

        $r->update(['stage' => 'cancelled']);
        $this->audit->log('requisition.cancelled', $r, ['code' => $r->code()]);

        return back()->with('success', 'Requerimiento anulado.');
    }

    // --------------------------------------------------------------- cotizaciones y orden (logística)

    public function storeQuotation(Request $request, int $id): RedirectResponse
    {
        $r = Requisition::findOrFail($id);
        abort_unless(in_array($r->stage, ['approved', 'quoting'], true), 422, 'Solo se cotizan requerimientos aprobados.');

        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $r->tenant_id)->whereNull('deleted_at')],
            'number'      => ['nullable', 'string', 'max:50'],
            'amount'      => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            'currency'    => ['required', Rule::in(array_keys(Requisition::CURRENCIES))],
            'quoted_at'   => ['nullable', 'date'],
            'expires_at'  => ['nullable', 'date', 'after_or_equal:quoted_at'],
            'contact'     => ['nullable', 'string', 'max:50'],
            'phone'       => ['nullable', 'string', 'max:50'],
            'notes'       => ['nullable', 'string', 'max:250'],
        ], self::MESSAGES, ['supplier_id' => 'el proveedor', 'amount' => 'el monto']);

        // Express: un solo proveedor, en soles y por debajo del tope (sin cuadro comparativo)
        if ($r->is_express) {
            abort_if($r->quotations()->exists(), 422, 'El requerimiento Express se ordena con un solo proveedor.');
            if ($data['currency'] !== 'PEN' || (float) $data['amount'] >= Requisition::EXPRESS_LIMIT) {
                throw ValidationException::withMessages(['amount' => 'El Express es un gasto menor a S/ ' . Requisition::EXPRESS_LIMIT . ' en soles.']);
            }
        }

        $quotation = DB::transaction(function () use ($r, $data) {
            $r->update(['stage' => 'quoting']);

            return $r->quotations()->create($data + ['tenant_id' => $r->tenant_id]);
        });
        $this->audit->log('quotation.created', $r, ['code' => $r->code(), 'quotation_id' => $quotation->id]);

        return back()->with('success', 'Cotización agregada.');
    }

    public function destroyQuotation(int $id, int $quotationId): RedirectResponse
    {
        $r = Requisition::findOrFail($id);
        abort_unless($r->stage === 'quoting', 422, 'La orden ya fue emitida.');
        $r->quotations()->findOrFail($quotationId)->delete();
        $this->audit->log('quotation.deleted', $r, ['code' => $r->code(), 'quotation_id' => $quotationId]);

        return back()->with('success', 'Cotización eliminada.');
    }

    /** Emite la OC u OS con la cotización elegida y genera las cuotas por pagar */
    public function order(Request $request, int $id): RedirectResponse
    {
        $r = Requisition::findOrFail($id);
        abort_unless($r->stage === 'quoting', 422, 'Agrega al menos una cotización antes de emitir la orden.');

        $data = $request->validate([
            'quotation_id'   => ['required', Rule::exists('quotations', 'id')->where('requisition_id', $r->id)],
            'type'           => ['required', Rule::in(['purchase', 'service'])],
            'payment_terms'  => ['required', Rule::in(Requisition::PAYMENT_TERMS)],
            'payment_method' => ['required', Rule::in(Requisition::PAYMENT_METHODS)],
            'payment_origin' => ['required', Rule::in(Requisition::PAYMENT_ORIGINS)],
            'priority'       => ['required', Rule::in(Requisition::PRIORITIES)],
            'installments'   => ['required', 'integer', 'min:1', 'max:36'],
            'first_due_date' => ['required', 'date'],
            'interval_days'  => ['required', 'integer', 'min:1', 'max:365'],
            'has_detraction' => ['boolean'],
        ], self::MESSAGES, ['quotation_id' => 'la cotización', 'first_due_date' => 'el primer vencimiento']);

        $number = DB::transaction(function () use ($r, $data, $request) {
            $quotation = $r->quotations()->findOrFail($data['quotation_id']);
            $column = $data['type'] === 'purchase' ? 'purchase_order_number' : 'service_order_number';
            $prefix = $data['type'] === 'purchase' ? 'OC' : 'OS';
            // OC/OS-[EMPRESA]-[SERIE]-[N]-[AÑO], misma serie (sede) que el requerimiento
            [, $number] = DocumentSequence::next($r->tenant, $prefix, DocumentSequence::seriesFrom($r->year ? $r->series : null), (int) now()->year);

            $r->quotations()->update(['is_selected' => false]);
            $quotation->update(['is_selected' => true]);

            $r->update([
                'stage' => 'ordered', $column => $number, 'ordered_at' => now(),
                'supplier_id' => $quotation->supplier_id, 'amount' => $quotation->amount, 'amount_payable' => $quotation->amount,
                'currency' => $quotation->currency, 'installments' => $data['installments'],
                'has_detraction' => (bool) ($data['has_detraction'] ?? false),
            ] + collect($data)->only(['payment_terms', 'payment_method', 'payment_origin', 'priority'])->all());

            // Cuotas iguales; la última absorbe el redondeo
            $count = (int) $data['installments'];
            $base = floor(((float) $quotation->amount / $count) * 100) / 100;
            $due = \Illuminate\Support\Carbon::parse($data['first_due_date']);
            for ($i = 1; $i <= $count; $i++) {
                $r->paymentInstallments()->create([
                    'tenant_id' => $r->tenant_id, 'installment' => $i, 'due_date' => $due->copy(),
                    'amount' => $i === $count ? round((float) $quotation->amount - $base * ($count - 1), 2) : $base,
                    'status' => 'pending', 'payment_method' => $data['payment_method'], 'registered_by' => $request->user()->full_name,
                ]);
                $due->addDays((int) $data['interval_days']);
            }

            return $number;
        });
        $this->audit->log('requisition.ordered', $r, ['code' => $r->code(), 'order' => $number]);

        return back()->with('success', "Orden {$number} emitida: las cuotas quedaron pendientes de pago.");
    }

    // ------------------------------------------------------------------ privado

    /** Solo los mensajes propios de este flujo; los genéricos vienen de lang/es/validation.php */
    private const MESSAGES = [
        'expires_at.after_or_equal' => 'La vigencia no puede ser anterior a la fecha de la cotización.',
    ];

    /** Sin requisitions.view_all solo se ven los propios */
    private function visible(Request $request): Builder
    {
        $user = $request->user();

        // La jefatura inmediata ve además los Express de su equipo, para validarlos
        return Requisition::query()->unless(
            $user->hasPermission('requisitions.view_all') || $user->hasPermission('requisitions.approve') || $user->hasPermission('purchasing.manage'),
            fn ($q) => $q->where(fn ($w) => $w->where('user_id', $user->id)
                ->orWhere(fn ($e) => $e->where('is_express', true)->whereIn('user_id', User::supervisedBy($user)->select('users.id')))),
        );
    }

    /**
     * Quién valida: gerencia (requisitions.approve). El Express (< S/ 600) lo valida la jefatura
     * inmediata (supervisor de la sede de quien solicita) o contabilidad, nunca quien lo pidió.
     */
    private function canReview(Requisition $r, User $user): bool
    {
        if (! $r->is_express) {
            return $user->hasPermission('requisitions.approve');
        }

        return $r->user_id !== $user->id && ($user->hasPermission('requisitions.approve')
            || User::supervisedBy($user)->whereKey($r->user_id)->exists());
    }

    private function editable(Request $request, int $id): Requisition
    {
        $r = $this->visible($request)->findOrFail($id);
        abort_unless($r->stage === 'requested', 422, 'Solo se edita un requerimiento que aún no se revisa.');
        abort_unless($r->user_id === $request->user()->id || $request->user()->hasPermission('requisitions.view_all'), 403);

        return $r;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'requested_at'          => ['required', 'date'],
            'detail'                => ['required', 'string', 'max:1000'],
            'area'                  => ['nullable', 'string', 'max:50'],
            'requested_by'          => ['required', 'string', 'max:50'],
            'email'                 => ['nullable', 'email', 'max:100'],
            'classification'        => ['required', Rule::in(Requisition::CLASSIFICATIONS)],
            'priority'              => ['required', Rule::in(Requisition::PRIORITIES)],
            'plate'                 => ['nullable', 'string', 'max:50'],
            'km'                    => ['nullable', 'string', 'max:50'],
            'agency'                => ['nullable', 'string', 'max:100'],
            'site'                  => ['nullable', 'string', 'max:100'],
            'delivery_place'        => ['nullable', 'string', 'max:100'],
            'items'                 => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product'       => ['required', 'string', 'max:100'],
            'items.*.unit'          => ['nullable', 'string', 'max:50'],
            'items.*.quantity'      => ['required', 'numeric', 'min:0.001', 'max:99999999'],
            'items.*.justification' => ['nullable', 'string', 'max:100'],
            'is_express'            => ['boolean'],
            'amount'                => ['nullable', 'required_if:is_express,true,1', 'numeric', 'min:0.01', 'lt:' . Requisition::EXPRESS_LIMIT],
        ], self::MESSAGES + [
            'amount.required_if' => 'Indica el monto estimado del gasto.',
            'amount.lt'          => 'El Express es para gastos menores a S/ ' . Requisition::EXPRESS_LIMIT . '; usa un requerimiento normal.',
        ] + ['items.required' => 'Agrega al menos un producto.', 'items.min' => 'Agrega al menos un producto.'], [
            'requested_at' => 'la fecha', 'detail' => 'el detalle', 'requested_by' => 'quién solicita', 'classification' => 'la clasificación',
            'priority' => 'la prioridad', 'items.*.product' => 'el producto', 'items.*.quantity' => 'la cantidad',
        ]);
    }

    private function header(array $data): array
    {
        $express = (bool) ($data['is_express'] ?? false);

        // El monto estimado solo se guarda en el Express (en soles); el normal lo fija la cotización
        return ['is_express' => $express, 'amount' => $express ? $data['amount'] : null] + ($express ? ['currency' => 'PEN'] : [])
            + collect($data)->except(['items', 'is_express', 'amount'])->all();
    }

    /** Reemplaza las líneas; el producto se busca por nombre en la empresa o se crea */
    private function syncItems(Requisition $r, array $items): void
    {
        $r->items()->delete();
        foreach ($items as $item) {
            $product = Product::firstOrCreate(
                ['tenant_id' => $r->tenant_id, 'name' => trim($item['product'])],
                ['unit' => $item['unit'] ?? null, 'classification' => $r->classification],
            );
            $r->items()->create([
                'product_id' => $product->id, 'quantity' => $item['quantity'], 'justification' => $item['justification'] ?? null,
            ]);
        }
    }

    private function form(Requisition $r): Response
    {
        return Inertia::render('Finance/Pages/Requisitions/Form', [
            'requisition' => [
                ...$r->only(['id', 'detail', 'area', 'requested_by', 'email', 'classification', 'priority', 'plate', 'km', 'agency', 'site', 'delivery_place', 'is_express', 'amount']),
                'code'         => $r->exists ? $r->code() : null,
                'requested_at' => ($r->requested_at ?? now())->format('Y-m-d'),
                'items'        => $r->exists
                    ? $r->items->map(fn ($i) => ['product' => $i->product?->name, 'unit' => $i->product?->unit, 'quantity' => (float) $i->quantity, 'justification' => $i->justification ?? ''])
                    : [],
            ],
            'products' => Product::orderBy('name')->limit(1000)->get(['name', 'unit']),
            'options'  => self::options(),
        ]);
    }

    public static function options(): array
    {
        return [
            'classifications' => Requisition::CLASSIFICATIONS,
            'currencies'      => Requisition::CURRENCIES,
            'payment_terms'   => Requisition::PAYMENT_TERMS,
            'payment_methods' => Requisition::PAYMENT_METHODS,
            'priorities'      => Requisition::PRIORITIES,
            'express_limit'   => Requisition::EXPRESS_LIMIT,
            'payment_origins' => Requisition::PAYMENT_ORIGINS,
            'units'           => ['UNIDAD (BIENES)', 'UNIDAD (SERVICIOS)', 'CAJA', 'PAQUETE', 'JUEGO', 'KIT', 'PAR', 'DOCENA', 'KILOGRAMO', 'GRAMO',
                'LITRO', 'GALON', 'METRO', 'METRO CUADRADO', 'METRO CUBICO', 'TONELADAS', 'BOLSA', 'BALDE', 'CILINDRO', 'RESMA', 'PIEZAS', 'TUBOS'],
        ];
    }
}
