<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\User;
use App\Modules\Core\Presenters\AuditLogPresenter;
use App\Modules\Core\Support\Labels;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bitácora de auditoría (audit_logs): quién hizo qué y cuándo.
 * Con empresa activa el TenantScope deja solo sus registros; en la vista
 * global del Super Admin se ven los de todas, con su empresa.
 * Búsqueda, filtros, pestañas por tipo, totales y páginas se resuelven aquí sobre toda la
 * bitácora (25 por página), no en el navegador sobre la página que llegó.
 */
class AuditLogWebController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date', 'after_or_equal:from'],
            'action'  => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer'],
            'q'       => ['nullable', 'string', 'max:100'],
            'tone'    => ['nullable', 'in:success,primary,warning,danger'],
        ], [], ['from' => 'desde', 'to' => 'hasta']);

        $tenantId = current_tenant_id_or_null();

        // Acciones registradas con su etiqueta y tono (Labels): alimentan el filtro, la búsqueda y las pestañas
        $actionCounts = AuditLog::forTenant($tenantId)->selectRaw('action, COUNT(*) as n')->groupBy('action')->pluck('n', 'action');
        $badges = $actionCounts->keys()->mapWithKeys(fn ($a) => [$a => Labels::actionBadge($a)]);
        $actions = $badges->map(fn ($b) => $b['label'])->sortBy(fn ($label) => Str::ascii($label));

        // Toda la bitácora, no solo la página: filtros, búsqueda y totales van en la consulta
        $filtered = AuditLog::forTenant($tenantId)
            ->when($validated['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($validated['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($validated['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($validated['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when(trim((string) ($validated['q'] ?? '')), fn ($q, $term) => $this->search($q, $term, $actions));

        // Pestañas por tipo: cuántos hay de cada tono con los demás filtros
        $byAction = (clone $filtered)->selectRaw('action, COUNT(*) as n')->groupBy('action')->pluck('n', 'action');
        $toneCounts = ['all' => (int) $byAction->sum(), 'success' => 0, 'primary' => 0, 'warning' => 0, 'danger' => 0];
        foreach ($byAction as $action => $n) {
            $tone = $badges[$action]['tone'] ?? Labels::actionBadge($action)['tone'];
            if (isset($toneCounts[$tone])) {
                $toneCounts[$tone] += (int) $n;
            }
        }

        $shown = (clone $filtered)->when($validated['tone'] ?? null,
            fn ($q, $tone) => $q->whereIn('action', $badges->filter(fn ($b) => $b['tone'] === $tone)->keys()->all()));

        $logs = (clone $shown)
            ->with('user:id,first_name,last_name,email,role')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'total'   => $logs->total(),
            'users'   => (clone $shown)->whereNotNull('user_id')->distinct()->count('user_id'),
            'changes' => (clone $shown)->whereNotNull('meta->changes')->count(),
            'exports' => (clone $shown)->where('action', 'like', '%export%')->count(),
        ];

        $users = User::withoutGlobalScope(TenantScope::class)->withTrashed()
            ->whereIn('id', AuditLog::forTenant($tenantId)->whereNotNull('user_id')->distinct()->select('user_id'))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'tenant_id']);

        $viewer = $request->user();

        return Inertia::render('Core/Pages/Audit/Index', [
            'logs'    => $logs->through(fn (AuditLog $log) => AuditLogPresenter::make($log, $viewer)->toArray()),
            'actions' => $actions->map(fn ($label, $action) => ['value' => $action, 'label' => $label])->values(),
            'users'   => $users->map(fn (User $u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")]),
            'stats'      => $stats,
            'toneCounts' => $toneCounts,
            'filters' => [
                'from'    => $validated['from'] ?? '',
                'to'      => $validated['to'] ?? '',
                'action'  => $validated['action'] ?? '',
                'user_id' => $validated['user_id'] ?? '',
                'q'       => $validated['q'] ?? '',
                'tone'    => $validated['tone'] ?? 'all',
            ],
        ]);
    }

    /**
     * Búsqueda libre: nombre de quien lo hizo, acción (por su nombre en español), IP o el detalle guardado.
     *
     * @param  \Illuminate\Support\Collection<string, string>  $actions  acción => etiqueta
     */
    private function search($query, string $term, $actions)
    {
        $needle = Str::lower(Str::ascii($term));
        $matchingActions = $actions->filter(fn ($label, $action) => str_contains(Str::lower(Str::ascii($label)), $needle) || str_contains($action, $needle))->keys()->all();
        $like = fn (string $s) => "%{$s}%";
        // El detalle (JSON) se compara en minúsculas. MySQL guarda «MAÑANA» tal cual; otros motores lo guardan escapado
        // («MAÑANA», y ñ ≠ Ñ en el código): se buscan ambas formas, con «_» en lugar de la barra invertida, que en
        // LIKE se interpreta distinto según el motor
        $variants = collect([$term, mb_strtoupper($term), mb_strtolower($term)])
            ->flatMap(fn ($t) => [$t, str_replace('\\', '_', substr(json_encode($t), 1, -1))])
            ->map(fn ($t) => mb_strtolower($t))->unique()->values();
        $words = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY);

        return $query->where(fn ($q) => $q
            ->whereIn('action', $matchingActions)
            ->orWhere('ip', 'like', $like($term))
            ->orWhere(function ($m) use ($variants, $like) {
                foreach ($variants as $variant) {
                    $m->orWhereRaw('LOWER(CAST(meta AS CHAR)) LIKE ?', [$like($variant)]);
                }
            })
            // «Elder Ruiz»: cada palabra en el nombre o el apellido (sin funciones propias de un motor de base de datos)
            ->orWhereHas('user', fn ($u) => $u->withoutGlobalScope(TenantScope::class)->where(function ($n) use ($words, $like) {
                foreach ($words as $word) {
                    $n->where(fn ($w) => $w->where('first_name', 'like', $like($word))->orWhere('last_name', 'like', $like($word)));
                }
            })));
    }
}
