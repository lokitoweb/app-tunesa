<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\SafeUpload;
use App\Modules\Core\Support\PrivateFile;
use App\Modules\Finance\Models\Vehicle;
use App\Modules\Finance\Notifications\RequisitionReviewed;
use App\Modules\Finance\Notifications\SupplierDocument;
use App\Modules\Finance\Services\FinanceDocumentPdf;
use App\Modules\Finance\Services\OrderPricing;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\PaymentInstallment;
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
            // RQ-[EMPRESA]-[SERIE]-[N]-[AÑO]: la serie es la de la sede de quien solicita (o su código)
            $branch = $request->user()->primaryBranch()->first() ?? $request->user()->assignedBranch;
            $series = DocumentSequence::seriesFrom($branch?->document_series ?: $branch?->code);
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
        $r = $this->visible($request)->with(['items.product', 'quotations.supplier:id,name,ruc', 'supplier:id,name,ruc', 'paymentInstallments.cancellations',
            'stockMovements.branch:id,name'])->findOrFail($id);
        $user = $request->user();

        return Inertia::render('Finance/Pages/Requisitions/Show', [
            'requisition' => [
                ...$r->only(['id', 'detail', 'area', 'requested_by', 'email', 'plate', 'km', 'agency', 'site', 'delivery_place',
                    'classification', 'stage', 'priority', 'notes', 'reviewed_by', 'payment_terms', 'payment_method', 'payment_origin',
                    'currency', 'amount', 'installments', 'purchase_order_number', 'service_order_number', 'is_express', 'is_direct_payment', 'amount_payable', 'delivery_mode', 'quote_payment_terms', 'quote_notes', 'cash_flow_concept']),
                'quote_requests' => $r->quoteRequests()->with('supplier:id,name,ruc,email')->orderBy('id')->get()->map(fn ($q) => [
                    'id' => $q->id, 'code' => $q->code, 'supplier' => $q->supplier?->name, 'has_email' => (bool) $q->supplier?->email,
                    'emailed_at' => $q->emailed_at?->timezone(tenant_timezone())->format('d/m/Y H:i'), 'supplier_id' => $q->supplier_id,
                ]),
                'code'         => $r->code(),
                'order_number' => $r->orderNumber(),
                'advance_amount' => $r->advance_amount !== null ? (float) $r->advance_amount : null,
                'requested_at' => $r->requested_at?->format('d/m/Y'),
                'reviewed_at'  => $r->reviewed_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'ordered_at'   => $r->ordered_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'is_open'      => $r->isOpen(),
                'is_mine'      => $r->user_id === $user->id,
                'supplier'     => $r->supplier?->only(['id', 'name', 'ruc']),
                'items'        => $r->items->map(fn ($i) => [
                    'id' => $i->id, 'product' => $i->product?->name, 'unit' => $i->product?->unit, 'quantity' => (float) $i->quantity, 'justification' => $i->justification,
                    'received_quantity' => (float) $i->received_quantity,
                    'unit_price' => $i->unit_price !== null ? (float) $i->unit_price : null,
                    'discount_percent' => (float) $i->discount_percent, 'subtotal' => $i->subtotal !== null ? (float) $i->subtotal : null,
                ]),
                // Desglose de la orden (null si se emitió con el total de la cotización)
                'pricing' => $r->igv_rate !== null ? [
                    'includes_igv' => (bool) $r->prices_include_igv, 'rate' => (float) $r->igv_rate,
                    'taxable' => (float) $r->taxable_amount, 'igv' => (float) $r->igv_amount, 'total' => (float) $r->amount,
                ] : null,
                'igv_rate' => (float) config('finance.igv_rate', 0.18),
                'detraction' => $r->has_detraction ? [
                    'reason' => $r->detraction_reason ? $r->detraction_reason . ' - ' . config("finance.detraction_reasons.{$r->detraction_reason}.0") : null,
                    'percent' => $r->detraction_percent, 'amount' => $r->detraction_amount !== null ? (float) $r->detraction_amount : null,
                    'payable' => (float) $r->amount_payable, 'number' => $r->detraction_number,
                ] : null,
                // Traza: factura, recepción en almacén y pagos (cancelado / amortizado) de esta orden
                'invoice'      => ['number' => $r->invoice_number, 'date' => $r->invoice_date?->format('d/m/Y'), 'has_file' => (bool) $r->invoice_path],
                'received_at'  => $r->received_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'receipts'     => $r->stockMovements->groupBy(fn ($m) => $m->date->format('d/m/Y') . ' · ' . $m->branch?->name)->map->count(),
                'payments'     => $r->paymentInstallments->flatMap->cancellations->sortBy('paid_at')->values()->map(fn ($c) => [
                    'id' => $c->id, 'paid_at' => $c->paid_at?->format('d/m/Y'), 'amount' => (float) $c->amount, 'operation' => $c->operation_number,
                    'status' => $c->is_amortization ? 'Pago amortizado' : 'Pago cancelado', 'has_voucher' => (bool) $c->voucher_path,
                ]),
                'quotations'   => $r->quotations->sortByDesc('is_selected')->values()->map(fn (Quotation $q) => [
                    ...$q->only(['id', 'number', 'amount', 'currency', 'contact', 'phone', 'notes', 'is_selected']),
                    'has_file'   => (bool) $q->file_path,
                    'supplier'   => $q->supplier?->only(['id', 'name', 'ruc']),
                    'quoted_at'  => $q->quoted_at?->format('d/m/Y'),
                    'expires_at' => $q->expires_at?->format('d/m/Y'),
                ]),
                // Anticipos disponibles por proveedor cotizado (para descontarlos al emitir la orden)
                'advances'     => $r->stage === 'quoting' ? $r->quotations->pluck('supplier_id')->unique()->filter()->mapWithKeys(fn ($sid) => [$sid => $this->availableAdvances($sid, null)
                    ->with('requisition:id,code,series,number,currency,detail')->get()->map(fn ($a) => [
                        'id' => $a->id, 'code' => $a->requisition->code(), 'detail' => $a->requisition->detail, 'currency' => $a->requisition->currency,
                        'amount' => (float) $a->amount, 'paid_at' => $a->paid_at?->timezone(tenant_timezone())->format('d/m/Y'),
                    ])]) : (object) [],
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
                'invoice'  => $r->stage === 'ordered' && ($user->hasPermission('purchasing.manage') || $user->hasPermission('treasury.pay')),
                'receive'  => $r->stage === 'ordered' && $r->purchase_order_number && ! $r->received_at && $user->hasPermission('inventory.manage'),
            ],
            'branches'    => $user->hasPermission('inventory.manage') ? \App\Modules\Core\Models\Branch::orderBy('name')->get(['id', 'name']) : [],
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
        // ACEPTADO / RECHAZADO a quien lo pidió (aviso + correo por el SMTP de la empresa); un fallo de correo no revierte la revisión
        rescue(fn () => $r->user?->notify(new RequisitionReviewed($r)), report: true);

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
            // PDF de la cotización: obligatorio salvo en el Express (sin cotización formal)
            'file'        => [$r->is_express ? 'nullable' : 'required', 'file', 'mimes:pdf', 'max:5120', SafeUpload::pdf()],
        ], self::MESSAGES + ['file.required' => 'Adjunta el PDF de la cotización.', 'file.mimes' => 'La cotización debe ser PDF.', 'file.max' => 'El PDF pesa más de 5 MB.'],
            ['supplier_id' => 'el proveedor', 'amount' => 'el monto', 'file' => 'la cotización']);
        unset($data['file']);

        // Express: un solo proveedor, en soles y por debajo del tope (sin cuadro comparativo)
        if ($r->is_express) {
            abort_if($r->quotations()->exists(), 422, 'El requerimiento Express se ordena con un solo proveedor.');
            if ($data['currency'] !== 'PEN' || (float) $data['amount'] >= Requisition::EXPRESS_LIMIT) {
                throw ValidationException::withMessages(['amount' => 'El Express es un gasto menor a S/ ' . Requisition::EXPRESS_LIMIT . ' en soles.']);
            }
        }

        $data['file_path'] = $request->file('file')?->store("finance/{$r->tenant_id}/quotations", self::DISK);
        $quotation = DB::transaction(function () use ($r, $data) {
            $r->update(['stage' => 'quoting']);

            return $r->quotations()->create($data + ['tenant_id' => $r->tenant_id]);
        });
        $this->audit->log('quotation.created', $r, ['code' => $r->code(), 'quotation_id' => $quotation->id]);

        return back()->with('success', 'Cotización agregada.');
    }

    /** PDF de la cotización (privado; lo ve quien ve el requerimiento) */
    public function quotationFile(Request $request, int $id, int $quotationId)
    {
        $q = $this->visible($request)->findOrFail($id)->quotations()->findOrFail($quotationId);

        return PrivateFile::response(self::DISK, $q->file_path);
    }

    /** Factura del proveedor (número, fecha y PDF): sin factura no se paga */
    public function invoice(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.manage') || $request->user()->hasPermission('treasury.pay'), 403);
        $r = Requisition::findOrFail($id);
        abort_unless($r->stage === 'ordered', 422, 'La factura se registra sobre una orden emitida y no pagada.');
        $data = $request->validate([
            'invoice_number' => ['required', 'string', 'max:50'],
            'invoice_date'   => ['required', 'date', 'before_or_equal:today'],
            'file'           => [$r->invoice_path ? 'nullable' : 'required', 'file', 'mimes:pdf', 'max:5120', SafeUpload::pdf()],
        ], ['file.required' => 'Adjunta el PDF de la factura.', 'file.mimes' => 'La factura debe ser PDF.', 'invoice_date.before_or_equal' => 'La fecha no puede ser futura.'],
            ['invoice_number' => 'el número de factura', 'invoice_date' => 'la fecha de la factura', 'file' => 'la factura']);

        $update = ['invoice_number' => $data['invoice_number'], 'invoice_date' => $data['invoice_date']];
        if ($request->hasFile('file')) {
            if ($r->invoice_path) {
                Storage::disk(self::DISK)->delete($r->invoice_path);
            }
            $update['invoice_path'] = $request->file('file')->store("finance/{$r->tenant_id}/invoices", self::DISK);
        }
        $r->update($update);
        $this->audit->log('requisition.invoiced', $r, ['code' => $r->code(), 'invoice' => $data['invoice_number']]);

        return back()->with('success', 'Factura registrada: ya se puede pagar.');
    }

    public function invoiceFile(Request $request, int $id)
    {
        return PrivateFile::response(self::DISK, $this->visible($request)->findOrFail($id)->invoice_path);
    }

    /** OC/OS en PDF con la firma de quien aprobó */
    public function orderPdf(Request $request, int $id): HttpResponse
    {
        $r = $this->visible($request)->findOrFail($id);
        abort_unless($r->orderNumber(), 404);

        return response(FinanceDocumentPdf::order($r), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $r->orderNumber() . '.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
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
            'cash_flow_concept' => ['nullable', Rule::in(Requisition::cashFlowConcepts())],
            'has_detraction' => ['boolean'],
            'detraction_reason' => ['nullable', 'required_if_accepted:has_detraction', Rule::in(array_keys(config('finance.detraction_reasons')))],
            // Cuotas escritas a mano (sisgere: lista de cuotas editable); vacío = cuotas iguales
            'schedule'          => ['nullable', 'array', 'max:36'],
            'schedule.*.due_date' => ['required', 'date'],
            'schedule.*.amount'   => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            // Anticipos pagados del proveedor que se descuentan de esta orden (FrmReq › Aplicar anticipo)
            'advance_ids'         => ['nullable', 'array', 'max:20'],
            'advance_ids.*'       => ['integer', 'distinct'],
            // Precios por ítem (opcionales, como en sisgere): si se llena uno, van todos
            'prices_include_igv'       => ['boolean'],
            'items'                    => ['nullable', 'array', 'max:100'],
            'items.*.id'               => ['required', 'integer', Rule::exists('requisition_items', 'id')->where('requisition_id', $r->id)],
            'items.*.unit_price'       => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], self::MESSAGES + ['detraction_reason.required_if_accepted' => 'Elige el motivo de la detracción.'], [
            'quotation_id' => 'la cotización', 'first_due_date' => 'el primer vencimiento', 'detraction_reason' => 'el motivo de detracción',
            'schedule.*.due_date' => 'el vencimiento de la cuota :position', 'schedule.*.amount' => 'el monto de la cuota :position',
        ]);

        $pricing = $this->pricing($r, $data);
        // Con precios por ítem, el total de la orden sale de ellos (con su IGV); sin precios, de la cotización
        $quotation = $r->quotations()->findOrFail($data['quotation_id']);
        $total = $pricing ? $pricing['total'] : (float) $quotation->amount;

        // Detracción (FrmReq): % del motivo, en soles enteros; al proveedor se le paga el neto
        $hasDetraction = (bool) ($data['has_detraction'] ?? false);
        $reason = $hasDetraction ? $data['detraction_reason'] : null;
        $percent = $reason ? (int) config("finance.detraction_reasons.{$reason}.1") : null;
        $detraction = $reason ? OrderPricing::detraction($total, $percent) : null;
        $payable = round($total - (float) $detraction, 2);

        // Anticipos: del mismo proveedor y moneda, pagados y sin aplicar; no pueden superar la deuda
        $advances = $this->availableAdvances($quotation->supplier_id, $quotation->currency)->whereIn('id', $data['advance_ids'] ?? [])->get();
        if (count($data['advance_ids'] ?? []) !== $advances->count()) {
            throw ValidationException::withMessages(['advance_ids' => 'Uno de los anticipos ya no está disponible. Actualiza la pantalla.']);
        }
        $advance = round((float) $advances->sum('amount'), 2);
        if ($advance > $payable + 0.004) {
            throw ValidationException::withMessages(['advance_ids' => 'El anticipo es mayor que la deuda (' . FinanceDocumentPdf::money($quotation->currency, $payable) . ').']);
        }
        $payable = round($payable - $advance, 2);

        $schedule = $payable <= 0 ? [] : OrderPricing::installments($payable, (int) $data['installments'], $data['first_due_date'], (int) $data['interval_days'], $data['schedule'] ?? null)
            ?? throw ValidationException::withMessages(['schedule' => 'Las cuotas deben sumar el neto a pagar: ' . FinanceDocumentPdf::money($quotation->currency, $payable) . '.']);

        $number = DB::transaction(function () use ($r, $data, $request, $pricing, $quotation, $total, $hasDetraction, $reason, $percent, $detraction, $payable, $schedule, $advances, $advance) {
            // Con el anticipo bloqueado se revisa otra vez: dos órdenes a la vez no aplican el mismo anticipo
            if ($advances->isNotEmpty()) {
                $locked = PaymentInstallment::whereKey($advances->modelKeys())->whereNull('advance_applied_to_id')->lockForUpdate()->count();
                if ($locked !== $advances->count()) {
                    throw ValidationException::withMessages(['advance_ids' => 'Uno de los anticipos ya se aplicó a otra orden. Actualiza la pantalla.']);
                }
                PaymentInstallment::whereKey($advances->modelKeys())->update(['advance_applied_to_id' => $r->id]);
            }
            if ($pricing) {
                foreach ($pricing['items'] as $id => [$price, $discount, $subtotal]) {
                    $r->items()->whereKey($id)->update(['unit_price' => $price, 'discount_percent' => $discount, 'subtotal' => $subtotal]);
                }
            }
            $column = $data['type'] === 'purchase' ? 'purchase_order_number' : 'service_order_number';
            $prefix = $data['type'] === 'purchase' ? 'OC' : 'OS';
            // OC/OS-[EMPRESA]-[SERIE]-[N]-[AÑO], misma serie (sede) que el requerimiento
            [, $number] = DocumentSequence::next($r->tenant, $prefix, DocumentSequence::seriesFrom($r->year ? $r->series : null), (int) now()->year);

            $r->quotations()->update(['is_selected' => false]);
            $quotation->update(['is_selected' => true]);

            $r->update([
                // Cubierta del todo por anticipos: no quedan cuotas y la orden queda pagada
                'stage' => $schedule ? 'ordered' : 'paid', $column => $number, 'ordered_at' => now(),
                'supplier_id' => $quotation->supplier_id, 'amount' => $total, 'amount_payable' => $payable, 'advance_amount' => $advance > 0 ? $advance : null,
                'currency' => $quotation->currency, 'installments' => count($schedule),
                'cash_flow_concept' => $data['cash_flow_concept'] ?? $r->cash_flow_concept,
                'has_detraction' => $hasDetraction, 'detraction_reason' => $reason, 'detraction_percent' => $percent, 'detraction_amount' => $detraction,
                'prices_include_igv' => $pricing['includes_igv'] ?? null, 'igv_rate' => $pricing['rate'] ?? null,
                'taxable_amount' => $pricing['taxable'] ?? null, 'igv_amount' => $pricing['igv'] ?? null,
            ] + collect($data)->only(['payment_terms', 'payment_method', 'payment_origin', 'priority'])->all());

            foreach ($schedule as $i => $row) {
                $r->paymentInstallments()->create([
                    'tenant_id' => $r->tenant_id, 'installment' => $i + 1, 'due_date' => $row['due_date'], 'amount' => $row['amount'],
                    'status' => 'pending', 'payment_method' => $data['payment_method'], 'registered_by' => $request->user()->full_name,
                ]);
            }

            return $number;
        });
        $this->audit->log('requisition.ordered', $r, ['code' => $r->code(), 'order' => $number]);
        if ($advances->isNotEmpty()) {
            $this->audit->log('requisition.advance_applied', $r, ['order' => $number, 'amount' => $advance, 'advances' => $advances->modelKeys()]);
        }

        // La OC/OS en PDF (con la firma de quien aprobó) al correo del proveedor, por el SMTP de la empresa
        $r->refresh()->load('supplier');
        if ($r->supplier?->email) {
            rescue(fn () => Notification::route('mail', $r->supplier->email)->notify(new SupplierDocument(
                $r->tenant, "{$number} · {$r->tenant->company_name}",
                ["Le hacemos llegar la {$number} por " . FinanceDocumentPdf::money($r->currency, $r->amount) . '.', "Detalle: {$r->detail}"],
                FinanceDocumentPdf::order($r), "{$number}.pdf",
            )), report: true);
        }

        return back()->with('success', "Orden {$number} emitida: las cuotas quedaron pendientes de pago.");
    }

    /**
     * Solicitud de cotización a varios proveedores (FrmReq › Registrar solicitud): un SC-… por
     * proveedor con las condiciones pedidas, y el PDF por correo. Reenviar a un proveedor conserva su SC.
     */
    public function sendQuoteRequests(Request $request, int $id): RedirectResponse
    {
        $r = Requisition::with('tenant')->findOrFail($id);
        abort_unless(in_array($r->stage, ['approved', 'quoting'], true), 422, 'La solicitud de cotización se envía con el requerimiento aprobado.');
        $data = $request->validate([
            'supplier_ids'        => ['required', 'array', 'min:1', 'max:30'],
            'supplier_ids.*'      => ['integer', 'distinct', Rule::exists('suppliers', 'id')->where('tenant_id', $r->tenant_id)->where('is_active', true)->whereNull('deleted_at')],
            'delivery_mode'       => ['required', Rule::in(['pickup', 'delivery'])],
            'delivery_place'      => ['nullable', 'required_if:delivery_mode,delivery', 'string', 'max:100'],
            'quote_payment_terms' => ['nullable', 'string', 'max:100'],
            'quote_notes'         => ['nullable', 'string', 'max:300'],
        ], ['supplier_ids.required' => 'Elige al menos un proveedor.', 'supplier_ids.*.exists' => 'Uno de los proveedores no está activo.',
            'delivery_place.required_if' => 'Indica el lugar de entrega.'],
            ['delivery_mode' => 'el tipo de entrega', 'delivery_place' => 'el lugar de entrega', 'quote_payment_terms' => 'la condición de pago', 'quote_notes' => 'la observación']);

        $user = $request->user();
        $requests = DB::transaction(function () use ($r, $data, $user) {
            $r->update(collect($data)->only(['delivery_mode', 'delivery_place', 'quote_payment_terms', 'quote_notes'])->all());

            return collect($data['supplier_ids'])->map(function ($sid) use ($r, $user) {
                $existing = $r->quoteRequests()->where('supplier_id', $sid)->first();
                if ($existing) {
                    $existing->update(['sent_by' => $user->full_name]);

                    return $existing;
                }
                [, $code] = DocumentSequence::next($r->tenant, 'SC', DocumentSequence::seriesFrom($r->year ? $r->series : null), (int) now()->year);

                return $r->quoteRequests()->create(['tenant_id' => $r->tenant_id, 'supplier_id' => $sid, 'code' => $code, 'sent_by' => $user->full_name]);
            });
        });
        $this->audit->log('requisition.quote_requested', $r, ['code' => $r->code(), 'requests' => $requests->pluck('code')->all()]);

        // El PDF a cada proveedor con correo (SMTP de la empresa); un fallo de correo no deshace la solicitud
        $mailed = 0;
        foreach ((new \Illuminate\Database\Eloquent\Collection($requests->all()))->load('supplier') as $q) {
            if (! $q->supplier?->email) {
                continue;
            }
            $ok = rescue(fn () => Notification::route('mail', $q->supplier->email)->notify(new SupplierDocument(
                $r->tenant, "Solicitud de cotización {$q->code} · {$r->tenant->company_name}",
                ["Le solicitamos su cotización para: {$r->detail}.", 'Adjuntamos el detalle de lo requerido y las condiciones.'],
                FinanceDocumentPdf::quoteRequest($q), "{$q->code}.pdf",
            )) ?? true, false, report: true);
            if ($ok) {
                $q->update(['emailed_at' => now()]);
                $mailed++;
            }
        }
        $without = $requests->count() - $mailed;

        return back()->with('success', "Solicitud de cotización registrada para {$requests->count()} proveedor(es)"
            . ($mailed ? ": {$mailed} enviada(s) por correo" : '') . ($without ? ". {$without} sin correo: descarga su PDF y envíalo." : '.'));
    }

    public function quoteRequestPdf(Request $request, int $id, int $quoteRequest): HttpResponse
    {
        $q = $this->visible($request)->findOrFail($id)->quoteRequests()->findOrFail($quoteRequest);

        return response(FinanceDocumentPdf::quoteRequest($q), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"{$q->code}.pdf\"", 'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Columnas del Excel de ítems (sisgere FrmEXCEL: nombre, cantidad, unidad, justificación) */
    private const ITEM_COLUMNS = [
        'Producto o servicio' => ['product', ['producto', 'servicio', 'nombre', 'descripcion']],
        'Cantidad'            => ['quantity', ['cantidad', 'cant']],
        'Unidad'              => ['unit', ['unidad', 'und', 'medida']],
        'Justificación'       => ['justification', ['justific', 'motivo', 'observ']],
    ];

    /** Lee un Excel de ítems y devuelve las líneas para el formulario (no guarda nada) */
    public function parseItems(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:2048']],
            ['file.required' => 'Elige el archivo Excel.', 'file.mimes' => 'El archivo debe ser Excel (.xlsx, .xls) o CSV.', 'file.max' => 'El archivo pesa más de 2 MB.']);
        $units = self::options()['units'];
        $items = [];
        $errors = [];
        foreach (\App\Modules\Core\Support\SpreadsheetRows::read($request->file('file')->getRealPath(), self::ITEM_COLUMNS) as $line => $row) {
            $product = mb_substr(trim((string) ($row['product'] ?? '')), 0, 100);
            $qty = is_numeric($row['quantity'] ?? null) ? (float) $row['quantity'] : null;
            if ($product === '' || ! $qty || $qty <= 0) {
                $errors[] = "Fila {$line}: " . ($product === '' ? 'falta el producto' : 'la cantidad debe ser un número mayor a cero') . '.';
                continue;
            }
            $unit = mb_strtoupper(trim((string) ($row['unit'] ?? '')));
            $items[] = ['product' => $product, 'quantity' => $qty, 'unit' => in_array($unit, $units, true) ? $unit : 'UNIDAD (BIENES)',
                'justification' => mb_substr(trim((string) ($row['justification'] ?? '')), 0, 250)];
            if (count($items) > 100) {
                $errors[] = 'Máximo 100 ítems por requerimiento.';
                break;
            }
        }
        if ($errors || ! $items) {
            throw ValidationException::withMessages(['file' => $items || $errors ? implode(' ', array_slice($errors, 0, 10)) : 'El archivo no tiene ítems.']);
        }

        return response()->json(['items' => $items]);
    }

    public function itemsTemplate(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'rqi') . '.xlsx';
        \App\Modules\Core\Support\SpreadsheetRows::template($path, 'Ítems', self::ITEM_COLUMNS, ['Llanta 295/80 R22.5', 4, 'UNIDAD (BIENES)', 'Desgaste']);

        return response()->download($path, 'plantilla-items-requerimiento.xlsx')->deleteFileAfterSend();
    }

    // ------------------------------------------------------------------ privado

    /** Anticipos pagados y sin aplicar del proveedor (mostrar_anticipo de sisgere) */
    private function availableAdvances(?int $supplierId, ?string $currency): Builder
    {
        return PaymentInstallment::where('is_advance', true)->where('status', 'paid')->whereNull('advance_applied_to_id')
            ->whereHas('requisition', fn ($q) => $q->where('supplier_id', $supplierId)->when($currency, fn ($q) => $q->where('currency', $currency)));
    }

    /**
     * Precios por ítem de la orden, o null si no se ingresó ninguno (la orden toma el total de la
     * cotización, como antes). Si se ingresó alguno, todos los ítems deben tener precio.
     *
     * @return null|array{items: array<int, array{0: float, 1: float, 2: float}>, taxable: float, igv: float, total: float, rate: float, includes_igv: bool}
     */
    private function pricing(Requisition $r, array $data): ?array
    {
        $sent = collect($data['items'] ?? [])->keyBy('id');
        if ($sent->every(fn ($l) => ($l['unit_price'] ?? null) === null || $l['unit_price'] === '')) {
            return null;
        }

        $lines = [];
        foreach ($r->items as $i => $item) {
            $price = $sent[$item->id]['unit_price'] ?? null;
            if ($price === null || $price === '') {
                throw ValidationException::withMessages(['items' => 'Ingresa el precio de todos los ítems (o de ninguno, para usar el total de la cotización).']);
            }
            $lines[$item->id] = ['quantity' => (float) $item->quantity, 'unit_price' => (float) $price, 'discount_percent' => (float) ($sent[$item->id]['discount_percent'] ?? 0)];
        }

        $calc = OrderPricing::compute(array_values($lines), (bool) ($data['prices_include_igv'] ?? true));
        if ($calc['total'] <= 0) {
            throw ValidationException::withMessages(['items' => 'El total de la orden debe ser mayor a cero.']);
        }

        $items = [];
        foreach (array_keys($lines) as $n => $id) {
            $items[$id] = [$lines[$id]['unit_price'], $lines[$id]['discount_percent'], $calc['lines'][$n]];
        }

        return ['items' => $items] + $calc;
    }

    private const DISK = TreasuryWebController::VOUCHER_DISK;

    /** Solo los mensajes propios de este flujo; los genéricos vienen de lang/es/validation.php */
    private const MESSAGES = [
        'expires_at.after_or_equal' => 'La vigencia no puede ser anterior a la fecha de la cotización.',
    ];

    /** Sin requisitions.view_all solo se ven los propios */
    private function visible(Request $request): Builder
    {
        $user = $request->user();

        // Los pagos de RRHH (sueldos) no se listan aquí: tienen su pantalla. La jefatura inmediata ve además los Express de su equipo
        return Requisition::query()->whereNull('payroll_concept')->unless(
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
            'vehicle_id'            => ['nullable', Rule::exists('vehicles', 'id')->where('tenant_id', current_tenant_id_or_null())->whereNull('deleted_at')],
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
        // Con vehículo del maestro, la placa sale de él (la columna plate queda para los migrados)
        if (! empty($data['vehicle_id'])) {
            $data['plate'] = Vehicle::find($data['vehicle_id'])?->plate;
        }

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
                ...$r->only(['id', 'detail', 'area', 'requested_by', 'email', 'classification', 'priority', 'plate', 'km', 'agency', 'site', 'delivery_place', 'is_express', 'amount', 'vehicle_id']),
                'code'         => $r->exists ? $r->code() : null,
                'requested_at' => ($r->requested_at ?? now())->format('Y-m-d'),
                'items'        => $r->exists
                    ? $r->items->map(fn ($i) => ['product' => $i->product?->name, 'unit' => $i->product?->unit, 'quantity' => (float) $i->quantity, 'justification' => $i->justification ?? ''])
                    : [],
            ],
            'products' => Product::orderBy('name')->limit(1000)->get(['name', 'unit']),
            'options'  => self::options(),
            'vehicles' => Vehicle::where('is_active', true)->orderBy('plate')->get(['id', 'plate', 'brand', 'model', 'type']),
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
            'cash_flow_concepts' => config('finance.cash_flow_concepts'),
            'detraction_reasons' => collect(config('finance.detraction_reasons'))->map(fn ($r, $code) => ['code' => (string) $code, 'label' => "{$code} - {$r[0]}", 'percent' => $r[1]])->values(),
            'units'           => ['UNIDAD (BIENES)', 'UNIDAD (SERVICIOS)', 'CAJA', 'PAQUETE', 'JUEGO', 'KIT', 'PAR', 'DOCENA', 'KILOGRAMO', 'GRAMO',
                'LITRO', 'GALON', 'METRO', 'METRO CUADRADO', 'METRO CUBICO', 'TONELADAS', 'BOLSA', 'BALDE', 'CILINDRO', 'RESMA', 'PIEZAS', 'TUBOS'],
        ];
    }
}
