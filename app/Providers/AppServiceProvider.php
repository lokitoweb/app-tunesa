<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use App\Models\Attendance;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
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

        // Los permisos sembrados (RolesAndPermissionsSeeder) funcionan como
        // abilities de Gate: $user->can('shifts.manage'), @can en Blade, etc.
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ? true : null;
        });
    }
}
