<?php

namespace App\Modules\Onboarding\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Onboarding\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Incorporación del colaborador en la app:
 *   GET  /api/onboarding         → estado, etapas y lo que falta en cada una
 *   POST /api/onboarding/submit  → envía a RRHH cuando las etapas obligatorias están completas
 *
 * Cada etapa se completa con los endpoints que ya existen (ficha, tallas,
 * legajo, capacitaciones); aquí solo se consulta y se envía.
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->onboarding->summary($request->user())]);
    }

    public function submit(Request $request): JsonResponse
    {
        $this->onboarding->submit($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Enviamos tu incorporación a RRHH. Te avisaremos cuando la aprueben.',
            'data'    => $this->onboarding->summary($request->user()->fresh()),
        ]);
    }
}
