<?php

namespace App\Modules\Core\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Illuminate\Http\JsonResponse;

/**
 * RoleController
 * Matriz de roles y permisos (solo lectura en Fase 1).
 *
 * GET /api/admin/roles
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::system()
            ->where('slug', '!=', Role::SUPER_ADMIN)
            ->with('permissions:id,slug')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'roles' => $roles->map(fn (Role $r) => [
                    'slug'        => $r->slug,
                    'name'        => $r->name,
                    'description' => $r->description,
                    'permissions' => $r->permissions->pluck('slug')->values(),
                ]),
                'permissions' => Permission::orderBy('group')->orderBy('slug')
                    ->get(['slug', 'name', 'group'])
                    ->groupBy('group'),
            ],
        ]);
    }
}
