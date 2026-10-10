<?php

namespace App\Modules\Payroll\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * PayslipController
 * Gestión de boletas de pago. RRHH las sube, empleados las consultan.
 */
class PayslipController extends Controller
{
    /**
     * Boletas del empleado autenticado (app móvil).
     * GET /api/payslips
     */
    public function index(Request $request): JsonResponse
    {
        if ($blocked = $this->moduleDisabledFor($request->user())) {
            return $blocked;
        }

        $payslips = Payslip::where('tenant_id', current_tenant_id())
            ->where('user_id', $request->user()->id)
            ->published()
            ->orderBy('period_year', 'desc')
            ->orderBy('period_month', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $payslips->map(fn($p) => $this->formatPayslip($p)),
        ]);
    }

    /**
     * Detalle de una boleta (empleado ve la suya, admin ve cualquiera).
     * GET /api/payslips/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($blocked = $this->moduleDisabledFor($request->user())) {
            return $blocked;
        }

        $user    = $request->user();
        $query   = Payslip::where('tenant_id', current_tenant_id());

        // Si es empleado, solo puede ver sus propias boletas
        if ($user->isEmployee()) {
            $query->where('user_id', $user->id)->published();
        }

        $payslip = $query->findOrFail($id);

        // Marcar como leída la primera vez que el empleado la abre
        if ($user->isEmployee()) {
            $payslip->markAsRead();
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatPayslip($payslip, detailed: true),
        ]);
    }

    /**
     * Subir nueva boleta (RRHH).
     * POST /api/admin/payslips
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'       => ['required', 'integer', 'exists:users,id'],
            'period_month'  => ['required', 'integer', 'between:1,12'],
            'period_year'   => ['required', 'integer', 'min:2020'],
            'file'          => ['required', 'file', 'mimes:pdf', 'max:10240'], // 10MB
            'title'         => ['sometimes', 'nullable', 'string', 'max:200'],
            'gross_salary'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'deductions'    => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'net_salary'    => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'breakdown'     => ['sometimes', 'nullable', 'array'],
            'notes'         => ['sometimes', 'nullable', 'string'],
        ]);

        // Verificar que el empleado pertenece al tenant
        $employee = \App\Modules\Core\Models\User::where('tenant_id', current_tenant_id())
            ->findOrFail($validated['user_id']);

        // Guardar el PDF
        $file      = $request->file('file');
        $tenantId  = current_tenant_id();
        $year      = $validated['period_year'];
        $month     = str_pad($validated['period_month'], 2, '0', STR_PAD_LEFT);
        $path      = $file->storeAs(
            "payslips/{$tenantId}/{$employee->id}/{$year}/{$month}",
            "boleta_{$year}_{$month}_{$employee->id}.pdf",
            'private'
        );

        $payslip = Payslip::create([
            'tenant_id'    => $tenantId,
            'user_id'      => $employee->id,
            'uploaded_by'  => $request->user()->id,
            'title'        => $validated['title'] ?? "Boleta de Pago - {$month}/{$year}",
            'period_month' => $validated['period_month'],
            'period_year'  => $validated['period_year'],
            'file_path'    => $path,
            'file_name'    => $file->getClientOriginalName(),
            'file_size'    => $file->getSize(),
            'mime_type'    => 'application/pdf',
            'gross_salary' => $validated['gross_salary'] ?? null,
            'deductions'   => $validated['deductions'] ?? null,
            'net_salary'   => $validated['net_salary'] ?? null,
            'breakdown'    => $validated['breakdown'] ?? null,
            'notes'        => $validated['notes'] ?? null,
            'status'       => 'draft',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Boleta subida correctamente. Publica cuando esté lista.',
            'data'    => $this->formatPayslip($payslip),
        ], 201);
    }

    /**
     * Carga masiva de boletas de un período (multipart, files[]).
     * Cada PDF se asigna por DNI o código de empleado en el nombre del archivo.
     * POST /api/admin/payslips/bulk
     */
    public function bulkStore(Request $request, PayslipImportService $importer): JsonResponse
    {
        $validated = $request->validate([
            'period_month' => ['required', 'integer', 'between:1,12'],
            'period_year'  => ['required', 'integer', 'between:2020,2100'],
            'files'        => ['required', 'array', 'min:1', 'max:500'],
            'files.*'      => ['file', 'mimes:pdf', 'max:10240'],
            'publish_now'  => ['sometimes', 'boolean'],
        ]);

        $result = $importer->import(
            $request->file('files'),
            (int) $validated['period_month'],
            (int) $validated['period_year'],
            $request->user()->id,
            $request->boolean('publish_now'),
        );

        return response()->json([
            'success' => true,
            'message' => (count($result['created']) + count($result['replaced'])) . ' boletas cargadas, ' . count($result['skipped']) . ' omitidas.',
            'data'    => $result,
        ], 201);
    }

    /**
     * Publicar boleta (hacerla visible para el empleado).
     * POST /api/admin/payslips/{id}/publish
     */
    public function publish(int $id): JsonResponse
    {
        $payslip = Payslip::where('tenant_id', current_tenant_id())->findOrFail($id);

        $payslip->update([
            'status'       => 'published',
            'published_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Boleta publicada. El empleado ya puede verla.',
            'data'    => $this->formatPayslip($payslip->fresh()),
        ]);
    }

    /**
     * Boletas de un empleado específico (vista admin/RRHH).
     * GET /api/admin/payslips/employee/{userId}
     */
    public function byEmployee(int $userId): JsonResponse
    {
        $payslips = Payslip::where('tenant_id', current_tenant_id())
            ->where('user_id', $userId)
            ->with('employee:id,first_name,last_name', 'uploader:id,first_name,last_name')
            ->orderBy('period_year', 'desc')
            ->orderBy('period_month', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $payslips->map(fn($p) => $this->formatPayslip($p, detailed: true)),
        ]);
    }

    /**
     * Descarga protegida del PDF.
     * El archivo vive en el disco 'private' y solo se entrega por aquí:
     * el empleado únicamente sus boletas publicadas; RRHH/Admin cualquiera
     * de su empresa (el TenantScope impide ver las de otra empresa).
     *
     * GET /api/payslips/{id}/download
     */
    public function download(Request $request, int $id)
    {
        if ($blocked = $this->moduleDisabledFor($request->user())) {
            return $blocked;
        }

        $user  = $request->user();
        $query = Payslip::query();

        if (! $user->hasPermission('payslips.manage')) {
            $query->where('user_id', $user->id)->published();
        }

        $payslip = $query->findOrFail($id);

        if (! Storage::disk('private')->exists($payslip->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo de la boleta no está disponible.',
                'code'    => 'FILE_NOT_FOUND',
            ], 404);
        }

        if ($payslip->user_id === $user->id) {
            $payslip->markAsRead();
        }

        return Storage::disk('private')->download(
            $payslip->file_path,
            "boleta_{$payslip->period_year}_" . str_pad($payslip->period_month, 2, '0', STR_PAD_LEFT) . '.pdf',
            ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']
        );
    }

    /**
     * Actualizar datos de una boleta y, opcionalmente, reemplazar el PDF.
     * PUT /api/admin/payslips/{id}  (multipart con _method=PUT si incluye archivo)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $payslip = Payslip::findOrFail($id);

        $validated = $request->validate([
            'file'         => ['sometimes', 'file', 'mimes:pdf', 'max:10240'],
            'title'        => ['sometimes', 'string', 'max:200'],
            'gross_salary' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'deductions'   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'net_salary'   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'breakdown'    => ['sometimes', 'nullable', 'array'],
            'notes'        => ['sometimes', 'nullable', 'string'],
            'status'       => ['sometimes', 'in:draft,archived'],
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            Storage::disk('private')->put($payslip->file_path, $file->getContent());
            $validated['file_name'] = $file->getClientOriginalName();
            $validated['file_size'] = $file->getSize();
            // Un PDF nuevo debe volver a leerse
            $validated['read_at']   = null;
        }
        unset($validated['file']);

        $payslip->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Boleta actualizada.',
            'data'    => $this->formatPayslip($payslip->fresh(), detailed: true),
        ]);
    }

    /**
     * Eliminar una boleta en borrador (borra también el PDF, para poder
     * volver a subir la del mismo período). Una boleta ya publicada no se
     * elimina: se archiva con PUT status=archived.
     * DELETE /api/admin/payslips/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $payslip = Payslip::findOrFail($id);

        if ($payslip->isPublished()) {
            return response()->json([
                'success' => false,
                'message' => 'La boleta ya fue publicada. Archívala en lugar de eliminarla.',
                'code'    => 'PAYSLIP_PUBLISHED',
            ], 409);
        }

        Storage::disk('private')->delete($payslip->file_path);
        $payslip->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Boleta eliminada.',
        ]);
    }

    /** Marcar boleta como leída (la app llama esto al abrir el PDF). */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        if ($blocked = $this->moduleDisabledFor($request->user())) {
            return $blocked;
        }

        $payslip = Payslip::where('tenant_id', current_tenant_id())
            ->where('user_id', $request->user()->id)
            ->published()
            ->findOrFail($id);

        $payslip->markAsRead();

        return response()->json(['success' => true]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function formatPayslip(Payslip $payslip, bool $detailed = false): array
    {
        $data = [
            'id'             => $payslip->id,
            'title'          => $payslip->title,
            'period_month'   => $payslip->period_month,
            'period_year'    => $payslip->period_year,
            'period_label'   => $payslip->period_label,
            'status'         => $payslip->status,
            'is_read'        => ! is_null($payslip->read_at),
            'read_at'        => $payslip->read_at?->toIso8601String(),
            'published_at'   => $payslip->published_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['financials'] = [
                'gross_salary' => $payslip->gross_salary,
                'deductions'   => $payslip->deductions,
                'net_salary'   => $payslip->net_salary,
                'breakdown'    => $payslip->breakdown,
            ];
            $data['file'] = [
                'name'     => $payslip->file_name,
                'size_kb'  => $payslip->file_size ? round($payslip->file_size / 1024, 1) : null,
            ];
        }

        return $data;
    }

    /**
     * "Boletas de pago" apagado en la configuración de la app: el colaborador
     * no puede listarlas ni descargarlas (RRHH y admins sí).
     */
    private function moduleDisabledFor(\App\Modules\Core\Models\User $user): ?JsonResponse
    {
        if (! $user->isEmployee() || \App\Modules\Core\Models\CompanyConfiguration::current()->allows('mobile_payslips_enabled')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Tu empresa no habilitó esta sección en la app.',
            'code'    => 'MODULE_DISABLED',
        ], 403);
    }
}
