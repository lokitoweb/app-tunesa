<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Attendance\Controllers\Api\AttendanceController;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Resources\AttendanceResource;
use App\Modules\Attendance\Services\ScheduleGate;
use App\Modules\Core\Controllers\Controller;
use App\Modules\HR\Controllers\Api\Employee\ProfileController;
use App\Modules\ModuleRegistry;
use App\Modules\Payroll\Controllers\Api\PayslipController;
use App\Modules\Payroll\Models\Payslip;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mi espacio: el portal web del colaborador. Muestra lo mismo que la app con la misma lógica
 * (AttendanceController::monthFor, AttendanceResource, ProfileController::payload,
 * PayslipController::formatPayslip) y respeta lo que la empresa decide mostrar al colaborador.
 * Todo es de la propia persona: nada de otros colaboradores.
 */
class PortalController extends Controller
{
    public function home(Request $request): Response
    {
        $user = $request->user();
        $today = Carbon::now(tenant_timezone())->toDateString();
        $attendance = $this->attendanceEnabled($request)
            ? Attendance::where('user_id', $user->id)->where('work_date', $today)->with(['branch', 'shift'])->first()
            : null;

        return Inertia::render('HR/Pages/Portal/Home', [
            'today'      => $today,
            'attendance' => $attendance ? (new AttendanceResource($attendance))->resolve($request) : null,
            'schedule'   => $this->attendanceEnabled($request) ? app(ScheduleGate::class)->statusFor($user, $today) : null,
            'next'       => AttendanceController::determineNextAction($attendance),
            // Plan de contingencia vigente que la cubre: puede marcar aquí
            'contingency' => $this->attendanceEnabled($request) && $user->hasPermission('attendance.mark')
                ? \App\Modules\Attendance\Models\AttendanceContingency::activeFor($user)?->only(['reason', 'ends_at'])
                : null,
        ]);
    }

    public function attendance(Request $request): Response
    {
        abort_unless($this->attendanceEnabled($request), 404);
        $now = Carbon::now(tenant_timezone());
        $year = $request->integer('year', $now->year);
        $month = min(12, max(1, $request->integer('month', $now->month)));
        ['attendances' => $attendances, 'summary' => $summary] = AttendanceController::monthFor($request->user(), $year, $month);

        return Inertia::render('HR/Pages/Portal/Attendance', [
            'period'  => ['year' => $year, 'month' => $month],
            'summary' => $summary,
            'days'    => AttendanceResource::collection($attendances->getCollection())->resolve($request),
        ]);
    }

    /**
     * Marca de contingencia: solo con un plan vigente que cubra a la persona. Pasa por el mismo
     * servicio que la app (orden de las marcas, turno, incorporación) con la hora del servidor y
     * sin GPS; queda etiquetada con el plan.
     */
    public function punch(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->attendanceEnabled($request) && $user->hasPermission('attendance.mark'), 404);
        $data = $request->validate(['type' => ['required', \Illuminate\Validation\Rule::in(\App\Modules\Attendance\Models\AttendancePunch::TYPES)]]);

        $plan = \App\Modules\Attendance\Models\AttendanceContingency::activeFor($user);
        if (! $plan) {
            return back()->with('error', 'No hay un plan de contingencia activo para ti: marca desde la app.');
        }
        if (app(\App\Modules\Onboarding\Middleware\EnsureOnboardingApproved::class)->blocks($user)) {
            return back()->with('error', \App\Modules\Onboarding\Middleware\EnsureOnboardingApproved::MESSAGE);
        }

        try {
            $result = app(\App\Modules\Attendance\Services\AttendanceService::class)->punch($user,
                \App\Modules\Attendance\Services\Attendance\PunchData::contingency($data['type'], $plan->id, $request->ip(), mb_substr((string) $request->userAgent(), 0, 255)));
        } catch (\App\Modules\Attendance\Exceptions\AttendanceException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', self::PUNCH_LABELS[$data['type']] . ' registrada a las ' . $result->punch->server_time->setTimezone(tenant_timezone())->format('H:i') . ' (plan de contingencia).');
    }

    public const PUNCH_LABELS = ['clock_in' => 'Entrada', 'break_start' => 'Inicio de refrigerio', 'break_end' => 'Fin de refrigerio', 'clock_out' => 'Salida'];

    /** Justificar un día propio (tardanza, falta, marca olvidada): lo revisa RR. HH. o su jefe */
    public function justify(Request $request, int $id): RedirectResponse
    {
        abort_unless($this->attendanceEnabled($request), 404);
        $data = $request->validate([
            'notes' => ['required', 'string', 'max:1000'],
            'file'  => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120', new \App\Modules\Core\Rules\SafeUpload],
        ], [], ['notes' => 'el motivo', 'file' => 'el archivo']);
        Attendance::where('user_id', $request->user()->id)->findOrFail($id)->submitJustification($data['notes'], $request->file('file'));

        return back()->with('success', 'Justificación enviada. Te avisaremos cuando sea revisada.');
    }

    public function profile(Request $request): Response
    {
        return Inertia::render('HR/Pages/Portal/Profile', [
            // Lo mismo que ve en la app; los números de cuenta, enmascarados
            'data' => ProfileController::payload($request->user()),
        ]);
    }

    public function payslips(Request $request): Response
    {
        $user = $request->user();
        abort_unless(self::sections($user)['payslips'], 404);

        return Inertia::render('HR/Pages/Portal/Payslips', [
            'payslips' => Payslip::where('user_id', $user->id)->published()
                ->orderByDesc('period_year')->orderByDesc('period_month')->get()
                ->map(fn ($p) => PayslipController::formatPayslip($p))->values(),
        ]);
    }

    /** Cambio de contraseña: el mismo del panel (reglas, cierre de sesiones de la app y aviso) */
    public function password(Request $request): RedirectResponse
    {
        return app(\App\Modules\Core\Controllers\Web\AccountWebController::class)->updatePassword($request);
    }

    /** La misma descarga que la app: solo boletas propias y publicadas */
    public function downloadPayslip(Request $request, int $id): mixed
    {
        abort_unless(self::sections($request->user())['payslips'], 404);

        return app(PayslipController::class)->download($request, $id);
    }

    /**
     * Secciones que la persona puede ver: el módulo activo en su empresa, su permiso y, en las
     * boletas, que la empresa las muestre al colaborador. El menú y cada página usan esta regla.
     *
     * @return array{attendance: bool, payslips: bool}
     */
    public static function sections(\App\Modules\Core\Models\User $user): array
    {
        return [
            'attendance' => ModuleRegistry::enabledFor($user->tenant, 'Attendance') && $user->hasPermission('attendance.view_own'),
            'payslips'   => ModuleRegistry::enabledFor($user->tenant, 'Payroll') && $user->hasPermission('payslips.view_own') && PayslipController::visibleTo($user),
        ];
    }

    private function attendanceEnabled(Request $request): bool
    {
        return self::sections($request->user())['attendance'];
    }
}
