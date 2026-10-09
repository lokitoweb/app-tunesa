<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\CompanyConfiguration;
use App\Models\EmployeeDocument;
use App\Services\AuditLogger;
use App\Services\DocumentStorageService;
use App\Services\SecureFileUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Mi Legajo": el colaborador ve y sube sus propios documentos.
 * Nunca puede ver documentos de otra persona (filtro por user_id + TenantScope).
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentStorageService $documents,
        private readonly SecureFileUrl $urls,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/employee/documents */
    public function index(Request $request): JsonResponse
    {
        if ($blocked = $this->ensureEnabled('mobile_documents_enabled')) {
            return $blocked;
        }

        $docs = EmployeeDocument::where('user_id', $request->user()->id)
            ->where('status', '!=', 'superseded')
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $docs->map->toApi()]);
    }

    /**
     * Subida multipart: file (PDF/JPG/PNG, máx. según la empresa) + type.
     * POST /api/employee/documents
     */
    public function store(Request $request): JsonResponse
    {
        if ($blocked = $this->ensureEnabled('mobile_documents_upload')) {
            return $blocked;
        }

        $config = CompanyConfiguration::current();

        $validated = $request->validate([
            'type'       => ['required', Rule::in(EmployeeDocument::EMPLOYEE_TYPES)],
            'file'       => DocumentStorageService::fileRules($config),
            'title'      => ['nullable', 'string', 'max:150'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ], [
            'file.mimes' => 'Formato no permitido. Sube un PDF, JPG o PNG.',
            'file.max'   => "El archivo supera {$config->max_upload_mb} MB.",
        ]);

        $user     = $request->user();
        $document = $this->documents->store($user, $request->file('file'), $validated['type'], $user, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Documento enviado. RRHH lo revisará.',
            'data'    => $document->toApi(),
        ], 201);
    }

    /**
     * URL temporal (5 min) para ver un documento propio.
     * GET /api/employee/documents/{id}/url
     */
    public function url(Request $request, int $id): JsonResponse
    {
        if ($blocked = $this->ensureEnabled('mobile_documents_enabled')) {
            return $blocked;
        }

        $document = EmployeeDocument::where('user_id', $request->user()->id)->findOrFail($id);
        $this->audit->log('document.viewed', $document);

        return response()->json([
            'success' => true,
            'data'    => [
                'url'        => $this->urls->make($document->disk, $document->path, 'document', $document->id),
                'expires_in' => (int) config('filesystems.signed_url_minutes', 5) * 60,
                'mime_type'  => $document->mime_type,
            ],
        ]);
    }

    /**
     * Retirar un documento propio que aún no fue aprobado.
     * DELETE /api/employee/documents/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $document = EmployeeDocument::where('user_id', $request->user()->id)->findOrFail($id);

        if (! in_array($document->status, ['pending', 'rejected'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Un documento aprobado solo lo puede retirar RRHH.',
                'code'    => 'DOCUMENT_LOCKED',
            ], 409);
        }

        $this->documents->purge($document);

        return response()->json(['success' => true, 'message' => 'Documento retirado.']);
    }

    private function ensureEnabled(string $flag): ?JsonResponse
    {
        if (CompanyConfiguration::current()->allows($flag)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Tu empresa no habilitó esta sección en la app.',
            'code'    => 'MODULE_DISABLED',
        ], 403);
    }
}
