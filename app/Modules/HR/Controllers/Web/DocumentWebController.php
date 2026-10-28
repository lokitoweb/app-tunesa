<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Position;
use App\Modules\HR\Models\Shift;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\UniformDelivery;
use App\Modules\HR\Models\UniformSize;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\HR\Services\DocumentStorageService;
use App\Modules\Core\Services\SecureFileUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Legajo digital en el panel: bandeja de revisión de documentos y
 * ficha completa del colaborador (datos de pago, régimen, tallas, entregas).
 */
class DocumentWebController extends Controller
{
    public function __construct(
        private readonly DocumentStorageService $documents,
        private readonly SecureFileUrl $urls,
        private readonly AuditLogger $audit,
    ) {}

    /** Bandeja de revisión */
    public function index(Request $request): View|\Illuminate\Http\Response
    {
        $status = $request->input('status', 'pending');

        $documents = EmployeeDocument::with(['user:id,first_name,last_name,employee_code,document_number', 'reviewer:id,first_name,last_name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->search, fn ($q, $s) => $q->whereHas('user', fn ($u) => $u
                ->where('first_name', 'like', "%{$s}%")
                ->orWhere('last_name', 'like', "%{$s}%")
                ->orWhere('document_number', 'like', "%{$s}%")
                ->orWhere('employee_code', 'like', "%{$s}%")))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $counts = EmployeeDocument::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $expiring = EmployeeDocument::with('user:id,first_name,last_name')
            ->where('status', 'approved')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(30)->toDateString())
            ->orderBy('expires_at')
            ->limit(10)
            ->get();

        if ($request->ajax()) {
            return response()->view('documents.partials.table', compact('documents', 'status'));
        }

