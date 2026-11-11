<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\HR\Controllers\Web\PortalController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Marcar mi asistencia" en el panel, para quien trabaja en el panel: jefaturas y oficina con el permiso
 * attendance.web_punch, o cualquiera que marque con un plan de contingencia vigente. Mismo estado y misma
 * marcación que Mi espacio (PortalController), sin GPS y con la hora del servidor.
 */
class MyPunchWebController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless(PortalController::canPunchFromWeb($request->user()), 404);

        return Inertia::render('Attendance/Pages/MyPunch', PortalController::punchState($request));
    }

    public function store(Request $request): RedirectResponse
    {
        return app(PortalController::class)->punch($request);
    }
}
