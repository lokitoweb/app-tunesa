<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\UniformDelivery;
use App\Models\UniformSize;
use App\Models\User;
use App\Services\UniformReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dotación para logística: tallas de todo el personal, resumen por talla,
 * exportación y registro de entregas.
 */
class UniformAdminController extends Controller
{
    public function __construct(private readonly UniformReportService $report) {}

    /** GET /api/admin/uniforms?branch_id= */
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id') ?: null;

        return response()->json([
            'success' => true,
            'data'    => [
                'employees' => $this->report->rows($branchId),
                'summary'   => $this->report->summary($branchId),
            ],
        ]);
    }

    /** GET /api/admin/uniforms/export (CSV) */
    public function export(Request $request)
    {
        $branchId = $request->integer('branch_id') ?: null;

        return $this->report->download($branchId, 'tallas_dotacion_' . now()->format('Ymd') . '.csv');
    }

    /** PUT /api/admin/employees/{id}/uniform-sizes */
    public function updateSizes(Request $request, int $id): JsonResponse
    {
        $employee  = User::findOrFail($id);
        $validated = $request->validate([
            'shirt_size' => ['nullable', Rule::in(UniformSize::SHIRT_SIZES)],
            'pants_size' => ['nullable', Rule::in(array_merge(UniformSize::PANTS_SIZES, UniformSize::SHIRT_SIZES))],
            'shoe_size'  => ['nullable', Rule::in(UniformSize::SHOE_SIZES)],
            'shoe_type'  => ['nullable', Rule::in(array_keys(UniformSize::SHOE_TYPES))],
            'notes'      => ['nullable', 'string', 'max:255'],
        ]);

        $sizes = UniformSize::updateOrCreate(
            ['user_id' => $employee->id],
            $validated + ['tenant_id' => $employee->tenant_id, 'updated_by' => $request->user()->id],
        );

        return response()->json(['success' => true, 'data' => $sizes->toApi()]);
    }

    /** POST /api/admin/uniforms/deliveries */
    public function storeDelivery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'      => ['required', 'integer'],
            'item'         => ['required', Rule::in(array_keys(UniformDelivery::ITEMS))],
            'size'         => ['nullable', 'string', 'max:10'],
            'quantity'     => ['required', 'integer', 'between:1,50'],
            'delivered_at' => ['required', 'date', 'before_or_equal:today'],
            'notes'        => ['nullable', 'string', 'max:255'],
        ]);

        $employee = User::findOrFail($validated['user_id']);

        $delivery = UniformDelivery::create($validated + [
            'tenant_id'    => $employee->tenant_id,
            'delivered_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'message' => 'Entrega registrada.', 'data' => $delivery->toApi()], 201);
    }
}
