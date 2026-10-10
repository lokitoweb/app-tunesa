<?php

namespace App\Modules\HR\Controllers\Api\Admin;

use App\Modules\Core\Controllers\Controller;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\HR\Services\DocumentStorageService;
use App\Modules\Core\Services\SecureFileUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Revisión de legajos por RRHH: listar, ver (URL temporal), aprobar,
 * rechazar y cargar documentos a nombre del colaborador (ej. contrato).
 */
class DocumentReviewController extends Controller
{
    public function __construct(
        private readonly DocumentStorageService $documents,
        private readonly SecureFileUrl $urls,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/admin/documents?status=pending&type=dni&user_id=5 */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'  => ['nullable', Rule::in(array_keys(EmployeeDocument::STATUSES))],
            'type'    => ['nullable', Rule::in(array_keys(EmployeeDocument::TYPES))],
            'user_id' => ['nullable', 'integer'],
        ]);

        $docs = EmployeeDocument::with('user:id,first_name,last_name,employee_code,document_number')
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->when($request->user_id, fn ($q, $v) => $q->where('user_id', $v))
            ->latest()
            ->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => collect($docs->items())->map(fn (EmployeeDocument $d) => $d->toApi() + [
                'employee' => ['id' => $d->user?->id, 'name' => $d->user?->full_name, 'code' => $d->user?->employee_code],
            ]),
            'meta'    => ['total' => $docs->total(), 'current_page' => $docs->currentPage(), 'last_page' => $docs->lastPage()],
        ]);
    }

    /** GET /api/admin/documents/{id}/url */
    public function url(int $id): JsonResponse
    {
        $document = EmployeeDocument::findOrFail($id);
        $this->audit->log('document.viewed', $document);

        return response()->json([
            'success' => true,
            'data'    => [
                'url'          => $this->urls->make($document->disk, $document->path, 'document', $document->id),
                'mime_type'    => $document->mime_type,
                'integrity_ok' => $this->documents->verifyIntegrity($document),
            ],
        ]);
    }

    /** POST /api/admin/documents/{id}/approve */
    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['expires_at' => ['nullable', 'date']]);
        $document  = EmployeeDocument::findOrFail($id);

        if ($document->status !== 'pending') {
            return $this->notPending();
        }

        $document = $this->documents->approve($document, $request->user(), $validated['expires_at'] ?? null);

        return response()->json(['success' => true, 'message' => 'Documento aprobado.', 'data' => $document->toApi()]);
    }

    /** POST /api/admin/documents/{id}/reject */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $document  = EmployeeDocument::findOrFail($id);

        if ($document->status !== 'pending') {
            return $this->notPending();
        }

        $document = $this->documents->reject($document, $request->user(), $validated['reason']);

        return response()->json(['success' => true, 'message' => 'Documento rechazado.', 'data' => $document->toApi()]);
    }

    /**
     * RRHH sube un documento al legajo (nace aprobado).
     * POST /api/admin/employees/{userId}/documents
     */
    public function storeFor(Request $request, int $userId): JsonResponse
    {
        $employee = User::findOrFail($userId);
        abort_unless($request->user()->canManage($employee), 403);

        $validated = $request->validate([
            'type'       => ['required', Rule::in(array_keys(EmployeeDocument::TYPES))],
            'file'       => DocumentStorageService::fileRules(),
            'title'      => ['nullable', 'string', 'max:150'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $document = $this->documents->store($employee, $request->file('file'), $validated['type'], $request->user(), $validated);

        return response()->json(['success' => true, 'data' => $document->toApi()], 201);
    }

    private function notPending(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'El documento ya fue revisado.',
            'code'    => 'DOCUMENT_ALREADY_REVIEWED',
        ], 409);
    }
}
