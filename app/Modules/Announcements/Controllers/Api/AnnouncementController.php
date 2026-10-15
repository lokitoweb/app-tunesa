<?php

namespace App\Modules\Announcements\Controllers\Api;

use App\Modules\Announcements\Models\Announcement;
use App\Modules\Announcements\Services\AnnouncementService;
use App\Modules\Core\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Comunicados del colaborador (app móvil): los enviados a su sede, área, cargo o a él */
class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    /** GET /api/announcements */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $list = Announcement::sent()->visibleToAudience($user)
            ->where('sent_at', '>=', now()->subMonths(6))
            ->withExists(['reads as read' => fn ($q) => $q->where('user_id', $user->id)])
            ->latest('sent_at')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $list->map(fn (Announcement $a) => $this->summary($a))->values(),
            'meta'    => ['unread' => $list->where('read', false)->count()],
        ]);
    }

    /** GET /api/announcements/{id}: abrirlo lo marca como leído */
    public function show(Request $request, int $id): JsonResponse
    {
        $a = Announcement::sent()->visibleToAudience($request->user())->findOrFail($id);
        $this->announcements->markRead($a, $request->user());
        $a->setAttribute('read', true);

        return response()->json(['success' => true, 'data' => $this->summary($a) + ['body' => $a->body]]);
    }

    private function summary(Announcement $a): array
    {
        return [
            'id'             => $a->id,
            'title'          => $a->title,
            'excerpt'        => \Illuminate\Support\Str::limit(strip_tags($a->body), 160),
            'category'       => $a->category,
            'category_label' => $a->categoryLabel(),
            'is_important'   => $a->is_important,
            'event_at'       => $a->event_at?->toIso8601String(),
            'location'       => $a->location,
            'url'            => $a->url,
            'sent_at'        => $a->sent_at?->toIso8601String(),
            'read'           => (bool) $a->read,
        ];
    }
}
