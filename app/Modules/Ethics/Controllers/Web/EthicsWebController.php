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
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $counts['open'] = $counts->except('closed')->sum();

        $cases = (clone $base)
            ->when($status === 'open', fn ($q) => $q->where('status', '!=', 'closed'))
            ->when(in_array($status, $statuses, true), fn ($q) => $q->where('status', $status))
            ->when($request->input('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->input('code'), fn ($q, $c) => $q->where('public_code', 'like', '%' . mb_strtoupper(trim($c)) . '%'))
            ->with('assignee:id,first_name,last_name')
            ->orderByDesc('investigator_unread')->latest('reported_on')->latest('id')
            ->paginate(20)->withQueryString();

        return view('ethics.index', compact('cases', 'counts', 'status'));
    }

    public function show(int $case): View
    {
        $case = EthicsCase::with(['messages.investigator:id,first_name,last_name', 'messages.attachments', 'attachments', 'assignee:id,first_name,last_name'])->findOrFail($case);

        if ($case->investigator_unread) {
            $case->forceFill(['investigator_unread' => false])->save();
        }
        app(AuditLogger::class)->log('ethics.viewed', $case, ['code' => $case->public_code]);

        return view('ethics.show', ['case' => $case]);
    }

    /** Identidad de quien eligió identificarse: solo ethics.view_identity, auditado */
    public function identity(int $case): RedirectResponse
    {
        $case = EthicsCase::findOrFail($case);
        abort_unless($case->identified && Auth::user()->can('ethics.view_identity'), 403);

        return redirect()->to(route('ethics.show', $case->id) . '#identity')
            ->with('ethics_identity', $this->ethics->revealIdentity($case, Auth::user()));
    }

    public function reply(Request $request, int $case): RedirectResponse
    {
        $case = EthicsCase::findOrFail($case);
        abort_if($case->isClosed(), 422, 'La denuncia está cerrada.');
        $data = $request->validate([
            'body'          => ['required', 'string', 'min:2', 'max:5000'],
            'ask_for_info'  => ['nullable', 'boolean'],
        ]);

        $this->ethics->reply($case, Auth::user(), $data['body'], (bool) ($data['ask_for_info'] ?? false));

        return back()->with('success', 'Respuesta enviada. El denunciante la verá al consultar su denuncia.');
    }

    public function status(Request $request, int $case): RedirectResponse
    {
        $case = EthicsCase::findOrFail($case);
        $data = $request->validate([
            'status'     => ['required', Rule::in(array_keys(EthicsCase::STATUSES))],
            'resolution' => ['nullable', 'required_if:status,closed', 'string', 'min:10', 'max:5000'],
        ], ['resolution.required_if' => 'Escribe la conclusión que verá el denunciante al cerrar la denuncia.']);

        if ($data['status'] === $case->status) {
            return back()->with('info', 'La denuncia ya estaba en ese estado.');
        }
        $this->ethics->changeStatus($case, Auth::user(), $data['status'], $data['resolution'] ?? null);

        return back()->with('success', "Estado actualizado a «{$case->statusLabel()}».");
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
