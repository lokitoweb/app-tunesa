<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\Shift;
use App\Modules\HR\Services\ScheduleProgramming;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Programación por calendario (QA Ronda 2, HQ-05): elegir la agencia, elegir el
 * horario, marcar los días; los días en blanco son días en que no se labora.
 */
class ScheduleWebController extends Controller
{
    /** Tope de un guardado de rango y de una repetición (días) para no lanzar escrituras gigantes */
    public const MAX_RANGE_DAYS = 62;
    public const MAX_REPEAT_DAYS = 366;

    /** Regla vigente 6: el mes se programa al menos 5 días antes de que empiece */
    public const PROGRAMMING_LEAD_DAYS = 5;

    /** Tope de colaboradores por guardado y de días a escribir en una repetición (una petición no debe escribir sin límite) */
    public const MAX_PEOPLE = 500;
    public const MAX_REPEAT_CELLS = 40000;

    public function __construct(private readonly ScheduleProgramming $programming) {}

    public function index(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'month'     => ['nullable', 'date_format:Y-m'],
        ]);
        $branches = Branch::visibleTo($request->user())->where('is_active', true)->orderBy('name')->get(['id', 'name', 'tenant_id']);
        $branch = $branches->firstWhere('id', (int) ($data['branch_id'] ?? 0)) ?? $branches->first();
        $month = CarbonImmutable::parse(($data['month'] ?? CarbonImmutable::now(tenant_timezone())->format('Y-m')) . '-01');
        [$from, $to] = [$month->toDateString(), $month->endOfMonth()->toDateString()];

        $people = $branch ? $this->programming->people($branch, $request->user(), $from, $to) : collect();
        $payload = [
            'branchId' => $branch?->id,
            'month'    => $month->format('Y-m'),
            'from'     => $from,
            'to'       => $to,
            'people'   => $people->map(fn ($u) => ['id' => $u->id, 'name' => trim("{$u->last_name}, {$u->first_name}"), 'code' => $u->employee_code, 'position' => $u->position])->values(),
            'grid'     => $branch ? $this->programming->grid($people, $from, $to) : [],
            // Regla 6: el mes se programa con PROGRAMMING_LEAD_DAYS días de anticipación
            'deadline' => $month->subDays(self::PROGRAMMING_LEAD_DAYS)->toDateString(),
            'holidays' => Holiday::where('tenant_id', $branch?->tenant_id)->whereBetween('date', [$from, $to])->get(['date', 'name'])
                ->mapWithKeys(fn (Holiday $h) => [$h->date->toDateString() => $h->name]),
        ];

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        $tenantId = $branch?->tenant_id;

        return Inertia::render('HR/Pages/Schedule/Index', $payload + [
            'branches'    => $branches->map(fn (Branch $b) => $b->only(['id', 'name'])),
            'shifts'      => Shift::where('tenant_id', $tenantId)->where('is_active', true)->orderBy('start_time')->get()
                ->map(fn (Shift $s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color ?: '#64748B',
                    'start' => substr((string) $s->start_time, 0, 5), 'end' => substr((string) $s->end_time, 0, 5)]),
            'today'       => CarbonImmutable::now(tenant_timezone())->toDateString(),
            'flagEnabled' => $tenantId ? app(ScheduleResolver::class)->enabled($tenantId) : false,
        ]);
    }

    /** Guarda el mes (o el rango) de la agencia para los colaboradores enviados */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'from'      => ['required', 'date_format:Y-m-d'],
            'to'        => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reason'    => ['required', 'string', 'min:5', 'max:300'],
            'rows'      => ['required', 'array', 'min:1', 'max:' . self::MAX_PEOPLE],
            'rows.*.user_id' => ['required', 'integer', 'distinct'],
            'rows.*.days'    => ['present', 'array'],
            'rows.*.days.*'  => ['nullable', 'integer'],
        ], [
            'reason.required' => 'Indica el motivo de la programación.',
            'reason.min'      => 'Explica un poco más el motivo.',
        ]);
        $this->guardRange($data['from'], $data['to'], self::MAX_RANGE_DAYS, 'to');
        [$branch, $userIds] = $this->scope($request, (int) $data['branch_id'], array_column($data['rows'], 'user_id'), $data['from'], $data['to']);

        $values = [];
        foreach ($data['rows'] as $row) {
            foreach ($row['days'] as $date => $shiftId) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || $date < $data['from'] || $date > $data['to']) {
                    throw ValidationException::withMessages(['rows' => 'Hay días fuera del periodo elegido.']);
                }
                $values[(int) $row['user_id']][$date] = $shiftId;
            }
        }
        $this->guardShifts(collect($values)->flatten()->filter()->unique()->all(), (int) $branch->tenant_id);

        $result = $this->programming->saveRange($request->user(), $branch, $userIds, $data['from'], $data['to'], $values, $data['reason']);

        return response()->json(['message' => "Programación guardada: {$result['created']} días nuevos, {$result['updated']} cambiados.", 'result' => $result]);
    }

    /** Copiar semana o repetir las semanas elegidas hasta una fecha */
    public function repeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id'   => ['required', 'integer'],
            'user_ids'    => ['nullable', 'array'],
            'user_ids.*'  => ['integer', 'distinct'],
            'source_from' => ['required', 'date_format:Y-m-d'],
            'source_to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:source_from'],
            'target_from' => ['required', 'date_format:Y-m-d', 'after:source_to'],
            'until'       => ['required', 'date_format:Y-m-d', 'after_or_equal:target_from'],
            'reason'      => ['required', 'string', 'min:5', 'max:300'],
        ], [
            'target_from.after' => 'La copia empieza después de las semanas de origen.',
            'reason.required'   => 'Indica el motivo de la programación.',
        ]);
        $this->guardRange($data['source_from'], $data['source_to'], self::MAX_RANGE_DAYS, 'source_to');
        $this->guardRange($data['target_from'], $data['until'], self::MAX_REPEAT_DAYS, 'until');

        $branch = Branch::visibleTo($request->user())->findOrFail((int) $data['branch_id']);
        $people = $this->programming->people($branch, $request->user(), $data['source_from'], $data['until']);
        if ($data['user_ids'] ?? null) {
            [, $ids] = $this->scope($request, $branch->id, $data['user_ids'], $data['source_from'], $data['until']);
            $people = $people->whereIn('id', $ids)->values();
        }

        $cells = $people->count() * (CarbonImmutable::parse($data['target_from'])->diffInDays(CarbonImmutable::parse($data['until'])) + 1);
        if ($cells > self::MAX_REPEAT_CELLS) {
            throw ValidationException::withMessages(['until' => 'Es demasiado para una sola vez: repite por menos semanas o para menos colaboradores.']);
        }

        $result = $this->programming->repeat($request->user(), $branch, $people, $data['source_from'], $data['source_to'], $data['target_from'], $data['until'], $data['reason']);

        return response()->json(['message' => "Programación repetida hasta el " . CarbonImmutable::parse($data['until'])->format('d/m/Y') . ": {$result['created']} días nuevos, {$result['updated']} cambiados.", 'result' => $result]);
    }

    /** La agencia es de la empresa y cada colaborador está en ella y al alcance de quien programa */
    private function scope(Request $request, int $branchId, array $userIds, string $from, string $to): array
    {
        $branch = Branch::visibleTo($request->user())->findOrFail($branchId);
        $allowed = $this->programming->people($branch, $request->user(), $from, $to)->pluck('id')->all();
        $foreign = array_diff(array_map('intval', $userIds), $allowed);
        if ($foreign) {
            throw ValidationException::withMessages(['rows' => 'Hay colaboradores que no pertenecen a esta agencia o están fuera de tu alcance.']);
        }

        return [$branch, array_map('intval', $userIds)];
    }

    /** Los turnos existen y son de la empresa de la agencia (también para el super admin, sin empresa fija) */
    private function guardShifts(array $ids, int $tenantId): void
    {
        if ($ids && Shift::where('tenant_id', $tenantId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['rows' => 'Hay horarios que no existen en la empresa.']);
        }
    }

    private function guardRange(string $from, string $to, int $max, string $field): void
    {
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > $max) {
            throw ValidationException::withMessages([$field => "El periodo no puede pasar de {$max} días."]);
        }
    }
}
