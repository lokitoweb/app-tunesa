<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\HR\Models\DocumentTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** RR. HH. › Plantillas de documentos del cese: cada empresa ajusta los textos; sin cambios rige el estándar */
class DocumentTemplateWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $tenantId = $this->tenantId();

        return Inertia::render('HR/Pages/DocumentTemplates/Index', [
            'templates' => collect(DocumentTemplate::DEFAULTS)->map(fn ($d, $key) => ['key' => $key, 'standard_title' => $d[0], 'standard_body' => $d[1], 'titled' => in_array($key, DocumentTemplate::TITLED, true)]
                + DocumentTemplate::resolve($tenantId, $key))->values(),
            'fields' => DocumentTemplate::FIELDS,
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        abort_unless(isset(DocumentTemplate::DEFAULTS[$key]), 404);
        $tenantId = $this->tenantId();
        $data = $request->validate(['title' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:10000']],
            [], ['title' => 'el título', 'body' => 'el texto']);
        preg_match_all('/\{(\w+)\}/', $data['title'] . ' ' . $data['body'], $m);
        $unknown = array_diff(array_unique($m[1]), array_keys(DocumentTemplate::FIELDS));
        if ($unknown) {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => 'Campo no reconocido: {' . implode('}, {', $unknown) . '}. Usa los de la lista.']);
        }

        $template = DocumentTemplate::withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenantId, 'key' => $key]);
        $this->audit->updateWithAudit('document_template.updated', $template, $data + ['updated_by' => $request->user()->id], ['template' => DocumentTemplate::DEFAULTS[$key][0]]);

        return back()->with('success', 'Plantilla guardada. Rige para los documentos que se impriman desde ahora.');
    }

    public function reset(string $key): RedirectResponse
    {
        abort_unless(isset(DocumentTemplate::DEFAULTS[$key]), 404);
        DocumentTemplate::withoutGlobalScopes()->where('tenant_id', $this->tenantId())->where('key', $key)->delete();
        $this->audit->log('document_template.reset', null, ['template' => DocumentTemplate::DEFAULTS[$key][0]]);

        return back()->with('success', 'La plantilla volvió al texto estándar.');
    }

    private function tenantId(): int
    {
        $id = current_tenant_id_or_null();
        abort_unless($id, 422, 'Elige una empresa.');

        return (int) $id;
    }
}
