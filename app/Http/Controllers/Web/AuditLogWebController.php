<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Scopes\TenantScope;
use App\Models\User;
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
    /** Etiquetas de las acciones registradas por AuditLogger */
    public const ACTIONS = [
        'auth.account_locked'               => 'Cuenta bloqueada por intentos fallidos',
        'company_configuration.updated'     => 'Configuración de la app cambiada',
        'document.uploaded'                 => 'Documento subido',
        'document.viewed'                   => 'Documento abierto',
        'document.approved'                 => 'Documento aprobado',
        'document.rejected'                 => 'Documento rechazado',
        'payment_info.revealed'             => 'Cuenta bancaria revelada',
        'payment_info.verified'             => 'Cuenta bancaria verificada',
        'payment_info.rejected'             => 'Cuenta bancaria rechazada',
        'payment_info.updated_by_employee'  => 'Datos de pago editados por el colaborador',
        'profile.updated_by_hr'             => 'Ficha laboral editada por RRHH',
        'payroll.exported'                  => 'Planilla exportada',
        'payslip.deleted'                   => 'Boleta eliminada',
        'payslip.archived'                  => 'Boleta anulada',
        'branch.deleted'                    => 'Sucursal eliminada',
        'holiday.created'                   => 'Feriado creado',
        'holiday.deleted'                   => 'Feriado eliminado',
        'tenant.created'                    => 'Empresa creada',
        'tenant.updated'                    => 'Empresa actualizada',
    ];

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

        $actions = AuditLog::forTenant($tenantId)->distinct()->orderBy('action')->pluck('action');

        $users = User::withoutGlobalScope(TenantScope::class)->withTrashed()
            ->whereIn('id', AuditLog::forTenant($tenantId)->whereNotNull('user_id')->distinct()->select('user_id'))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'tenant_id']);

        return view('audit.index', compact('logs', 'actions', 'users'));
    }

    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }

    /** meta → lista plana clave: valor para mostrar */
    public static function metaPairs(?array $meta): array
    {
        $pairs = [];
        foreach ($meta ?? [] as $key => $value) {
            $pairs[$key] = match (true) {
                is_bool($value)  => $value ? 'sí' : 'no',
                is_array($value) => array_is_list($value) ? implode(', ', array_map('strval', array_filter($value, 'is_scalar'))) : json_encode($value, JSON_UNESCAPED_UNICODE),
                $value === null  => '—',
                default          => (string) $value,
            };
        }

        return $pairs;
    }
}
