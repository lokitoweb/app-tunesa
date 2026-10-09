<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CompanyConfiguration;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\UniformDelivery;
use App\Models\UniformSize;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentStorageService;
use App\Services\SecureFileUrl;
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
    public function index(Request $request): View
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
            ->paginate(20)
            ->withQueryString();

        $counts = EmployeeDocument::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $expiring = EmployeeDocument::with('user:id,first_name,last_name')
            ->where('status', 'approved')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(30)->toDateString())
            ->orderBy('expires_at')
            ->limit(10)
            ->get();

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
    public function employeeFile(int $id): View
    {
        $employee = User::with(['profile.verifier', 'uniformSize', 'branches'])->findOrFail($id);
        abort_unless(Auth::user()->canManage($employee), 403);

        $documents  = $employee->documents()->with('reviewer:id,first_name,last_name')->latest()->get();
        $deliveries = $employee->uniformDeliveries()->with('deliverer:id,first_name,last_name')->latest('delivered_at')->get();
        $profile    = $employee->profile ?? new EmployeeProfile();
        $config     = CompanyConfiguration::current();
        $revealed   = session('revealed_profile') === $employee->id;

        return view('employees.file', compact('employee', 'documents', 'deliveries', 'profile', 'config', 'revealed'));
    }

    public function updateProfile(Request $request, int $id): RedirectResponse
    {
        $employee = User::findOrFail($id);
        abort_unless(Auth::user()->canManage($employee), 403);

        $validated = $request->validate([
            'labor_regime'            => ['nullable', Rule::in(array_keys(EmployeeProfile::LABOR_REGIMES))],
            'pension_system'          => ['nullable', Rule::in(array_keys(EmployeeProfile::PENSION_SYSTEMS))],
            'pension_fund'            => ['nullable', 'string', 'max:100'],
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

        $profile->save();
        $this->audit->log('profile.updated_by_hr', $profile, ['fields' => array_keys($validated)]);

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
        $profile->update([
            'payment_info_status'      => $validated['decision'],
            'payment_rejection_reason' => $validated['decision'] === 'rejected' ? $validated['reason'] : null,
            'payment_verified_by'      => Auth::id(),
            'payment_verified_at'      => now(),
        ]);
        $this->audit->log("payment_info.{$validated['decision']}", $profile);

        return back()->with('success', $validated['decision'] === 'verified' ? 'Datos de pago verificados.' : 'Datos de pago observados.');
    }

    /** Muestra los números completos en la siguiente carga (auditado) */
    public function reveal(int $id): RedirectResponse
    {
        $employee = User::findOrFail($id);
        abort_unless(Auth::user()->canManage($employee), 403);

        $this->audit->log('payment_info.revealed', $employee);

        return back()->with('revealed_profile', $employee->id);
    }

    public function upload(Request $request, int $id): RedirectResponse
    {
        $employee  = User::findOrFail($id);
        abort_unless(Auth::user()->canManage($employee), 403);

        $validated = $request->validate([
            'type'       => ['required', Rule::in(array_keys(EmployeeDocument::TYPES))],
            'file'       => DocumentStorageService::fileRules(CompanyConfiguration::current()),
            'title'      => ['nullable', 'string', 'max:150'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $this->documents->store($employee, $request->file('file'), $validated['type'], Auth::user(), $validated);

        return back()->with('success', 'Documento agregado al legajo.');
    }
}
