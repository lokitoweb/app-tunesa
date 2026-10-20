<?php

use App\Modules\Core\Controllers\SecureFileController;
use App\Modules\Core\Controllers\Web\AccountWebController;
use App\Modules\Core\Controllers\Web\AuditLogWebController;
use App\Modules\Core\Controllers\Web\AuthController;
use App\Modules\Core\Controllers\Web\BranchWebController;
use App\Modules\Core\Controllers\Web\BrandingWebController;
use App\Modules\Core\Controllers\Web\CompanySettingsWebController;
use App\Modules\Core\Controllers\Web\CompanyWebController;
use App\Modules\Core\Controllers\Web\DashboardController;
use App\Modules\Core\Controllers\Web\LegalController;
use App\Modules\Core\Controllers\Web\NotificationWebController;
use App\Modules\Core\Controllers\Web\OrganizationWebController;
use App\Modules\Core\Controllers\Web\PasswordResetController;
use App\Modules\Core\Services\SecureFileUrl;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Core (panel web): acceso, dashboard, empresas, sucursales, marca y bitácora
|--------------------------------------------------------------------------
| [auth, web.tenant]  → sesión + TenantContext de la empresa del usuario
|                       (activa los global scopes igual que en la API)
| can:<permiso>       → matriz de RolesAndPermissionsSeeder (Gate::before)
*/

Route::get('/', fn () => redirect()->route('dashboard'));

// Archivos privados (legajo, selfies) con URL firmada y temporal
Route::get('/secure-files/{kind}/{id}', [SecureFileController::class, 'show'])
    ->name('secure-files.show')
    ->whereIn('kind', [...SecureFileUrl::KINDS, ...SecureFileUrl::THUMB_KINDS])
    ->whereNumber('id')
    ->middleware(['signed', 'throttle:120,1,secure-files']);

// Páginas públicas para Google Play / App Store (con o sin sesión)
Route::get('/privacidad', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/eliminar-cuenta', [LegalController::class, 'deletionForm'])->name('legal.account-deletion');
Route::post('/eliminar-cuenta', [LegalController::class, 'deletionStore'])->name('legal.account-deletion.store')->middleware('throttle:5,1');

// Rutas públicas de autenticación
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post')->middleware('throttle:10,1');

    // Recuperación de contraseña (panel y colaboradores de la app: mismo correo)
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email')->middleware('throttle:5,1');
    // El correo enlaza a /reset-password?token=...&email=... (AppServiceProvider); también /reset-password/{token}
    Route::get('/reset-password/{token?}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update')->middleware('throttle:5,1');
});

// Logout accesible siempre (incluso con sesión expirada o sin autenticación)
Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'web.tenant'])->group(function () {
    Route::post('/keep-alive', function () {
        return response()->json([
            'status'     => 'alive',
            'csrf_token' => csrf_token(),
            'timestamp'  => now()->timestamp,
        ]);
    })->name('session.keep-alive');

    // Dashboard en tiempo real
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/live-stream', [DashboardController::class, 'liveStream'])
        ->name('dashboard.live-stream')
        ->middleware('throttle:60,1');
    Route::post('/admin/switch-tenant', [DashboardController::class, 'switchTenant'])->name('admin.tenant.switch');

    // Mi cuenta: datos personales y cambio de contraseña
    Route::get('/account', [AccountWebController::class, 'edit'])->name('account.edit');
    Route::put('/account/password', [AccountWebController::class, 'updatePassword'])->name('account.password')->middleware('throttle:6,1');

    // Notificaciones personales (campana del topbar)
    Route::get('/notifications', [NotificationWebController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationWebController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationWebController::class, 'read'])->name('notifications.read')->whereUuid('id');

    // Panel de control de visibilidad de la app móvil
    Route::prefix('settings/mobile')->name('settings.mobile')->middleware('can:company.configure')->group(function () {
        Route::get('/', [CompanySettingsWebController::class, 'edit']);
        Route::put('/', [CompanySettingsWebController::class, 'update'])->name('.update');
    });

    // Marca y app: marca blanca, app móvil y reglas operativas.
    // Vista global del Super Admin = valores por defecto de la plataforma.
    Route::prefix('settings/branding')->name('settings.branding')->middleware('can:company.configure')->group(function () {
        Route::get('/', [BrandingWebController::class, 'edit']);
        Route::put('/', [BrandingWebController::class, 'update'])->name('.update');
    });

    // Gestión de Sucursales y Geocercas (GPS Leaflet)
    Route::prefix('branches')->name('branches.')->middleware('can:branches.manage')->group(function () {
        Route::get('/', [BranchWebController::class, 'index'])->name('index');
        Route::get('/create', [BranchWebController::class, 'create'])->name('create');
        Route::post('/', [BranchWebController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [BranchWebController::class, 'edit'])->name('edit')->whereNumber('id');
        Route::put('/{id}', [BranchWebController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}', [BranchWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
    });

    // Jerarquía organizacional: zonas, sedes, áreas y cargos (pantalla Vue)
    Route::prefix('organization')->name('organization.')->middleware('can:branches.manage')->group(function () {
        Route::get('/', [OrganizationWebController::class, 'index'])->name('index');
        Route::patch('/branches/{id}', [OrganizationWebController::class, 'updateBranch'])->name('branches.update')->whereNumber('id');
        Route::put('/branches/{id}/positions', [OrganizationWebController::class, 'updateBranchPositions'])->name('branches.positions')->whereNumber('id');
        Route::post('/positions/suggested', [OrganizationWebController::class, 'storeSuggestedPositions'])->name('positions.suggested');
        Route::post('/{catalog}', [OrganizationWebController::class, 'store'])->name('store')->whereIn('catalog', ['zones', 'departments', 'positions']);
        Route::put('/{catalog}/{id}', [OrganizationWebController::class, 'update'])->name('update')->whereIn('catalog', ['zones', 'departments', 'positions'])->whereNumber('id');
        Route::delete('/{catalog}/{id}', [OrganizationWebController::class, 'destroy'])->name('destroy')->whereIn('catalog', ['zones', 'departments', 'positions'])->whereNumber('id');
    });

    // Plataforma (Super Admin): empresas, su primer administrador y marca blanca
    Route::prefix('companies')->name('companies.')->middleware('can:tenants.manage')->group(function () {
        Route::get('/', [CompanyWebController::class, 'index'])->name('index');
        Route::get('/create', [CompanyWebController::class, 'create'])->name('create');
        Route::post('/', [CompanyWebController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [CompanyWebController::class, 'edit'])->name('edit')->whereNumber('id');
        Route::put('/{id}', [CompanyWebController::class, 'update'])->name('update')->whereNumber('id');
    });

    // Bitácora de auditoría
    Route::get('/audit', [AuditLogWebController::class, 'index'])->name('audit.index')->middleware('can:audit.view');
});
