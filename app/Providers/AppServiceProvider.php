<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\EmployeeBranch;
use App\Modules\Training\Services\TrainingEnrollmentService;
use App\Modules\Integrations\Services\IntegrationDispatcher;
use App\Modules\Core\Services\NotificationDispatcher;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // El panel web usa Bootstrap 5
        Paginator::useBootstrapFive();

        // Proxies de confianza (ver config/app.php): vale también con config:cache
        TrustProxies::at(config('app.trusted_proxies'));

        // Límite usado por el grupo 'api' en bootstrap/app.php (ThrottleRequests:api)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // El enlace del correo de recuperación abre el panel de la empresa
        // (o la app móvil vía deep link) con el token para /api/auth/reset-password.
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            return rtrim(config('app.url'), '/') . '/reset-password?' . http_build_query([
                'token'  => $token,
                'email'  => $user->email,
                'tenant' => $user->tenant?->slug,
            ]);
        });

        // Notificaciones in-app (canal database) desde los eventos de los modelos:
        // cubren API, panel web y cargas masivas por igual.
        $notify = fn () => app(NotificationDispatcher::class);
        LeaveRequest::created(fn (LeaveRequest $m) => $notify()->leaveCreated($m));
        LeaveRequest::updated(fn (LeaveRequest $m) => $notify()->leaveUpdated($m));
        Payslip::created(fn (Payslip $m) => $notify()->payslipSaved($m, created: true));
        Payslip::updated(fn (Payslip $m) => $notify()->payslipSaved($m));
        EmployeeDocument::updated(fn (EmployeeDocument $m) => $notify()->documentUpdated($m));
        Attendance::updated(fn (Attendance $m) => $notify()->attendanceUpdated($m));
        EmployeeShift::created(fn (EmployeeShift $m) => $notify()->shiftAssigned($m, created: true));
        EmployeeShift::updated(fn (EmployeeShift $m) => $notify()->shiftAssigned($m));
        TenantSetting::saved(fn (TenantSetting $m) => $notify()->appSettingsSaved($m));

        // Capacitaciones: al dar de alta a alguien o cambiarle sucursal, área o
        // cargo recibe los cursos publicados que le corresponden
        $trainings = fn () => app(TrainingEnrollmentService::class);
        $syncTrainings = function (User $user) use ($trainings) {
            try {
                $trainings()->syncUser($user);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('No se pudieron asignar capacitaciones: ' . $e->getMessage(), ['user_id' => $user->id]);
            }
        };
        User::created(fn (User $u) => $syncTrainings($u));
        User::updated(fn (User $u) => $u->wasChanged(['department_id', 'position', 'status', 'role']) ? $syncTrainings($u) : null);
        EmployeeBranch::saved(fn (EmployeeBranch $p) => ($u = User::withoutGlobalScopes()->find($p->user_id)) ? $syncTrainings($u) : null);

        // Integraciones de "Marca y app": webhook (marcaciones y permisos) y
        // endpoint de notificaciones (cada aviso in-app). Encolados y firmados.
        $integrations = fn () => app(IntegrationDispatcher::class);
        Attendance::created(fn (Attendance $m) => $integrations()->attendanceSaved($m, created: true));
        Attendance::updated(fn (Attendance $m) => $integrations()->attendanceSaved($m));
        LeaveRequest::updated(fn (LeaveRequest $m) => $integrations()->leaveUpdated($m));
        Event::listen(function (NotificationSent $event) use ($integrations) {
            if ($event->channel === 'database' && $event->response instanceof DatabaseNotification) {
                $integrations()->notificationCreated($event->notifiable, $event->response);
            }
        });

        // Calendario con semáforo: un cambio en una jornada o un permiso invalida la caché de ese mes
        $bumpAttendance = function (Attendance $m) {
            foreach (array_filter([$m->work_date?->toDateString(), $m->getOriginal('work_date') ? \Carbon\Carbon::parse($m->getOriginal('work_date'))->toDateString() : null]) as $day) {
                AttendanceCalendarService::bump($m->tenant_id, $day);
            }
        };
        Attendance::saved($bumpAttendance);
        Attendance::deleted($bumpAttendance);
        $bumpLeave = function (LeaveRequest $m) {
            if (! $m->start_date || ! $m->end_date) {
                return;
            }
            for ($d = $m->start_date->copy()->startOfMonth(); $d->lte($m->end_date); $d->addMonthNoOverflow()) {
                AttendanceCalendarService::bump($m->tenant_id, $d->toDateString());
            }
        };
        LeaveRequest::saved($bumpLeave);
        LeaveRequest::deleted($bumpLeave);

        // Los permisos sembrados (RolesAndPermissionsSeeder) funcionan como
        // abilities de Gate: $user->can('shifts.manage'), @can en Blade, etc.
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ? true : null;
        });
    }
}
