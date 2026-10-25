<?php

namespace App\Modules\Birthdays\Controllers\Api;

use App\Modules\Birthdays\Services\BirthdayGiftService;
use App\Modules\Birthdays\Services\BirthdayService;
use App\Modules\Core\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cumpleaños en la app: próximos (Inicio), por mes y ficha de cada persona.
 * scope=team limita a quienes comparten sucursal con quien consulta.
 */
class BirthdayController extends Controller
{
    public function __construct(private readonly BirthdayService $birthdays, private readonly BirthdayGiftService $gifts) {}

    /**
     * GET /api/birthdays/upcoming?days=30&limit=10
     *
     * Inicio de la app: ?days=7&scope=company&limit=5 → los próximos 7 días con el
     * mismo filtro (empresa) que la pantalla de cumpleaños. scope=team limita a su
     * sede sin incluirlo; period=month, a lo que queda del mes. meta.greeting
     * trae el saludo de la empresa solo el día del cumpleaños de quien consulta.
     */
    public function upcoming(Request $request): JsonResponse
    {
        $data  = $request->validate([
            'days'   => ['nullable', 'integer', 'between:1,90'],
            'limit'  => ['nullable', 'integer', 'between:1,50'],
            'scope'  => ['nullable', 'in:company,team'],
            'period' => ['nullable', 'in:month'],
        ]);
        $today = now(tenant_timezone());
        $user  = $request->user();
        $scope = $data['scope'] ?? 'company';
        $days  = ($data['period'] ?? null) === 'month'
            ? (int) $today->copy()->startOfDay()->diffInDays($today->copy()->endOfMonth()->startOfDay())
            : (int) ($data['days'] ?? 30);

        $all = $this->birthdays->upcoming(current_tenant_id(), $today, $days, $user, $scope)
            ->when($scope === 'team', fn ($c) => $c->reject(fn ($p) => $p['id'] === $user->id)->values());
        $limit = (int) ($data['limit'] ?? 10);

        return response()->json([
            'success' => true,
            'data'    => $all->take($limit)->values(),
            'meta'    => [
                'total'    => $all->count(),
                'today'    => $all->where('is_today', true)->count(),
                'greeting' => $this->birthdays->greetingFor($user, $today),
            ],
        ]);
    }

    /** GET /api/birthdays?month=2&scope=company&search=ana */
    public function index(Request $request): JsonResponse
    {
        $today = now(tenant_timezone());
        $data  = $request->validate(['month' => ['nullable', 'integer', 'between:1,12'], 'scope' => ['nullable', 'in:company,team'], 'search' => ['nullable', 'string', 'max:60']]);
        $month = (int) ($data['month'] ?? $today->month);
        $user  = $request->user();

        $people = $this->birthdays->month(current_tenant_id(), $month, $today, $user, $data['scope'] ?? 'company', trim((string) ($data['search'] ?? '')));
        // Pasados: ya ocurrieron este año (mes anterior, o este mes antes de hoy)
        $isPast = fn ($p) => $p['month'] < $today->month || ($p['month'] === $today->month && $p['day'] < $today->day);

        return response()->json([
            'success' => true,
            'data'    => [
                'month'       => $month,
                'month_name'  => ucfirst(BirthdayService::MONTHS[$month]),
                'today'       => $people->where('is_today', true)->values(),
                'upcoming'    => $people->reject(fn ($p) => $p['is_today'] || $isPast($p))->values(),
                'past'        => $people->filter(fn ($p) => ! $p['is_today'] && $isPast($p))->values(),
                // Carrusel "Mi equipo": próximos de su sucursal en el año
                'team'        => $this->birthdays->upcoming(current_tenant_id(), $today, 365, $user, 'team', 15)
                    ->reject(fn ($p) => $p['id'] === $user->id)->values(),
            ],
            'meta'    => ['total' => $people->count(), 'current_month' => $today->month],
        ]);
    }

    /** GET /api/birthdays/{id} */
    public function show(int $id): JsonResponse
    {
        $user = $this->birthdays->people(current_tenant_id())->where('users.id', $id)->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->birthdays->present($user, now(tenant_timezone()))]);
    }

    /** GET /api/birthdays/{id}/gifts — regalos de su cumpleaños, catálogo y si se puede enviar hoy */
    public function gifts(Request $request, int $id): JsonResponse
    {
        $recipient = $this->birthdays->people(current_tenant_id())->where('users.id', $id)->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->gifts->board($recipient, $request->user(), now(tenant_timezone()))]);
    }

    /** POST /api/birthdays/{id}/gifts {type} — solo el día de su cumpleaños y no a uno mismo */
    public function sendGift(Request $request, int $id): JsonResponse
    {
        $data      = $request->validate(['type' => ['required', Rule::in(array_keys(BirthdayGiftService::CATALOG))]]);
        $recipient = $this->birthdays->people(current_tenant_id())->where('users.id', $id)->firstOrFail();
        $sender    = $request->user();
        $today     = now(tenant_timezone());

        if ($recipient->id === $sender->id) {
            return response()->json(['success' => false, 'message' => 'No puedes enviarte regalos a ti mismo.'], 422);
        }
        if (! $this->gifts->isBirthday($recipient, $today)) {
            return response()->json(['success' => false, 'message' => 'Los regalos solo se pueden enviar el día de su cumpleaños.', 'code' => 'GIFT_EXPIRED'], 422);
        }
        if (! $gift = $this->gifts->send($recipient, $sender, $data['type'], $today)) {
            return response()->json(['success' => false, 'message' => 'Ya le enviaste este regalo hoy.', 'code' => 'GIFT_ALREADY_SENT'], 409);
        }

        return response()->json(['success' => true, 'message' => '¡Regalo enviado!', 'data' => $this->gifts->present($gift->load('sender'), $today)], 201);
    }
}
