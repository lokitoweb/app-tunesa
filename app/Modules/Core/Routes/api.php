<?php

/**
 * =============================================================================
 * API de TuneSA — convenciones comunes a los Routes/api.php de cada módulo
 *
 *  [tenant]                 ResolveTenant → identifica la empresa (X-Tenant-Slug,
 *                           subdominio o dominio propio) y activa los global scopes
 *  [api.tenant]             tenant + app.version + Sanctum + EnforceTenantScope + password.fresh
 *                           (contraseña temporal → solo /auth/me y change-password).
 *                           Grupo definido en bootstrap/app.php.
 *  [... + permission:x]     + CheckPermission (matriz sembrada por RolesAndPermissionsSeeder)
 *  [superadmin]             Sanctum Auth + role:super_admin, SIN tenant (cross-tenant)
 *  app.version              426 APP_UPDATE_REQUIRED si X-App-Version < mínima (force_update)
 *
 * Nota: esta API no usa prefijo /v1, salvo algunos alias (/api/v1/app/config...).
 * =============================================================================
 */

use App\Modules\Core\Controllers\Api\Admin\CompanyConfigurationController;
use App\Modules\Core\Controllers\Api\AccountDeletionController;
use App\Modules\Core\Controllers\Api\AppConfigController;
use App\Modules\Core\Controllers\Api\AuthController;
use App\Modules\Core\Controllers\Api\BranchController;
use App\Modules\Core\Controllers\Api\PositionController;
use App\Modules\Core\Controllers\Api\NotificationController;
use App\Modules\Core\Controllers\Api\RoleController;
use App\Modules\Core\Controllers\Api\TenantController;
use Illuminate\Support\Facades\Route;

// Configuración pública de la app (antes del login): marca, versión mínima, reglas
Route::middleware('throttle:60,1')->group(function () {
    Route::get('v1/app/config', [AppConfigController::class, 'show']);
    Route::get('app/config',    [AppConfigController::class, 'show']);
});

// Acceso de la app sin código de empresa: el servidor la reconoce por el correo
Route::prefix('v1/auth')->group(function () {
    Route::post('login',           [AuthController::class, 'loginByEmail'])->middleware('throttle:10,1');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
});

Route::middleware(['tenant'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login',           [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('reset-password',  [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    });
    Route::get('tenant/branding', [TenantController::class, 'branding']);
});

Route::middleware('api.tenant')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout',          [AuthController::class, 'logout']);
        Route::post('logout-all',      [AuthController::class, 'logoutAll']);
        Route::post('logout-others',   [AuthController::class, 'logoutOthers']);
        Route::get('notification-preferences', [AuthController::class, 'notificationPreferences']);
        Route::put('notification-preferences', [AuthController::class, 'updateNotificationPreferences']);
        Route::get('sessions',         [AuthController::class, 'sessions']);
        Route::delete('sessions/{id}', [AuthController::class, 'revokeSession'])->whereNumber('id');
        Route::get('me',               [AuthController::class, 'me']);
        Route::put('profile',          [AuthController::class, 'updateProfile']);
        Route::post('profile',         [AuthController::class, 'updateProfile']); // multipart (foto de perfil)
        Route::put('change-password',  [AuthController::class, 'changePassword']);
        Route::post('refresh-fcm',     [AuthController::class, 'updateFcmToken']);
        Route::post('account-deletion', [AccountDeletionController::class, 'store'])->middleware('throttle:5,1');
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/',          [NotificationController::class, 'index']);
        Route::post('read-all',  [NotificationController::class, 'readAll']);
        Route::post('{id}/read', [NotificationController::class, 'read'])->whereUuid('id');
    });

    Route::get('my-branches', [BranchController::class, 'myBranches']);

    Route::prefix('admin')->group(function () {
        Route::apiResource('branches', BranchController::class)->middleware('permission:branches.manage');
        Route::get('positions', [PositionController::class, 'index'])->middleware('permission:users.manage,branches.manage');
        Route::get('dashboard/map', [BranchController::class, 'mapData'])->middleware('permission:attendance.view_all');
        Route::put('branding', [TenantController::class, 'updateBranding'])->middleware('permission:tenants.branding');
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');

        Route::middleware('permission:company.configure')->group(function () {
            Route::get('company-configuration', [CompanyConfigurationController::class, 'show']);
            Route::put('company-configuration', [CompanyConfigurationController::class, 'update']);
            Route::get('app-config',                    [AppConfigController::class, 'adminShow']);
            Route::match(['put', 'post'], 'app-config', [AppConfigController::class, 'adminUpdate']);
        });
    });
});

// Super Admin: sin empresa (cross-tenant)
Route::prefix('superadmin')->group(function () {
    Route::post('auth/login', [AuthController::class, 'superAdminLogin'])->middleware('throttle:5,1');
    Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
        Route::apiResource('tenants', TenantController::class);
        Route::post('tenants/{id}/suspend',  [TenantController::class, 'suspend']);
        Route::post('tenants/{id}/activate', [TenantController::class, 'activate']);
    });
});
