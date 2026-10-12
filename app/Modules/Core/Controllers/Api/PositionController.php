<?php

namespace App\Modules\Core\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de cargos de la empresa para la app (RRHH y supervisores eligen el
 * cargo al registrar o editar a un colaborador). Se administra en el panel.
 */
class PositionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $positions = Position::with('department:id,name')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data'    => $positions->map(fn (Position $p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'code'        => $p->code,
                'description' => $p->description,
                'department'  => $p->department ? ['id' => $p->department->id, 'name' => $p->department->name] : null,
                'is_roaming'  => $p->is_roaming,
                'is_active'   => $p->is_active,
            ])->values(),
        ]);
    }
}
