<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        $filter = $request->input('filter') === 'unread' ? 'unread' : 'all';

        $notifications = ($filter === 'unread'
                ? $request->user()->unreadNotifications()
                : $request->user()->notifications())
            ->paginate(20)
            ->withQueryString();

        return view('notifications.index', compact('notifications', 'filter'));
    }

    /** Marca como leída y abre el módulo relacionado (si lo hay en el panel) */
    public function read(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return redirect()->to(self::targetUrl($notification, $request->user()) ?? route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'Todas las notificaciones quedaron como leídas.');
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
                ? \App\Notifications\MonthlyReportReady::signedUrl((int) $data['tenant_id'], (string) $data['period'], 1)
                : null,
            default => null,
        };
    }

    /** Icono bootstrap-icons según el tipo */
    public static function icon(?string $type): string
    {
        return match ($type) {
            'leave_request.submitted', 'leave_request.approved', 'leave_request.rejected' => 'calendar2-check',
            'payslip.published'                                => 'file-earmark-text',
            'report.monthly_ready'                             => 'file-earmark-spreadsheet',
            'document.approved', 'document.rejected'           => 'folder-check',
            'justification.approved', 'justification.rejected' => 'clock-history',
            default                                            => 'bell',
        };
    }
}
