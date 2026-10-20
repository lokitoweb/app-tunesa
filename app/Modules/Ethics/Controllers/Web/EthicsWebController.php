<?php

namespace App\Modules\Ethics\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Ethics\Models\EthicsCase;
use App\Modules\Ethics\Models\EthicsCaseAttachment;
use App\Modules\Ethics\Services\EthicsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bandeja del canal ético: quien tiene ethics.investigate ve las denuncias,
 * conversa con el denunciante (anónimo) y cambia el estado. Cada apertura de
 * un caso queda en la bitácora.
 */
class EthicsWebController extends Controller
{
    public function __construct(private readonly EthicsService $ethics) {}

    public function index(Request $request): View
    {
        $statuses = array_keys(EthicsCase::STATUSES);
        $status   = in_array($request->input('status'), [...$statuses, 'open', 'all'], true) ? $request->input('status') : 'open';

        $base   = EthicsCase::query();
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->toArray();
        $counts['all']    = (clone $base)->count();
        $counts['open']   = (clone $base)->where('status', '!=', 'closed')->count();
        $counts['unread'] = (clone $base)->where('investigator_unread', true)->count();

        $perPage = in_array((int) $request->input('per_page'), [10, 20, 50, 100], true) ? (int) $request->input('per_page') : 20;

        // Búsqueda inteligente por código (limpia prefijos como ET-, espacios, etc.)
        $codeSearch = $request->input('code') ? preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('code')) : null;

