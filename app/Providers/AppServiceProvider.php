<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use App\Models\User;
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

        // Los permisos sembrados (RolesAndPermissionsSeeder) funcionan como
        // abilities de Gate: $user->can('shifts.manage'), @can en Blade, etc.
        Gate::before(function (User $user, string $ability) {
            return $user->hasPermission($ability) ? true : null;
        });
    }
}
