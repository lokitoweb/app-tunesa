<?php

namespace App\Modules\CompanyDocs\Controllers\Api;

use App\Modules\CompanyDocs\Models\CompanyDocument;
use App\Modules\CompanyDocs\Models\CompanyDocumentFolder;
use App\Modules\CompanyDocs\Models\CompanyDocumentRead;
use App\Modules\CompanyDocs\Services\CompanyDocumentService;
use App\Modules\Core\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documentos de la empresa en la app: carpetas, lectura del PDF (en memoria)
 * y "Leído y conforme".
 */
class CompanyDocumentController extends Controller
{
    public function __construct(private readonly CompanyDocumentService $documents) {}

    /** GET /api/company-documents */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $docs = CompanyDocument::published()->visibleToAudience($user)
            ->with('folder')
            ->latest('published_at')
            ->limit(500)
            ->get();
        $reads = CompanyDocumentRead::where('user_id', $user->id)->whereIn('document_id', $docs->pluck('id'))->get()->keyBy('document_id');

        $folders = CompanyDocumentFolder::whereIn('id', $docs->pluck('folder_id')->filter()->unique())->orderBy('position')->get();
        $data = $docs->map(fn (CompanyDocument $d) => $this->summary($d, $reads[$d->id] ?? null));

        return response()->json([
            'success' => true,
            'data'    => $data->values(),
            'meta'    => [
                'folders' => $folders->map(fn (CompanyDocumentFolder $f) => [
                    'id' => $f->id, 'name' => $f->name, 'description' => $f->description, 'icon' => $f->icon, 'color' => $f->color,
                    'count' => $docs->where('folder_id', $f->id)->count(),
                ])->values(),
                'pending_acknowledgement' => $data->where('pending_acknowledgement', true)->count(),
                'unread' => $data->where('read', false)->count(),
            ],
        ]);
    }

    /** GET /api/company-documents/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $document = $this->find($request, $id);
        $read = CompanyDocumentRead::where('user_id', $request->user()->id)->where('document_id', $document->id)->first();

        return response()->json(['success' => true, 'data' => $this->summary($document, $read) + ['description' => $document->description]]);
    }

    /** GET /api/company-documents/{id}/file — el PDF; cuenta como leído */
    public function file(Request $request, int $id): Response
    {
        $document = $this->find($request, $id);
        abort_unless(Storage::disk($document->file_disk)->exists($document->file_path), 404);
        $this->documents->markRead($document, $request->user());

        return Storage::disk($document->file_disk)->response($document->file_path, $document->file_name, [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /api/company-documents/{id}/acknowledge — "Leído y conforme" */
    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $document = $this->find($request, $id);
        abort_unless($document->requires_acknowledgement, 422, 'Este documento no pide confirmación.');
        $read = $this->documents->acknowledge($document, $request->user(), $request->ip());

        return response()->json([
            'success' => true,
            'message' => 'Listo: confirmaste que leíste el documento.',
            'data'    => $this->summary($document, $read),
        ]);
    }

    private function find(Request $request, int $id): CompanyDocument
    {
        return CompanyDocument::published()->visibleToAudience($request->user())->with('folder')->findOrFail($id);
    }

    private function summary(CompanyDocument $d, ?CompanyDocumentRead $read): array
    {
        return [
            'id'              => $d->id,
            'title'           => $d->title,
            'version'         => $d->version,
            'folder_id'       => $d->folder_id,
            'folder'          => $d->folder?->name,
            'file_name'       => $d->file_name,
            'file_size'       => $d->file_size,
            'published_at'    => $d->published_at?->toIso8601String(),
            'requires_acknowledgement' => $d->requires_acknowledgement,
            'acknowledge_by'  => $d->acknowledge_by?->toDateString(),
            'read'            => $read?->first_read_at !== null,
            'acknowledged_at' => $read?->acknowledged_at?->toIso8601String(),
            'pending_acknowledgement' => $d->requires_acknowledgement && ! $read?->acknowledged_at,
        ];
    }
}