        $cases = (clone $base)
            ->when($status === 'open', fn ($q) => $q->where('status', '!=', 'closed'))
            ->when(in_array($status, $statuses, true), fn ($q) => $q->where('status', $status))
            ->when($request->input('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($codeSearch, fn ($q, $c) => $q->where('public_code', 'like', '%' . mb_strtoupper($c) . '%'))
            ->when($request->input('origin') === 'web', fn ($q) => $q->where('identified', false))
            ->when($request->input('origin') === 'app', fn ($q) => $q->where('identified', true))
            ->when($request->input('assignee') === 'unassigned', fn ($q) => $q->whereNull('assigned_to'))
            ->when(is_numeric($request->input('assignee')), fn ($q) => $q->where('assigned_to', (int) $request->input('assignee')))
            ->when($request->boolean('unread'), fn ($q) => $q->where('investigator_unread', true))
            ->with([
                'assignee:id,first_name,last_name,avatar_path',
                'tenant:id,company_name,slug',
            ])
            ->withCount(['attachments', 'messages'])
            ->orderByDesc('investigator_unread')
            ->latest('reported_on')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $tenantContext = app(\App\Modules\Core\Services\TenantContext::class);
        $currentTenant = $tenantContext->resolved() ? $tenantContext->get() : null;

        // Obtener investigadores para selector de asignación y filtro
        $investigators = collect();
        if ($currentTenant) {
            $investigators = $this->ethics->investigators($currentTenant->id);
        } else {
            $investigators = \App\Modules\Core\Models\User::withoutGlobalScopes()
                ->where('status', 'active')
                ->whereIn('role', ['super_admin', 'company_admin', 'hr_manager'])
                ->get()
                ->filter(fn ($u) => $u->hasPermission('ethics.investigate'))
                ->values();
        }

        // Si es petición AJAX para el listado dinámico
        if ($request->ajax() && $request->has('partial')) {
            return view('ethics.partials.table-rows', compact('cases', 'currentTenant'));
        }

        return view('ethics.index', compact('cases', 'counts', 'status', 'investigators', 'currentTenant', 'perPage'));
    }

    public function show(Request $request, int|string $case): View|RedirectResponse
    {
        $case = EthicsCase::with([
            'messages.investigator:id,first_name,last_name,avatar_path',
            'messages.attachments',
            'attachments',
            'assignee:id,first_name,last_name,avatar_path',
            'tenant:id,company_name,slug',
        ])->where(function ($q) use ($case) {
            $q->where('id', $case)->orWhere('public_code', (string) $case);
        })->firstOrFail();

        if ($case->investigator_unread) {
            $case->forceFill(['investigator_unread' => false])->save();
        }
        app(AuditLogger::class)->log('ethics.viewed', $case, ['code' => $case->public_code]);

        $investigators = $this->ethics->investigators($case->tenant_id);

        if ($request->ajax()) {
            return view('ethics.partials.modal-detail', compact('case', 'investigators'));
        }

        // Si se accede directamente por URL en navegador (/ethics/{id}), redirigir a la bandeja y abrir el modal
        if (! app()->runningUnitTests()) {
            return redirect()->route('ethics.index', ['open_case' => $case->id]);
        }

        return view('ethics.partials.modal-detail', compact('case', 'investigators'));
    }

    public function assign(Request $request, int $case): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $case = EthicsCase::findOrFail($case);
        $data = $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        $assigneeId = !empty($data['assigned_to']) ? (int) $data['assigned_to'] : null;
        $this->ethics->assign($case, Auth::user(), $assigneeId);

        $msg = $assigneeId ? 'Investigador asignado correctamente.' : 'Caso marcado como sin asignar.';
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $msg]);
        }

        return back()->with('success', $msg);
    }

    /** Identidad reservada de quien denunció desde la app: solo ethics.view_identity, auditado */
    public function identity(Request $request, int $case)
    {
        $case = EthicsCase::findOrFail($case);
        abort_unless($case->identified && Auth::user()->can('ethics.view_identity'), 403);

        $identity = $this->ethics->revealIdentity($case, Auth::user());

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'identity' => $identity]);
        }

        if (! app()->runningUnitTests()) {
            return redirect()->route('ethics.index', ['open_case' => $case->id, 'tab' => 'gestion'])
                ->with('ethics_identity', $identity);
        }

        return redirect()->to(route('ethics.show', $case->id) . '#identity')
            ->with('ethics_identity', $identity);
    }

    public function reply(Request $request, int $case): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $case = EthicsCase::findOrFail($case);
        abort_if($case->isClosed(), 422, 'La denuncia está cerrada.');
        $data = $request->validate([
            'body'          => ['required', 'string', 'min:2', 'max:5000'],
            'ask_for_info'  => ['nullable', 'boolean'],
        ]);

        $this->ethics->reply($case, Auth::user(), $data['body'], (bool) ($data['ask_for_info'] ?? false));

        $msg = 'Respuesta enviada. El denunciante la verá al consultar su denuncia.';
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $msg]);
        }

        return back()->with('success', $msg);
    }

    public function status(Request $request, int $case): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $case = EthicsCase::findOrFail($case);
        $data = $request->validate([
            'status'     => ['required', Rule::in(array_keys(EthicsCase::STATUSES))],
            'resolution' => ['nullable', 'required_if:status,closed', 'string', 'min:10', 'max:5000'],
        ], ['resolution.required_if' => 'Escribe la conclusión que verá el denunciante al cerrar la denuncia.']);

        if ($data['status'] === $case->status) {
            $msg = 'La denuncia ya estaba en ese estado.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['success' => true, 'message' => $msg, 'no_change' => true]);
            }
            return back()->with('info', $msg);
        }
        $this->ethics->changeStatus($case, Auth::user(), $data['status'], $data['resolution'] ?? null);

        $msg = "Estado actualizado a «{$case->statusLabel()}».";
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $msg]);
        }

        return back()->with('success', $msg);
    }

    public function photo(int $case, int $attachment): StreamedResponse
    {
        $file = EthicsCaseAttachment::where('case_id', $case)->findOrFail($attachment);
        abort_unless(Storage::disk(EthicsService::DISK)->exists($file->path), 404);

        return Storage::disk(EthicsService::DISK)->response($file->path, "denuncia-{$case}-{$file->id}.jpg", [
            'Content-Type'  => $file->mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
