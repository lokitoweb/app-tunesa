<?php

namespace App\Modules\Announcements\Controllers\Web;

use App\Modules\Announcements\Models\Announcement;
use App\Modules\Announcements\Services\AnnouncementService;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\Audience;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Comunicados en el panel: avisos, reuniones y actualizaciones para toda la
 * empresa, una sede, un área, un cargo o personas puntuales. Llegan por push
 * y a la bandeja de la app; el panel muestra cuántos lo leyeron.
 */
class AnnouncementWebController extends Controller
{
    public function __construct(
        private readonly AnnouncementService $announcements,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $search = trim((string) $request->query('search'));

        $list = Announcement::withCount('reads')->with('audienceTargets')
            ->when(array_key_exists($status, Announcement::STATUSES), fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->orderByRaw("CASE status WHEN 'scheduled' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->latest('updated_at')
            ->paginate(20)->withQueryString()
            ->through(fn (Announcement $a) => [
                'id'           => $a->id,
                'tenant_id'    => $a->tenant_id,
                'title'        => $a->title,
                'category'     => $a->category,
                'is_important' => $a->is_important,
                'status'       => $a->status,
                'audience'     => Audience::describe($a->audienceTargets),
                'event_at'     => $a->event_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'scheduled_at' => $a->scheduled_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'sent_at'      => $a->sent_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'recipients'   => $a->recipients,
                'reads'        => $a->reads_count,
            ]);

        return Inertia::render('Announcements/Pages/Announcements/Index', [
            'announcements' => $list,
            'categories'    => Announcement::CATEGORIES,
            'statuses'      => Announcement::STATUSES,
            'totals'        => [
                'sent_month' => Announcement::sent()->where('sent_at', '>=', now()->startOfMonth())->count(),
                'scheduled'  => Announcement::where('status', 'scheduled')->count(),
                'draft'      => Announcement::where('status', 'draft')->count(),
            ],
            'filters'       => ['status' => $status, 'search' => $search],
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        if (! current_tenant_id_or_null()) {
            return redirect()->route('announcements.index')->with('error', 'Elige una empresa en el selector para crear un comunicado.');
        }

        return $this->form(new Announcement(['category' => 'general', 'status' => 'draft']));
    }

    public function edit(int $id): Response|RedirectResponse
    {
        $announcement = Announcement::with('audienceTargets')->findOrFail($id);
        if ($announcement->status === 'sent') {
            return redirect()->route('announcements.show', $id);
        }

        return $this->form($announcement);
    }

    public function show(int $id): Response
    {
        $a = Announcement::with('audienceTargets')->withCount('reads')->findOrFail($id);
        $readers = $a->reads()->with('user:id,first_name,last_name,position')->latest('read_at')->limit(200)->get();

        return Inertia::render('Announcements/Pages/Announcements/Show', [
            'announcement' => [
                'id'           => $a->id,
                'title'        => $a->title,
                'body'         => $a->body,
                'category'     => $a->category,
                'is_important' => $a->is_important,
                'location'     => $a->location,
                'url'          => $a->url,
                'status'       => $a->status,
                'audience'     => Audience::describe($a->audienceTargets),
                'event_at'     => $a->event_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'scheduled_at' => $a->scheduled_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'sent_at'      => $a->sent_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
                'recipients'   => $a->recipients,
                'reads'        => $a->reads_count,
            ],
            'readers'      => $readers->map(fn ($r) => [
                'name'     => $r->user?->full_name ?? trim(($r->user?->first_name ?? '') . ' ' . ($r->user?->last_name ?? '')),
                'position' => $r->user?->position,
                'read_at'  => $r->read_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
            ]),
            'categories'   => Announcement::CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa para crear el comunicado.');
        $data = $this->validated($request);
        $a = $this->announcements->save(new Announcement(), $data, $request->user(), $data['mode']);
        $this->audit->logCreated('announcement.created', $a, ['title' => $a->title]);
        $this->auditMode($a, $data['mode']);

        return redirect()->route('announcements.index')->with('success', $this->savedMessage($a));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $a = Announcement::findOrFail($id);
        if ($a->status === 'sent') {
            return back()->with('error', 'Un comunicado enviado no se puede editar.');
        }
        $data = $this->validated($request);
        $this->announcements->save($a, $data, $request->user(), $data['mode']);
        $this->audit->log('announcement.updated', $a, ['title' => $a->title]);
        $this->auditMode($a, $data['mode']);

        return redirect()->route('announcements.index')->with('success', $this->savedMessage($a));
    }

    public function destroy(int $id): RedirectResponse
    {
        $a = Announcement::findOrFail($id);
        $a->delete();
        $this->audit->log('announcement.deleted', $a, ['title' => $a->title]);

        return redirect()->route('announcements.index')->with('success', $a->status === 'sent'
            ? 'Comunicado eliminado: ya no aparece en la app.'
            : 'Comunicado eliminado.');
    }

    // ------------------------------------------------------------------ privado

    private function form(Announcement $a): Response
    {
        return Inertia::render('Announcements/Pages/Announcements/Form', [
            'announcement' => [
                'id'           => $a->id,
                'title'        => $a->title ?? '',
                'body'         => $a->body ?? '',
                'category'     => $a->category ?? 'general',
                'is_important' => (bool) $a->is_important,
                'event_at'     => $a->event_at?->timezone(tenant_timezone())->format('Y-m-d\TH:i'),
                'location'     => $a->location ?? '',
                'url'          => $a->url ?? '',
                'scheduled_at' => $a->scheduled_at?->timezone(tenant_timezone())->format('Y-m-d\TH:i'),
                'status'       => $a->status,
                'mode'         => 'send',
                'targets'      => Audience::toForm($a->exists ? $a->audienceTargets : collect()),
            ],
            'categories' => Announcement::CATEGORIES,
            'audience'   => Audience::formOptions(),
        ]);
    }

    private function validated(Request $request): array
    {
        $mode = $request->input('mode');
        if ($mode === 'draft' && empty($request->input('targets'))) {
            $request->merge(['targets' => null]); // un borrador puede guardarse sin público
        }
        $data = $request->validate([
            'title'        => ['required', 'string', 'max:150'],
            'body'         => ['required', 'string', 'max:5000'],
            'category'     => ['required', Rule::in(array_keys(Announcement::CATEGORIES))],
            'is_important' => ['boolean'],
            'event_at'     => ['nullable', 'date'],
            'location'     => ['nullable', 'string', 'max:200'],
            'url'          => ['nullable', 'url:https', 'max:500'],
            'mode'         => ['required', Rule::in(['draft', 'schedule', 'send'])],
            'scheduled_at' => [$mode === 'schedule' ? 'required' : 'nullable', 'date', ...($mode === 'schedule' ? ['after:now'] : [])],
        ] + Audience::rules($mode !== 'draft'), Audience::MESSAGES + [
            'url.url'               => 'El enlace debe empezar con https://.',
            'scheduled_at.required' => 'Indica cuándo se envía.',
            'scheduled_at.after'    => 'La hora de envío debe ser futura.',
            'body.required'         => 'Escribe el mensaje del comunicado.',
        ]);
        Audience::validateIds($data['targets'] ?? [], current_tenant_id_or_null());

        // Las fechas del formulario vienen en la hora de la empresa
        foreach (['event_at', 'scheduled_at'] as $key) {
            if (! empty($data[$key])) {
                $data[$key] = \Illuminate\Support\Carbon::parse($data[$key], tenant_timezone())->utc();
            }
        }

        return $data;
    }

    private function auditMode(Announcement $a, string $mode): void
    {
        if ($mode === 'send') {
            $this->audit->log('announcement.sent', $a, ['title' => $a->title, 'recipients' => $a->recipients]);
        } elseif ($mode === 'schedule') {
            $this->audit->log('announcement.scheduled', $a, ['title' => $a->title, 'scheduled_at' => $a->scheduled_at?->toIso8601String()]);
        }
    }

    private function savedMessage(Announcement $a): string
    {
        return match ($a->status) {
            'sent'      => "Comunicado enviado a {$a->recipients} " . ($a->recipients === 1 ? 'persona' : 'personas') . '.',
            'scheduled' => 'Comunicado programado para el ' . $a->scheduled_at->timezone(tenant_timezone())->format('d/m/Y H:i') . '.',
            default     => 'Comunicado guardado como borrador.',
        };
    }
}
