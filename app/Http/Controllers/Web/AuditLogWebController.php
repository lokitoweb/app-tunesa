<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Presenters\AuditLogPresenter;
use App\Support\Labels;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bitácora de auditoría (audit_logs): quién hizo qué y cuándo.
 * Con empresa activa el TenantScope deja solo sus registros; en la vista
 * global del Super Admin se ven los de todas, con su empresa.
 */
class AuditLogWebController extends Controller
{
    public function index(Request $request): View
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

        $viewer     = $request->user();
        $presenters = $logs->getCollection()->map(fn (AuditLog $log) => AuditLogPresenter::make($log, $viewer));

        return view('audit.index', compact('logs', 'presenters', 'actions', 'users'));
    }

    /** @deprecated Usa App\Support\Labels::action() */
    public static function actionLabel(string $action): string
    {
        return Labels::action($action);
    }
}
