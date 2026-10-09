<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\CompanyConfiguration;
use App\Models\UniformSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Dotación": tallas de indumentaria e historial de entregas del colaborador.
 */
class UniformController extends Controller
{
    /** GET /api/employee/uniform-sizes */
    public function show(Request $request): JsonResponse
    {
        if (! CompanyConfiguration::current()->allows('mobile_uniforms_enabled')) {
            return $this->disabled();
        }

        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'sizes'      => $user->uniformSize?->toApi(),
                'deliveries' => $user->uniformDeliveries()->latest('delivered_at')->get()->map->toApi(),
            ],
        ]);
    }

    /** PUT /api/employee/uniform-sizes */
    public function update(Request $request): JsonResponse
    {
        if (! CompanyConfiguration::current()->allows('mobile_uniforms_edit')) {
            return $this->disabled('Tu empresa no permite cambiar las tallas desde la app.');
        }

        $validated = $request->validate([
            'shirt_size' => ['required', Rule::in(UniformSize::SHIRT_SIZES)],
            'pants_size' => ['required', Rule::in(array_merge(UniformSize::PANTS_SIZES, UniformSize::SHIRT_SIZES))],
            'shoe_size'  => ['required', Rule::in(UniformSize::SHOE_SIZES)],
            'shoe_type'  => ['nullable', Rule::in(array_keys(UniformSize::SHOE_TYPES))],
            'notes'      => ['nullable', 'string', 'max:255'],
        ]);

        $user  = $request->user();
        $sizes = UniformSize::updateOrCreate(
            ['user_id' => $user->id],
            $validated + ['tenant_id' => $user->tenant_id, 'updated_by' => $user->id],
        );

        return response()->json(['success' => true, 'message' => 'Tallas guardadas.', 'data' => $sizes->toApi()]);
    }

    private function disabled(string $message = 'Tu empresa no habilitó esta sección en la app.'): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => 'MODULE_DISABLED'], 403);
    }
}
