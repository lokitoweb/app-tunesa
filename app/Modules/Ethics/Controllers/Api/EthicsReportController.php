<?php

namespace App\Modules\Ethics\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Ethics\Models\EthicsCase;
use App\Modules\Ethics\Models\EthicsCaseMessage;
use App\Modules\Ethics\Services\EthicsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Canal ético en la app, sin sesión: registrar una denuncia y seguirla con
 * código + token (cabecera X-Ethics-Token, nunca en la URL).
 */
class EthicsReportController extends Controller
{
    private const PHOTO_RULES = ['nullable', 'array', 'max:3'];

    public function __construct(private readonly EthicsService $ethics) {}

    public function categories(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => collect(EthicsCase::CATEGORIES)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->register($request, null);
    }

    /**
     * Denuncia desde la app: va con sesión y la identidad queda cifrada y
     * reservada. Para todos es anónima; solo RRHH (ethics.view_identity) la ve.
     */
    public function storeIdentified(Request $request): JsonResponse
    {
        return $this->register($request, $request->user());
    }

    private function register(Request $request, ?\App\Modules\Core\Models\User $identifyAs): JsonResponse
    {
        $data = $request->validate([
            'category'     => ['required', Rule::in(array_keys(EthicsCase::CATEGORIES))],
            'subject'      => ['required', 'string', 'min:5', 'max:150'],
            'body'         => ['required', 'string', 'min:20', 'max:5000'],
            'push_channel' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_-]{16,64}$/'],
            'photos'       => self::PHOTO_RULES,
            'photos.*'     => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $tenant = $request->attributes->get('tenant') ?? $identifyAs?->tenant;
        $result = $this->ethics->report($tenant, $data, $request->file('photos', []), $identifyAs);

        return response()->json([
            'success' => true,
            'message' => 'Denuncia registrada. Guarda el código y la clave: son la única forma de seguirla.',
            'data'    => [
                'code'       => $result['case']->public_code,
                'token'      => $result['token'],
                'status'     => $result['case']->status,
                'identified' => $result['case']->identified,
            ],
        ], 201);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $case = $this->case($request, $code);
        if ($case->reporter_unread) {
            $case->forceFill(['reporter_unread' => false])->save();
        }

        return response()->json(['success' => true, 'data' => $this->present($case)]);
    }

    public function message(Request $request, string $code): JsonResponse
    {
        $case = $this->case($request, $code);
        $data = $request->validate([
            'body'     => ['required', 'string', 'min:2', 'max:5000'],
            'photos'   => self::PHOTO_RULES,
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $this->ethics->reporterMessage($case, $data['body'], $request->file('photos', []));

        return response()->json(['success' => true, 'message' => 'Mensaje enviado.', 'data' => $this->present($case->fresh())], 201);
    }

    private function case(Request $request, string $code): EthicsCase
    {
        $case = $this->ethics->find($request->attributes->get('tenant'), $code, $request->header('X-Ethics-Token'));

        if (! $case) {
            abort(response()->json([
                'success' => false,
                'message' => 'No encontramos una denuncia con ese código y clave. Revisa que estén bien escritos.',
                'code'    => 'ETHICS_CASE_NOT_FOUND',
            ], 404));
        }

        return $case;
    }

    /** Lo que ve el denunciante: sin nombres de investigadores ni datos internos */
    private function present(EthicsCase $case): array
    {
        $case->loadMissing('messages.attachments', 'attachments');

        return [
            'code'           => $case->public_code,
            'category'       => $case->category,
            'category_label' => $case->categoryLabel(),
            'subject'        => $case->subject,
            'body'           => $case->body,
            'status'         => $case->status,
            'status_label'   => $case->statusLabel(),
            'identified'     => $case->identified,
            'reported_on'    => $case->reported_on->toDateString(),
            'resolution'     => $case->isClosed() ? $case->resolution : null,
            'photos'         => $case->attachments->whereNull('message_id')->count(),
            'messages'       => $case->messages->map(fn (EthicsCaseMessage $m) => [
                'from'    => $m->author_type,
                'body'    => $m->body,
                'sent_on' => $m->sent_on->toDateString(),
                'photos'  => $m->attachments->count(),
            ])->values(),
        ];
    }
}
