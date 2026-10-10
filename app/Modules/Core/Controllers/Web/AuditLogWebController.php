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
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bitácora de auditoría (audit_logs): quién hizo qué y cuándo.
 * Con empresa activa el TenantScope deja solo sus registros; en la vista
 * global del Super Admin se ven los de todas, con su empresa.
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
        ], [], ['from' => 'desde', 'to' => 'hasta']);

        $tenantId = current_tenant_id_or_null();

        $logs = AuditLog::forTenant($tenantId)
            ->when($validated['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($validated['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($validated['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($validated['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->with('user:id,first_name,last_name,email,role')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Filtro "Acción": etiquetas en español, ordenadas alfabéticamente
        $actions = AuditLog::forTenant($tenantId)->distinct()->pluck('action')
            ->mapWithKeys(fn ($a) => [$a => Labels::action($a)])
            ->sortBy(fn ($label) => \Illuminate\Support\Str::ascii($label));

        $users = User::withoutGlobalScope(TenantScope::class)->withTrashed()
            ->whereIn('id', AuditLog::forTenant($tenantId)->whereNotNull('user_id')->distinct()->select('user_id'))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'tenant_id']);

        $viewer = $request->user();

        return Inertia::render('Core/Pages/Audit/Index', [
            'logs'    => $logs->through(fn (AuditLog $log) => AuditLogPresenter::make($log, $viewer)->toArray()),
            'actions' => $actions->map(fn ($label, $action) => ['value' => $action, 'label' => $label])->values(),
            'users'   => $users->map(fn (User $u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")]),
            'filters' => [
                'from'    => $validated['from'] ?? '',
                'to'      => $validated['to'] ?? '',
                'action'  => $validated['action'] ?? '',
                'user_id' => $validated['user_id'] ?? '',
            ],
        ]);
    }

    /** @deprecated Usa App\Modules\Core\Support\Labels::action() */
    public static function actionLabel(string $action): string
    {
        return Labels::action($action);
    }
}
