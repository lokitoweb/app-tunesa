<?php

namespace App\Modules\Core\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AccountDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/auth/account-deletion — la persona pide desde la app que se
 * elimine su cuenta y sus datos (Mi perfil › Privacidad y datos).
 */
class AccountDeletionController extends Controller
{
    public function store(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $user = $request->user();

        $deletionRequest = $deletion->request($user, $user->email, $data['reason'] ?? null, 'app');

        return response()->json([
            'success' => true,
            'message' => 'Recibimos tu pedido. RRHH eliminará tu cuenta y tus datos en un máximo de 30 días y te avisará por correo.',
            'data'    => [
                'id'         => $deletionRequest->id,
                'status'     => $deletionRequest->status,
                'created_at' => $deletionRequest->created_at?->toIso8601String(),
            ],
        ], 202);
    }
}
