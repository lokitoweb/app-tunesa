<?php

namespace App\Modules\Onboarding\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\Onboarding\Models\OnboardingProcess;
use App\Modules\Onboarding\Models\OnboardingStage;
use App\Modules\Onboarding\Services\OnboardingService;
use App\Modules\Training\Models\TrainingCourse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Incorporación en el panel: bandeja de RRHH (por revisar, en proceso,
 * observadas), detalle con cada etapa, aprobar u observar, y configuración
 * de las etapas de la empresa.
 */
class OnboardingWebController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding) {}

    public function index(Request $request): View
    {
        $tenantId = current_tenant_id_or_null();
        $status   = in_array($request->input('status'), [...OnboardingProcess::STATUSES, 'all'], true) ? $request->input('status') : OnboardingProcess::SUBMITTED;

        $base   = OnboardingProcess::forTenant($tenantId)->whereHas('user');
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $processes = (clone $base)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->input('search'), fn ($q, $search) => $q->whereHas('user', function ($u) use ($search) {
                $u->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('document_number', 'like', "%{$search}%");
            }))
            ->with(['user.branches', 'reviewer:id,first_name,last_name'])
            ->orderByRaw('submitted_at is null')->latest('submitted_at')->latest('id')
            ->paginate(20)->withQueryString();

        $stages = $processes->getCollection()
            ->mapWithKeys(fn (OnboardingProcess $p) => [$p->id => $p->isApproved() ? [] : $this->onboarding->evaluate($p->user)]);

        return view('onboarding.index', compact('processes', 'counts', 'status', 'stages'));
    }

    public function show(int $user): View
    {
        $employee = $this->employee($user);
        $process  = $this->onboarding->processFor($employee)->load('reviewer:id,first_name,last_name');

        return view('onboarding.show', [
            'employee' => $employee,
            'process'  => $process,
            'stages'   => $this->onboarding->evaluate($employee),
            'canAct'   => Auth::id() !== $employee->id,
        ]);
    }

    public function approve(int $user): RedirectResponse
    {
        $employee = $this->employee($user);
        $process  = $this->onboarding->processFor($employee);
        abort_unless(Auth::user()->canManage($employee), 403);

        if ($process->isApproved()) {
            return back()->with('info', 'Esta incorporación ya estaba aprobada.');
        }

        $this->onboarding->approve($process, Auth::user());

        return redirect()->route('onboarding.index')
            ->with('success', "Aprobaste la incorporación de {$employee->full_name}. Ya puede marcar.");
    }

    public function reject(Request $request, int $user): RedirectResponse
    {
        $employee = $this->employee($user);
        abort_unless(Auth::user()->canManage($employee), 403);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:500']],
            ['reason.required' => 'Escribe qué debe corregir el colaborador.', 'reason.min' => 'Escribe qué debe corregir el colaborador.'],
        );

        $this->onboarding->reject($this->onboarding->processFor($employee), Auth::user(), $data['reason']);

        return redirect()->route('onboarding.index')
            ->with('success', "Observaste la incorporación de {$employee->full_name}. Le avisamos en la app.");
    }

    public function settings(): View
    {
        $tenantId = current_tenant_id_or_null();
        abort_if($tenantId === null, 404, 'Elige una empresa para configurar su incorporación.');

        return view('onboarding.settings', [
            'stages'        => $this->onboarding->stagesFor($tenantId)->load('course'),
            'documents'     => array_map(fn ($t) => EmployeeDocument::TYPES[$t] ?? $t, CompanyConfiguration::forTenantId($tenantId)->requiredDocuments()),
            'courses'       => TrainingCourse::forTenant($tenantId)->where('status', 'published')->orderBy('title')->get(['id', 'title', 'category']),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();
        abort_if($tenantId === null, 404);

        $data = $request->validate([
            'required'        => ['array'],
            'required.*'      => [Rule::in(array_keys(OnboardingStage::KEYS))],
            'course_id'       => ['nullable', 'integer', Rule::exists('training_courses', 'id')->where('tenant_id', $tenantId)->where('status', 'published')],
        ]);

        $required = $data['required'] ?? [];
        foreach ($this->onboarding->stagesFor($tenantId) as $stage) {
            $stage->is_required = in_array($stage->key, $required, true);
            if ($stage->key === OnboardingStage::INDUCTION) {
                $stage->course_id = $data['course_id'] ?? null;
            }
            $stage->save();
        }

        app(AuditLogger::class)->log('onboarding.stages_updated', null, [
            'required'  => $required,
            'course_id' => $data['course_id'] ?? null,
        ]);

        return back()->with('success', 'Guardamos las etapas de incorporación.');
    }

    private function employee(int $id): User
    {
        return User::forTenant(current_tenant_id_or_null())->whereNotNull('tenant_id')->findOrFail($id);
    }
}
