<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notificaciones in-app del usuario autenticado (cualquier rol).
 *
 *   GET  /api/notifications              Paginado de 20, más recientes primero
 *   POST /api/notifications/{id}/read    Marcar una como leída
 *   POST /api/notifications/read-all     Marcar todas como leídas
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        $user = $request->user();
        $page = $user->notifications()->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn (DatabaseNotification $n) => self::format($n))->values(),
            'meta'    => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'data'    => self::format($notification),
            'meta'    => ['unread_count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'meta'    => ['unread_count' => 0],
        ]);
    }

    public static function format(DatabaseNotification $n): array
    {
        return [
            'id'         => $n->id,
            'type'       => $n->data['type'] ?? $n->type,
            'title'      => $n->data['title'] ?? '',
            'body'       => $n->data['body'] ?? '',
            'data'       => (object) ($n->data['data'] ?? []),
            'read_at'    => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
