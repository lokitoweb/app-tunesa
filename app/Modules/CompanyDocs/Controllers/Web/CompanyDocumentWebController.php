<?php

namespace App\Modules\CompanyDocs\Controllers\Web;

use App\Modules\CompanyDocs\Models\CompanyDocument;
use App\Modules\CompanyDocs\Models\CompanyDocumentFolder;
use App\Modules\CompanyDocs\Services\CompanyDocumentService;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\Audience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos de la empresa en el panel: carpetas, PDFs, a quién se le
 * muestran y quién los leyó o confirmó.
 */
class CompanyDocumentWebController extends Controller
{
    public function __construct(
        private readonly CompanyDocumentService $documents,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        CompanyDocumentFolder::ensureDefaults();
        $status = $request->query('status');
        $folder = $request->integer('folder') ?: null;
        $search = trim((string) $request->query('search'));

        $documents = CompanyDocument::with('folder')
            ->withCount(['reads as read_count' => fn ($q) => $q->whereNotNull('first_read_at'),
                'reads as ack_count' => fn ($q) => $q->whereNotNull('acknowledged_at')])
            ->when(array_key_exists($status, CompanyDocument::STATUSES), fn ($q) => $q->where('status', $status))
            ->when($folder, fn ($q) => $q->where('folder_id', $folder))
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->orderByRaw("CASE status WHEN 'published' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->latest('updated_at')
            ->paginate(100)->withQueryString()
            ->through(fn (CompanyDocument $d) => [
                'id'          => $d->id,
                'tenant_id'   => $d->tenant_id,
                'title'       => $d->title,
                'version'     => $d->version,
                'description' => $d->description,
                'folder'      => $d->folder?->name,
                'folder_id'   => $d->folder_id,
                'status'      => $d->status,
                'file_name'   => $d->file_name,
                'requires_acknowledgement' => (bool) $d->requires_acknowledgement,
                'acknowledge_by' => $d->acknowledge_by?->format('Y-m-d'),
                'read_count'  => $d->read_count,
                'ack_count'   => $d->ack_count,
                'size'        => $this->size($d->file_size),
                'updated_at'  => $d->updated_at?->format('d/m/Y'),
            ]);

        return Inertia::render('CompanyDocs/Pages/Documents/Index', [
            'documents'    => $documents,
            'folders'      => CompanyDocumentFolder::withCount('documents')->orderBy('position')->get(['id', 'name', 'description', 'icon', 'color', 'position']),
            'folder_icons' => CompanyDocumentFolder::ICONS,
            'audience'     => Audience::formOptions(),
            'statuses'     => CompanyDocument::STATUSES,
            'totals'       => [
                'published' => CompanyDocument::where('status', 'published')->count(),
                'draft'     => CompanyDocument::where('status', 'draft')->count(),
                'archived'  => CompanyDocument::where('status', 'archived')->count(),
                'total'     => CompanyDocument::count(),
            ],
            'filters'      => ['status' => $status, 'folder' => $folder, 'search' => $search],
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if (! current_tenant_id_or_null()) {
            return redirect()->route('company-docs.index')->with('error', 'Elige una empresa en el selector para subir un documento.');
        }
        CompanyDocumentFolder::ensureDefaults();

        return $this->form(new CompanyDocument(['status' => 'draft', 'folder_id' => $request->integer('folder') ?: null]));
    }

    public function edit(Request $request, int $id): Response|JsonResponse
    {
        $document = CompanyDocument::with('audienceTargets')->findOrFail($id);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'document' => [
                    'id'          => $document->id,
                    'title'       => $document->title ?? '',
                    'description' => $document->description ?? '',
                    'version'     => $document->version ?? '',
                    'folder_id'   => $document->folder_id,
                    'file_name'   => $document->file_name,
                    'requires_acknowledgement' => (bool) $document->requires_acknowledgement,
                    'acknowledge_by' => $document->acknowledge_by?->format('Y-m-d'),
                    'status'      => $document->status,
                    'notify'      => true,
                    'targets'     => Audience::toForm($document->audienceTargets),
                ],
            ]);
        }

        return $this->form($document);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa para subir el documento.');
        $document = $this->documents->save(new CompanyDocument(), $this->validated($request, true), $request->user(), $request->file('file'));
        $this->audit->logCreated('company_document.created', $document, ['title' => $document->title]);

        return redirect()->route('company-docs.index')->with('success', $this->savedMessage($document, 'subido'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $document = CompanyDocument::findOrFail($id);
        $this->documents->save($document, $this->validated($request, false), $request->user(), $request->file('file'));
        $this->audit->log('company_document.updated', $document, ['title' => $document->title]);

        return redirect()->route('company-docs.index')->with('success', $this->savedMessage($document, 'actualizado'));
    }

    public function status(Request $request, int $id): RedirectResponse
    {
        $status   = $request->validate(['status' => ['required', Rule::in(array_keys(CompanyDocument::STATUSES))]])['status'];
        $document = CompanyDocument::withCount('audienceTargets')->findOrFail($id);

        if ($status === 'published' && ! $document->audience_targets_count) {
            return back()->with('error', 'Elige a quién se le muestra el documento antes de publicarlo.');
        }

        $this->documents->setStatus($document, $status);
        $this->audit->log("company_document.{$status}", $document, ['title' => $document->title]);

        return back()->with('success', match ($status) {
            'published' => 'Documento publicado: ya aparece en la app de su público y se les avisó.',
            'archived'  => 'Documento archivado: ya no aparece en la app.',
            default     => 'El documento volvió a borrador y ya no aparece en la app.',
        });
    }

    public function destroy(int $id): RedirectResponse
    {
        $document = CompanyDocument::findOrFail($id);
        $document->delete();
        $this->audit->log('company_document.deleted', $document, ['title' => $document->title]);

        return redirect()->route('company-docs.index')->with('success', 'Documento eliminado.');
    }

    /** Detalle con el reporte de lectura y confirmación */
    public function show(Request $request, int $id): Response
    {
        $document = CompanyDocument::with(['folder', 'audienceTargets'])->findOrFail($id);
        $filter   = $request->query('filter');
        $search   = trim((string) $request->query('search'));
        $base     = $this->documents->audienceWithReads($document);

        $people = (clone $base)
            ->when($filter === 'pending', fn ($q) => $q->whereNull($document->requires_acknowledgement ? 'r.acknowledged_at' : 'r.first_read_at'))
            ->when($filter === 'read', fn ($q) => $q->whereNotNull('r.first_read_at'))
            ->when($filter === 'acknowledged', fn ($q) => $q->whereNotNull('r.acknowledged_at'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('users.first_name', 'like', "%{$search}%")
                ->orWhere('users.last_name', 'like', "%{$search}%")->orWhere('users.employee_code', 'like', "%{$search}%")))
            ->orderBy('users.last_name')
            ->paginate(25)->withQueryString()
            ->through(fn ($u) => [
                'id'              => $u->id,
                'name'            => "{$u->first_name} {$u->last_name}",
                'code'            => $u->employee_code,
                'position'        => $u->position,
                'read_at'         => $u->first_read_at ? \Illuminate\Support\Carbon::parse($u->first_read_at)->timezone(tenant_timezone())->format('d/m/Y h:i a') : null,
                'acknowledged_at' => $u->acknowledged_at ? \Illuminate\Support\Carbon::parse($u->acknowledged_at)->timezone(tenant_timezone())->format('d/m/Y h:i a') : null,
            ]);

        $total = (clone $base)->count();

        return Inertia::render('CompanyDocs/Pages/Documents/Show', [
            'document' => [
                'id'          => $document->id,
                'title'       => $document->title,
                'description' => $document->description,
                'version'     => $document->version,
                'folder'      => $document->folder?->name,
                'status'      => $document->status,
                'file_name'   => $document->file_name,
                'size'        => $this->size($document->file_size),
                'requires_acknowledgement' => $document->requires_acknowledgement,
                'acknowledge_by' => $document->acknowledge_by?->format('d/m/Y'),
                'published_at'   => $document->published_at?->timezone(tenant_timezone())->format('d/m/Y h:i a'),
                'audience'    => Audience::describe($document->audienceTargets),
            ],
            'stats' => [
                'audience'     => $total,
                'read'         => (clone $base)->whereNotNull('r.first_read_at')->count(),
                'acknowledged' => (clone $base)->whereNotNull('r.acknowledged_at')->count(),
            ],
            'statuses' => CompanyDocument::STATUSES,
            'people'   => $people,
            'filters'  => ['filter' => $filter, 'search' => $search],
        ]);
    }

    /** CSV de lectura y confirmación (auditorías de SST, inspecciones laborales) */
    public function export(int $id): StreamedResponse
    {
        $document = CompanyDocument::findOrFail($id);
        $query    = $this->documents->audienceWithReads($document)->orderBy('users.last_name');
        $tz       = tenant_timezone();

        return response()->streamDownload(function () use ($query, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Código', 'Documento de identidad', 'Colaborador', 'Cargo', 'Leído', 'Leído y conforme']);
            $query->chunk(500, function ($rows) use ($out, $tz) {
                foreach ($rows as $u) {
                    fputcsv($out, [
                        $u->employee_code, $u->document_number, "{$u->first_name} {$u->last_name}", $u->position,
                        $u->first_read_at ? \Illuminate\Support\Carbon::parse($u->first_read_at)->timezone($tz)->format('d/m/Y H:i') : '',
                        $u->acknowledged_at ? \Illuminate\Support\Carbon::parse($u->acknowledged_at)->timezone($tz)->format('d/m/Y H:i') : '',
                    ]);
                }
            });
            fclose($out);
        }, 'documento_' . $document->id . '_lectura.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Vista previa del PDF */
    public function file(int $id): HttpResponse
    {
        $document = CompanyDocument::findOrFail($id);
        abort_unless(Storage::disk($document->file_disk)->exists($document->file_path), 404);

        return Storage::disk($document->file_disk)->response($document->file_path, $document->file_name, [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ------------------------------------------------------------------ carpetas

    public function folders(): Response
    {
        CompanyDocumentFolder::ensureDefaults();

        return Inertia::render('CompanyDocs/Pages/Documents/Folders', [
            'folders' => CompanyDocumentFolder::withCount('documents')->orderBy('position')->get()
                ->map(fn (CompanyDocumentFolder $f) => $f->only(['id', 'name', 'description', 'icon', 'color', 'position', 'documents_count'])),
            'icons'   => CompanyDocumentFolder::ICONS,
        ]);
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa.');
        $folder = CompanyDocumentFolder::create($this->validatedFolder($request) + ['position' => (int) CompanyDocumentFolder::max('position') + 1]);
        $this->audit->logCreated('company_document_folder.created', $folder, ['name' => $folder->name]);

        return back()->with('success', 'Carpeta creada.');
    }

    public function updateFolder(Request $request, int $id): RedirectResponse
    {
        $folder = CompanyDocumentFolder::findOrFail($id);
        $folder->update($this->validatedFolder($request, $folder->id));
        $this->audit->log('company_document_folder.updated', $folder, ['name' => $folder->name]);

        return back()->with('success', 'Carpeta actualizada.');
    }

    public function destroyFolder(int $id): RedirectResponse
    {
        $folder = CompanyDocumentFolder::withCount('documents')->findOrFail($id);
        if ($folder->documents_count > 0) {
            return back()->with('error', 'Mueve o elimina los documentos de la carpeta antes de borrarla.');
        }
        $folder->delete();
        $this->audit->log('company_document_folder.deleted', $folder, ['name' => $folder->name]);

        return back()->with('success', 'Carpeta eliminada.');
    }

    // ------------------------------------------------------------------ privado

    private function form(CompanyDocument $document): Response
    {
        return Inertia::render('CompanyDocs/Pages/Documents/Form', [
            'document' => [
                'id'          => $document->id,
                'title'       => $document->title ?? '',
                'description' => $document->description ?? '',
                'version'     => $document->version ?? '',
                'folder_id'   => $document->folder_id,
                'file_name'   => $document->file_name,
                'requires_acknowledgement' => (bool) $document->requires_acknowledgement,
                'acknowledge_by' => $document->acknowledge_by?->format('Y-m-d'),
                'status'      => $document->status,
                'file'        => null,
                'notify'      => true,
                'targets'     => Audience::toForm($document->exists ? $document->audienceTargets : collect()),
            ],
            'folders'  => CompanyDocumentFolder::orderBy('position')->get(['id', 'name']),
            'audience' => Audience::formOptions(),
        ]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'title'          => ['required', 'string', 'max:200'],
            'description'    => ['nullable', 'string', 'max:5000'],
            'version'        => ['nullable', 'string', 'max:30'],
            'folder_id'      => ['required', 'integer', Rule::exists('company_document_folders', 'id')->where('tenant_id', current_tenant_id_or_null())],
            'file'           => [$creating ? 'required' : 'nullable', 'file', 'mimes:pdf', 'extensions:pdf', 'max:20480'],
            'requires_acknowledgement' => ['boolean'],
            'acknowledge_by' => ['nullable', 'date', 'after_or_equal:today'],
            'status'         => ['required', Rule::in(array_keys(CompanyDocument::STATUSES))],
            'notify'         => ['boolean'],
        ] + Audience::rules($request->input('status') === 'published'), Audience::MESSAGES + [
            'file.required' => 'Sube el documento en PDF.',
            'file.mimes'    => 'El documento debe ser un PDF.',
            'file.max'      => 'El PDF no puede pesar más de 20 MB.',
            'folder_id.required' => 'Elige una carpeta.',
            'acknowledge_by.after_or_equal' => 'La fecha límite no puede ser anterior a hoy.',
        ]);
        Audience::validateIds($data['targets'] ?? [], current_tenant_id_or_null());

        return $data;
    }

    private function validatedFolder(Request $request, ?int $ignore = null): array
    {
        return $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('company_document_folders', 'name')->where('tenant_id', current_tenant_id_or_null())->ignore($ignore)],
            'description' => ['nullable', 'string', 'max:255'],
            'icon'        => ['required', Rule::in(array_keys(CompanyDocumentFolder::ICONS))],
            'color'       => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'position'    => ['nullable', 'integer', 'between:0,999'],
        ], ['name.unique' => 'Ya existe una carpeta con ese nombre.']);
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private function savedMessage(CompanyDocument $document, string $verb): string
    {
        return $document->isPublished()
            ? "Documento {$verb} y publicado: ya aparece en la app de su público."
            : "Documento {$verb} como " . mb_strtolower(CompanyDocument::STATUSES[$document->status]) . '.';
    }
}
