<?php

namespace App\Modules\Onboarding\Services;

use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\UniformSize;
use App\Modules\ModuleRegistry;
use App\Modules\Onboarding\Models\OnboardingProcess;
use App\Modules\Onboarding\Models\OnboardingStage;
use App\Modules\Onboarding\Notifications\OnboardingReviewed;
use App\Modules\Onboarding\Notifications\OnboardingSubmitted;
use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingEnrollment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Incorporación: evalúa cada etapa contra su fuente (ficha, tallas, legajo,
 * inducción), recibe el envío del colaborador y la decisión de RRHH.
 *
 * Una etapa no guarda estado propio: se calcula siempre, así nunca se
 * desincroniza de los datos. Lo único guardado es el proceso (enviado,
 * aprobado, observado) y su copia en users.onboarding_status.
 */
class OnboardingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** ¿El candado aplica a esta empresa? (módulo encendido) */
    public function enabledFor(?Tenant $tenant): bool
    {
        return $tenant !== null && ModuleRegistry::enabledFor($tenant, 'Onboarding');
    }

    /**
     * Etapas de la empresa en orden; la primera vez se crean las 4 por defecto.
     *
     * @return Collection<int, OnboardingStage>
     */
    public function stagesFor(int $tenantId): Collection
    {
        $stages = OnboardingStage::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('position')->get();

        if ($stages->count() < count(OnboardingStage::KEYS)) {
            $position = 0;
            foreach (array_keys(OnboardingStage::KEYS) as $key) {
                OnboardingStage::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'key' => $key],
                    ['position' => $position, 'is_required' => true],
                );
                $position++;
            }
            $stages = OnboardingStage::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('position')->get();
        }

        return $stages;
    }

    public function processFor(User $user): OnboardingProcess
    {
        return OnboardingProcess::withoutGlobalScopes()->firstOrCreate(
            ['user_id' => $user->id],
            ['tenant_id' => $user->tenant_id, 'status' => $user->onboarding_status === OnboardingProcess::APPROVED ? OnboardingProcess::APPROVED : OnboardingProcess::PENDING],
        );
    }

    /** Ingreso nuevo: queda pendiente hasta que RRHH apruebe */
    public function startFor(User $user): void
    {
        if (! $user->tenant_id) {
            return;
        }

        OnboardingProcess::withoutGlobalScopes()->updateOrCreate(['user_id' => $user->id], ['tenant_id' => $user->tenant_id, 'status' => OnboardingProcess::PENDING]);
        $user->forceFill(['onboarding_status' => OnboardingProcess::PENDING])->saveQuietly();
    }

    /**
     * Estado de cada etapa para el colaborador.
     *
     * @return list<array{key: string, title: string, description: string, required: bool, status: string, missing: list<string>}>
     *         status: completed | pending | not_applicable
     */
    public function evaluate(User $user): array
    {
        $config = CompanyConfiguration::forTenantId($user->tenant_id);

        return $this->stagesFor($user->tenant_id)->map(function (OnboardingStage $stage) use ($user, $config) {
            [$status, $missing] = match ($stage->key) {
                OnboardingStage::PERSONAL_DATA => $this->personalData($user, $config),
                OnboardingStage::UNIFORMS      => $config->allows('mobile_uniforms_enabled') ? $this->uniforms($user) : ['not_applicable', []],
                OnboardingStage::DOCUMENTS     => $config->allows('mobile_documents_enabled') ? $this->documents($user, $config) : ['not_applicable', []],
                OnboardingStage::INDUCTION     => $this->induction($user, $stage),
                default                        => ['not_applicable', []],
            };

            return [
                'key'         => $stage->key,
                'title'       => $stage->title(),
                'description' => $stage->description(),
                'required'    => $stage->is_required,
                'status'      => $status,
                'missing'     => $missing,
            ];
        })->values()->all();
    }

    /** Primera etapa obligatoria sin completar, o null si ya puede enviar */
    public function nextPendingStage(array $stages): ?array
    {
        foreach ($stages as $stage) {
            if ($stage['required'] && $stage['status'] === 'pending') {
                return $stage;
            }
        }

        return null;
    }

    /** Resumen para la app y el panel */
    public function summary(User $user): array
    {
        $process = $this->processFor($user);
        $stages  = $process->isApproved() ? [] : $this->evaluate($user);
        $next    = $this->nextPendingStage($stages);

        return [
            'status'           => $process->status,
            'can_punch'        => $process->isApproved() || ! $this->enabledFor($user->tenant),
            'can_submit'       => in_array($process->status, [OnboardingProcess::PENDING, OnboardingProcess::REJECTED], true) && $next === null,
            'next_stage'       => $next['key'] ?? null,
            'rejection_reason' => $process->status === OnboardingProcess::REJECTED ? $process->rejection_reason : null,
            'submitted_at'     => $process->submitted_at?->toIso8601String(),
            'approved_at'      => $process->approved_at?->toIso8601String(),
            'stages'           => $stages,
        ];
    }

    public function submit(User $user): OnboardingProcess
    {
        $process = $this->processFor($user);

        if (! in_array($process->status, [OnboardingProcess::PENDING, OnboardingProcess::REJECTED], true)) {
            throw ValidationException::withMessages(['onboarding' => $process->isApproved()
                ? 'Tu incorporación ya fue aprobada.'
                : 'Ya enviaste tu incorporación. RRHH la está revisando.']);
        }

        if ($next = $this->nextPendingStage($this->evaluate($user))) {
            throw ValidationException::withMessages(['onboarding' => "Completa la etapa «{$next['title']}» antes de enviar."]);
        }

        $process->forceFill(['status' => OnboardingProcess::SUBMITTED, 'submitted_at' => now(), 'rejection_reason' => null])->save();
        $this->sync($user, $process);
        $this->audit->log('onboarding.submitted', $user, ['name' => $user->full_name]);
        $this->notifyReviewers($user);

        return $process;
    }

    public function approve(OnboardingProcess $process, User $reviewer): void
    {
        $this->assertReviewable($process, $reviewer);

        DB::transaction(function () use ($process, $reviewer) {
            $process->forceFill([
                'status' => OnboardingProcess::APPROVED, 'reviewed_by' => $reviewer->id,
                'approved_at' => now(), 'rejected_at' => null, 'rejection_reason' => null,
            ])->save();
            $this->sync($process->user, $process);
        });

        $this->audit->log('onboarding.approved', $process->user, ['name' => $process->user->full_name]);
        $this->safely(fn () => $process->user->notify(new OnboardingReviewed($process)));
    }

    public function reject(OnboardingProcess $process, User $reviewer, string $reason): void
    {
        $this->assertReviewable($process, $reviewer);

        DB::transaction(function () use ($process, $reviewer, $reason) {
            $process->forceFill([
                'status' => OnboardingProcess::REJECTED, 'reviewed_by' => $reviewer->id,
                'rejected_at' => now(), 'approved_at' => null, 'rejection_reason' => $reason,
            ])->save();
            $this->sync($process->user, $process);
        });

        $this->audit->log('onboarding.rejected', $process->user, ['name' => $process->user->full_name, 'reason' => $reason]);
        $this->safely(fn () => $process->user->notify(new OnboardingReviewed($process)));
    }

    // -------------------------------------------------------------------------

    private function personalData(User $user, CompanyConfiguration $config): array
    {
        $profile = EmployeeProfile::withoutGlobalScopes()->where('user_id', $user->id)->first();
        $missing = [];

        foreach (['address' => 'Dirección', 'district' => 'Distrito', 'emergency_contact_name' => 'Contacto de emergencia', 'emergency_contact_phone' => 'Teléfono de emergencia'] as $field => $label) {
            if (! filled($profile?->{$field})) {
                $missing[] = $label;
            }
        }
        // La cuenta de abono solo se pide si la app muestra la sección de datos de pago
        if ($config->allows('mobile_payment_info_enabled') && ! $profile?->hasCompletePaymentInfo()) {
            $missing[] = 'Cuenta de abono y régimen de pensiones';
        }

        return [$missing ? 'pending' : 'completed', $missing];
    }

    private function uniforms(User $user): array
    {
        $sizes   = UniformSize::withoutGlobalScopes()->where('user_id', $user->id)->first();
        $missing = array_keys(array_filter([
            'Talla de camisa'   => ! $sizes?->shirt_size,
            'Talla de pantalón' => ! $sizes?->pants_size,
            'Talla de calzado'  => ! $sizes?->shoe_size,
        ]));

        return [$missing ? 'pending' : 'completed', $missing];
    }

    private function documents(User $user, CompanyConfiguration $config): array
    {
        $types = array_values($config->requiredDocuments());
        if (! $types) {
            return ['not_applicable', []];
        }

        $have = EmployeeDocument::withoutGlobalScopes()->where('user_id', $user->id)->current()
            ->whereIn('type', $types)->distinct()->pluck('type')->all();

        $missing = array_map(fn ($t) => EmployeeDocument::TYPES[$t] ?? $t, array_values(array_diff($types, $have)));

        return [$missing ? 'pending' : 'completed', $missing];
    }

    private function induction(User $user, OnboardingStage $stage): array
    {
        $course = ModuleRegistry::enabledFor($user->tenant, 'Training') ? $this->inductionCourse($user->tenant_id, $stage) : null;
        if (! $course) {
            return ['not_applicable', []];
        }

        $done = TrainingEnrollment::withoutGlobalScopes()
            ->where('user_id', $user->id)->where('course_id', $course->id)->whereNotNull('completed_at')->exists();

        return [$done ? 'completed' : 'pending', $done ? [] : [$course->title]];
    }

    /** El curso elegido en la etapa, o el primer curso publicado de categoría Inducción */
    public function inductionCourse(int $tenantId, OnboardingStage $stage): ?TrainingCourse
    {
        $query = TrainingCourse::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'published');

        return $stage->course_id
            ? (clone $query)->find($stage->course_id)
            : $query->where('category', 'induccion')->orderBy('id')->first();
    }

    private function sync(User $user, OnboardingProcess $process): void
    {
        $user->forceFill(['onboarding_status' => $process->status])->saveQuietly();
    }

    private function assertReviewable(OnboardingProcess $process, User $reviewer): void
    {
        abort_if($reviewer->id === $process->user_id, 403, 'No puedes aprobar tu propia incorporación.');
        abort_unless($reviewer->isSuperAdmin() || $reviewer->tenant_id === $process->tenant_id, 404);
    }

    private function notifyReviewers(User $user): void
    {
        $this->safely(function () use ($user) {
            User::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->where('status', 'active')
                ->whereIn('role', ['company_admin', 'hr_manager'])->get()
                ->filter(fn (User $u) => $u->hasPermission('onboarding.review') && $u->id !== $user->id)
                ->each(fn (User $u) => $u->notify(new OnboardingSubmitted($user)));
        });
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
