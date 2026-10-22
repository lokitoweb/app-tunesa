<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * Notificaciones del usuario en el panel (campana del topbar y listado).
 * Son personales: no dependen de la empresa elegida por el Super Admin.
 */
class NotificationWebController extends Controller
{
    public function index(Request $request): View
    {
        $filter   = $request->input('filter') === 'unread' ? 'unread' : 'all';
        $category = $request->input('category');
        $search   = trim((string) $request->input('search'));
        $perPage  = in_array((int) $request->input('per_page'), [10, 20, 50], true) ? (int) $request->input('per_page') : 20;

        $user = $request->user();

        $counts = [
            'all'    => $user->notifications()->count(),
            'unread' => $user->unreadNotifications()->count(),
        ];

        $query = $filter === 'unread' ? $user->unreadNotifications() : $user->notifications();

        if ($category) {
            $prefix = match ($category) {
                'leave'      => 'leave_request.',
                'attendance' => 'attendance.',
                'payroll'    => 'payslip.',
                'documents'  => 'document.',
                'security'   => 'security.',
                default      => null,
            };

            if ($prefix) {
                if ($category === 'attendance') {
                    $query->where(function ($q) {
                        $q->where('data->type', 'like', 'justification.%')
                          ->orWhere('data->type', 'like', 'overtime.%')
                          ->orWhere('data->type', 'like', 'attendance.%');
                    });
                } elseif ($category === 'payroll') {
                    $query->where(function ($q) {
                        $q->where('data->type', 'like', 'payslip.%')
                          ->orWhere('data->type', 'like', 'report.%');
                    });
                } else {
                    $query->where('data->type', 'like', "{$prefix}%");
                }
            }
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('data->title', 'like', "%{$search}%")
                  ->orWhere('data->body', 'like', "%{$search}%");
            });
        }

        $notifications = $query->paginate($perPage)->withQueryString();

        return view('notifications.index', compact('notifications', 'filter', 'category', 'search', 'counts'));
    }

    /** Marca como leída y abre el módulo relacionado (si lo hay en el panel) */
    public function read(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $target = self::targetUrl($notification, $request->user());
        if ($target) {
            return redirect()->to($target);
        }

        return back()->with('success', 'Notificación marcada como leída.');
    }

    /** Alternar entre leída y no leída */
    public function toggleRead(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);

        if ($notification->read_at) {
            $notification->update(['read_at' => null]);
            $msg = 'Notificación marcada como no leída.';
        } else {
            $notification->markAsRead();
            $msg = 'Notificación marcada como leída.';
        }

        return back()->with('success', $msg);
    }

    /** Eliminar una notificación */
    public function destroy(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->delete();

        return back()->with('success', 'Notificación eliminada.');
    }

    /** Marcar todas como leídas */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'Todas las notificaciones quedaron marcadas como leídas.');
    }

    /** Pantalla del panel a la que lleva la notificación, o null si es solo de la app */
    public static function targetUrl(DatabaseNotification $notification, User $user): ?string
    {
        $data = $notification->data['data'] ?? [];

        return match ($notification->data['type'] ?? null) {
            'leave_request.submitted' => $user->can('leave.approve_team') ? route('approvals.index') : null,
            'payslip.published'       => $user->can('payslips.manage') && isset($data['period_year'])
                ? route('payslips.index', ['year' => $data['period_year'], 'month' => $data['period_month']])
                : null,
            // Enlace firmado nuevo (el guardado en la notificación vence)
            'report.monthly_ready'    => $user->can('reports.export') && isset($data['tenant_id'], $data['period'])
                ? \App\Modules\Attendance\Notifications\MonthlyReportReady::signedUrl((int) $data['tenant_id'], (string) $data['period'], 1)
                : null,
            default => null,
        };
    }

    /** Metadatos de la categoría (etiqueta, clase de color, icono) */
    public static function categoryMeta(?string $type): array
    {
        $t = (string) $type;
        if (str_starts_with($t, 'leave_request')) {
            return ['label' => 'Permisos & Vacaciones', 'color' => 'success', 'icon' => 'calendar2-check'];
        }
        if (str_starts_with($t, 'justification') || str_starts_with($t, 'overtime') || str_starts_with($t, 'attendance')) {
            return ['label' => 'Asistencia & Horas Extra', 'color' => 'warning', 'icon' => 'clock-history'];
        }
        if (str_starts_with($t, 'payslip') || str_starts_with($t, 'report')) {
            return ['label' => 'Planilla & Reportes', 'color' => 'primary', 'icon' => 'file-earmark-text'];
        }
        if (str_starts_with($t, 'document')) {
            return ['label' => 'Documentación', 'color' => 'info', 'icon' => 'folder-check'];
        }
        if (str_starts_with($t, 'security')) {
            return ['label' => 'Seguridad', 'color' => 'danger', 'icon' => 'shield-lock'];
        }

        return ['label' => 'Aviso General', 'color' => 'secondary', 'icon' => 'bell'];
    }

    /** Icono bootstrap-icons según el tipo */
    public static function icon(?string $type): string
    {
        return self::categoryMeta($type)['icon'];
    }
}
