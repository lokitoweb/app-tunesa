<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Attendance\Services\LiveBoardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Tablero en vivo: quién está trabajando, en descanso, por llegar, etc.
 *
 * GET /live       → página (contadores, lista filtrable y mapa)
 * GET /live/data  → JSON que la página consulta cada 10 s con If-None-Match;
 *                   responde 304 si nada cambió desde el último ETag.
 */
class LiveBoardController extends Controller
{
    public function __construct(private readonly LiveBoardService $board) {}

    public function index(Request $request): View
    {
        $this->authorizeBoard($request);

        $branches = Branch::forTenant(current_tenant_id_or_null())
            ->visibleTo($request->user())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'tenant_id', 'latitude', 'longitude', 'allowed_radius_meters']);

        return view('live.index', [
            'branches'       => $branches,
            'statuses'       => LiveBoardService::STATUS_LABELS,
            'hasSuspicious'  => $this->board->tracksSuspicious(),
        ]);
    }

    public function data(Request $request): JsonResponse|Response
    {
        $this->authorizeBoard($request);

        $branchId = $request->integer('branch_id') ?: null;
        $snapshot = $this->board->snapshot(current_tenant_id_or_null(), $request->user(), $branchId);

        $etag = '"' . $snapshot['etag'] . '"';
        $headers = [
            'ETag'          => $etag,
            'Cache-Control' => 'private, no-cache',
            'Vary'          => 'Cookie',
        ];

        $sent = array_map('trim', explode(',', (string) $request->header('If-None-Match')));
        if (in_array($etag, $sent, true) || in_array('W/' . $etag, $sent, true)) {
            return response('', 304, $headers);
        }

        return response()->json($this->board->present($snapshot), 200, $headers);
    }

    private function authorizeBoard(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->can('attendance.view_team') || $user->can('attendance.view_all'), 403);
    }
}
