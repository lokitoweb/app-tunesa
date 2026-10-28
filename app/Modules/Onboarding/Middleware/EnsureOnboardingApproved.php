<?php

namespace App\Modules\Onboarding\Middleware;

use App\Modules\Attendance\Services\Attendance\PunchAttemptRecorder;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Onboarding\Models\OnboardingProcess;
use App\Modules\Onboarding\Services\OnboardingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candado de incorporación (alias onboarding.approved): nadie marca hasta que
 * RRHH apruebe su incorporación. Va en entrada, descansos, salida y sync-batch,
 * después de la empresa, la sesión y el permiso.
 *
 * 403 ONBOARDING_PENDING con la etapa que falta; el intento queda registrado.
 * Si la empresa apagó el módulo Onboarding, no bloquea.
 */
class EnsureOnboardingApproved
{
    public const MESSAGE = 'Antes de marcar, completa tu incorporación y espera la aprobación de RRHH.';

    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly TenantContext $tenants,
    ) {}

    /** ¿La incorporación pendiente le impide marcar? (la app y Mi espacio usan esta misma regla) */
    public function blocks(?\App\Modules\Core\Models\User $user): bool
    {
        return $user && $user->onboarding_status !== OnboardingProcess::APPROVED
            && $this->onboarding->enabledFor($this->tenants->getOrNull() ?? $user->tenant);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $this->blocks($user)) {
            return $next($request);
        }

        $summary = $this->onboarding->summary($user);
        $type    = (string) ($request->input('punches.0.type') ?? str_replace('-', '_', (string) $request->segment(3)));

        app(PunchAttemptRecorder::class)->recordBlocked($user, $request, $type, 'ONBOARDING_PENDING', self::MESSAGE);

        return response()->json([
            'success' => false,
            'message' => self::MESSAGE,
            'code'    => 'ONBOARDING_PENDING',
            'data'    => [
                'status'     => $summary['status'],
                'next_stage' => $summary['next_stage'],
            ],
        ], 403);
    }
}