        return view('documents.index', compact('documents', 'counts', 'status', 'expiring'));
    }

    /** Abre el documento con una URL temporal (queda en la bitácora) */
    public function open(int $id): RedirectResponse
    {
        $document = EmployeeDocument::findOrFail($id);
        $this->audit->log('document.viewed', $document);

        return redirect()->away($this->urls->make($document->disk, $document->path, 'document', $document->id));
    }

    public function review(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'decision'   => ['required', 'in:approved,rejected'],
            'reason'     => ['required_if:decision,rejected', 'nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ], ['reason.required_if' => 'Indica el motivo del rechazo para que el colaborador lo corrija.']);

        $document = EmployeeDocument::findOrFail($id);

        if ($document->status !== 'pending') {
            return back()->with('error', 'Ese documento ya fue revisado.');
        }

        $validated['decision'] === 'approved'
            ? $this->documents->approve($document, Auth::user(), $validated['expires_at'] ?? null)
            : $this->documents->reject($document, Auth::user(), $validated['reason']);

        return back()->with('success', 'Documento ' . ($validated['decision'] === 'approved' ? 'aprobado' : 'rechazado') . '.');
    }

    /** Ficha completa del colaborador (legajo) */
    public function employeeFile(Request $request, int $id): View
    {
        $employee = User::with(['profile.verifier', 'uniformSize', 'branches'])->findOrFail($id);
        abort_unless(Auth::user()->canManage($employee), 403);

        $documents  = $employee->documents()->with('reviewer:id,first_name,last_name')->latest()->get();
        $deliveries = $employee->uniformDeliveries()->with('deliverer:id,first_name,last_name')->latest('delivered_at')->get();
        $profile    = $employee->profile ?? new EmployeeProfile();
        $config     = CompanyConfiguration::forTenantId($employee->tenant_id);

        // Los números de cuenta completos solo con profiles.view_sensitive (matriz de roles),
        // y cada vez que se muestran queda en la bitácora.
        $canViewSensitive = Auth::user()->hasPermission('profiles.view_sensitive');
        $revealed = session('revealed_profile') === $employee->id;
        if (! $revealed && $canViewSensitive && ($request->ajax() || $request->boolean('reveal'))) {
            $revealed = true;
            $this->audit->log('payment_info.revealed', $employee);
        }

        $tenantId = $employee->tenant_id;
        $branches = Branch::forTenant($tenantId)->where('is_active', true)->orderBy('name')->get();
        $shifts = Shift::forTenant($tenantId)->where('is_active', true)->orderBy('name')->get();
        $departments = Department::forTenant($tenantId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $positionsCatalog = Position::forTenant($tenantId)->with('department:id,name')->where('is_active', true)->orderBy('name')->get();
        $laborRegimes = \App\Modules\HR\Models\LaborRegime::options($tenantId, $profile->labor_regime);

        if ($request->ajax()) {
            return view('employees.partials.modal-file-content', compact('employee', 'documents', 'deliveries', 'profile', 'config', 'revealed', 'canViewSensitive', 'laborRegimes'));
        }

        return view('employees.file', compact('employee', 'documents', 'deliveries', 'profile', 'config', 'revealed', 'canViewSensitive', 'branches', 'shifts', 'departments', 'positionsCatalog', 'laborRegimes'));
    }

    public function updateProfile(Request $request, int $id): \Illuminate\Http\JsonResponse|RedirectResponse
    {
        $employee = User::findOrFail($id);
        abort_unless(Auth::user()->canManage($employee), 403);

        $validated = $request->validate([
            'labor_regime'            => ['nullable', Rule::in(array_keys(\App\Modules\HR\Models\LaborRegime::options($employee->tenant_id, $employee->profile?->labor_regime)))],
            'pension_system'          => ['nullable', Rule::in(array_keys(EmployeeProfile::PENSION_SYSTEMS))],
            'pension_fund'            => ['nullable', 'string', 'max:100'],
            'pension_code'            => ['nullable', 'string', 'max:50'],
            'health_insurance'        => ['nullable', Rule::in(array_keys(EmployeeProfile::HEALTH_INSURANCES))],
            'bank_name'               => ['nullable', 'string', 'max:100'],
            'bank_account_type'       => ['nullable', Rule::in(array_keys(EmployeeProfile::BANK_ACCOUNT_TYPES))],
            'bank_account_currency'   => ['nullable', 'string', 'size:3'],
            'bank_account_number'     => ['nullable', 'string', 'regex:/^[0-9\- ]{6,30}$/'],
            'interbank_code'          => ['nullable', 'string', 'regex:/^[0-9\- ]{10,30}$/'],
            'cts_bank_name'           => ['nullable', 'string', 'max:100'],
            'cts_account_number'      => ['nullable', 'string', 'regex:/^[0-9\- ]{6,30}$/'],
            'cts_currency'            => ['nullable', 'string', 'size:3'],
            'address'                 => ['nullable', 'string', 'max:255'],
            'district'                => ['nullable', 'string', 'max:100'],
            'emergency_contact_name'  => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
        ]);

        // Los números en blanco no pisan los guardados (el formulario no los precarga)
        foreach (['bank_account_number', 'interbank_code', 'cts_account_number'] as $field) {
            if (empty($validated[$field])) {
                unset($validated[$field]);
            } else {
                $validated[$field] = preg_replace('/\D/', '', $validated[$field]);
            }
        }
        if (isset($validated['pension_code']) && empty($validated['pension_code'])) {
            unset($validated['pension_code']);
        }

        $profile = EmployeeProfile::firstOrNew(['user_id' => $employee->id]);
        $profile->fill($validated + ['tenant_id' => $employee->tenant_id]);

        if ($profile->isDirty(EmployeeProfile::EMPLOYEE_PAYMENT_FIELDS)) {
            $profile->fill([
                'payment_info_status'     => $profile->hasCompletePaymentInfo() ? 'verified' : 'incomplete',
                'payment_info_updated_at' => now(),
                'payment_verified_by'     => Auth::id(),
                'payment_verified_at'     => now(),
            ]);
        }

        $this->audit->saveWithAudit('profile.updated_by_hr', $profile, ['name' => $employee->full_name]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Ficha del colaborador actualizada con éxito.',
            ]);
        }

        return back()->with('success', 'Ficha laboral actualizada.');
    }

    public function reviewPayment(Request $request, int $id): RedirectResponse
    {
        $employee = User::findOrFail($id);
        abort_unless(Auth::user()->canManage($employee), 403);

        $validated = $request->validate([
            'decision' => ['required', 'in:verified,rejected'],
            'reason'   => ['required_if:decision,rejected', 'nullable', 'string', 'max:255'],
        ]);

        $profile = EmployeeProfile::where('user_id', $employee->id)->firstOrFail();
        $this->audit->updateWithAudit("payment_info.{$validated['decision']}", $profile, [
            'payment_info_status'      => $validated['decision'],
            'payment_rejection_reason' => $validated['decision'] === 'rejected' ? $validated['reason'] : null,
            'payment_verified_by'      => Auth::id(),
            'payment_verified_at'      => now(),
        ]);

        return back()->with('success', $validated['decision'] === 'verified' ? 'Datos de pago verificados.' : 'Datos de pago observados.');
    }

    /** Muestra los números completos en la siguiente carga (auditado) */
    public function reveal(int $id): RedirectResponse
    {
        $employee = User::findOrFail($id);
        abort_unless(Auth::user()->canManage($employee), 403);

        $this->audit->log('payment_info.revealed', $employee);

        session(['revealed_profile' => $employee->id]);

        return redirect()->route('employees.file', $id)
            ->with('revealed_profile', $employee->id)
            ->with('success', 'Números de cuenta revelados y auditados.');
    }

    public function upload(Request $request, int $id): RedirectResponse
    {
        $employee  = User::findOrFail($id);
        abort_unless(Auth::user()->canManage($employee), 403);

        $validated = $request->validate([
            'type'       => ['required', Rule::in(array_keys(EmployeeDocument::TYPES))],
            'file'       => DocumentStorageService::fileRules(CompanyConfiguration::current()),
            'title'      => ['nullable', 'string', 'max:150'],
            // Opcional siempre (el examen médico puede no tener fecha aún): sin ella solo no hay alerta
            'expires_at' => ['nullable', 'date'],
        ]);

        $this->documents->store($employee, $request->file('file'), $validated['type'], Auth::user(), $validated);

        $noDate = in_array($validated['type'], EmployeeDocument::EXPIRING_TYPES, true) && empty($validated['expires_at']);

        return back()->with('success', 'Documento agregado al legajo.' . ($noDate ? ' Sin fecha de vencimiento no se enviará alerta: agrégala cuando la tengas.' : ''));
    }
}
