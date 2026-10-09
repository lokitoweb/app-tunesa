<?php

/**
 * =============================================================================
 * routes/api.php — Rutas API del sistema TuneSA
 *
 * Arquitectura de middlewares por grupo:
 *
 *  [tenant]                 ResolveTenant → identifica la empresa (X-Tenant-Slug,
 *                           subdominio o dominio propio) y activa los global scopes
 *  [tenant+auth]            + Sanctum Auth + EnforceTenantScope + password.fresh
 *                           (contraseña temporal → solo /auth/me y change-password)
 *  [... + permission:x]     + CheckPermission (matriz sembrada por RolesAndPermissionsSeeder)
 *  [superadmin]             Sanctum Auth + role:super_admin, SIN tenant (cross-tenant)
 * =============================================================================
 */

use App\Http\Controllers\Api\Admin\AttendanceDetailController;
use App\Http\Controllers\Api\Admin\CompanyConfigurationController;
use App\Http\Controllers\Api\Admin\DocumentReviewController;
use App\Http\Controllers\Api\Admin\EmployeeProfileController;
use App\Http\Controllers\Api\Admin\PayrollController;
use App\Http\Controllers\Api\Admin\UniformAdminController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\Employee\DocumentController;
use App\Http\Controllers\Api\Employee\ProfileController;
use App\Http\Controllers\Api\Employee\UniformController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PayslipController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// =============================================================================
// RUTAS PÚBLICAS CON TENANT (requieren identificar la empresa)
// =============================================================================
Route::middleware(['tenant'])->group(function () {

    // Autenticación
    Route::prefix('auth')->group(function () {
        Route::post('login',           [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('reset-password',  [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    });

    // Marca blanca (la app la pide antes del login)
    Route::get('tenant/branding', [TenantController::class, 'branding']);
});

// =============================================================================
// RUTAS PROTEGIDAS (Tenant + Auth + TenantScope)
// =============================================================================
Route::middleware(['tenant', 'auth:sanctum', 'tenant.scope', 'password.fresh'])->group(function () {

    // --- AUTH ---
    Route::prefix('auth')->group(function () {
        Route::post('logout',         [AuthController::class, 'logout']);
        Route::post('logout-all',     [AuthController::class, 'logoutAll']);
        Route::get('sessions',        [AuthController::class, 'sessions']);
        Route::delete('sessions/{id}', [AuthController::class, 'revokeSession'])->whereNumber('id');
        Route::get('me',              [AuthController::class, 'me']);
        Route::put('profile',         [AuthController::class, 'updateProfile']);
        Route::put('change-password', [AuthController::class, 'changePassword']);
        Route::post('refresh-fcm',    [AuthController::class, 'updateFcmToken']);
    });

    // --- NOTIFICACIONES IN-APP (cualquier rol) ---
    Route::prefix('notifications')->group(function () {
        Route::get('/',          [NotificationController::class, 'index']);
        Route::post('read-all',  [NotificationController::class, 'readAll']);
        Route::post('{id}/read', [NotificationController::class, 'read'])->whereUuid('id');
    });

    // --- ASISTENCIAS (marcación con selfie + GPS) ---
    Route::prefix('attendance')->group(function () {
        Route::middleware('permission:attendance.view_own')->group(function () {
            Route::get('today',   [AttendanceController::class, 'today']);
            Route::get('history', [AttendanceController::class, 'history']);
            Route::post('{id}/justification', [AttendanceController::class, 'submitJustification'])->whereNumber('id');
        });

        Route::middleware('permission:attendance.mark')->group(function () {
            Route::post('clock-in',    [AttendanceController::class, 'clockIn']);
            Route::post('break-start', [AttendanceController::class, 'breakStart']);
            Route::post('break-end',   [AttendanceController::class, 'breakEnd']);
            Route::post('clock-out',   [AttendanceController::class, 'clockOut']);
        });
    });

    // --- BOLETAS DE PAGO (empleado ve y descarga las propias) ---
    Route::middleware('permission:payslips.view_own,payslips.manage')->group(function () {
        Route::get('payslips',               [PayslipController::class, 'index']);
        Route::get('payslips/{id}',          [PayslipController::class, 'show'])->whereNumber('id');
        Route::get('payslips/{id}/download', [PayslipController::class, 'download'])->whereNumber('id');
        Route::post('payslips/{id}/read',    [PayslipController::class, 'markAsRead'])->whereNumber('id');
    });

    // --- TURNOS DEL EMPLEADO ---
    Route::middleware('permission:shifts.view_own')->group(function () {
        Route::get('my-shifts', [ShiftController::class, 'myShifts']);
        Route::get('schedule',  [ShiftController::class, 'schedule']);  // Vista de calendario
    });

    // --- SUCURSALES (para la app móvil, ver sucursales asignadas) ---
    Route::get('my-branches', [BranchController::class, 'myBranches']);

    // --- AUTOSERVICIO: onboarding, legajo, datos de pago y dotación ---
    Route::middleware('permission:profile.manage_own')->prefix('employee')->group(function () {
        Route::get('config',          [ProfileController::class, 'config']);
        Route::get('profile',         [ProfileController::class, 'show']);
        Route::put('payment-info',    [ProfileController::class, 'updatePaymentInfo']);
        Route::put('contact',         [ProfileController::class, 'updateContact']);

        Route::get('documents',              [DocumentController::class, 'index']);
        Route::post('documents',             [DocumentController::class, 'store'])->middleware('throttle:20,1');
        Route::get('documents/{id}/url',     [DocumentController::class, 'url'])->whereNumber('id');
        Route::delete('documents/{id}',      [DocumentController::class, 'destroy'])->whereNumber('id');

        Route::get('uniform-sizes',   [UniformController::class, 'show']);
        Route::put('uniform-sizes',   [UniformController::class, 'update']);
    });

    // --- SOLICITUDES: vacaciones, permisos, licencias médicas ---
    Route::middleware('permission:leave.request')->prefix('leave-requests')->group(function () {
        Route::get('/',            [LeaveRequestController::class, 'index']);
        Route::post('/',           [LeaveRequestController::class, 'store']);
        Route::get('{id}',         [LeaveRequestController::class, 'show'])->whereNumber('id');
        Route::post('{id}/cancel', [LeaveRequestController::class, 'cancel'])->whereNumber('id');
    });

    // --- APROBACIÓN DE SOLICITUDES (supervisor: su equipo; RRHH/Admin: todas) ---
    Route::middleware('permission:leave.approve_team,leave.approve_all')->prefix('approvals/leave-requests')->group(function () {
        Route::get('/',             [LeaveRequestController::class, 'pendingApprovals']);
        Route::post('{id}/approve', [LeaveRequestController::class, 'approve'])->whereNumber('id');
        Route::post('{id}/reject',  [LeaveRequestController::class, 'reject'])->whereNumber('id');
    });

    // =========================================================================
    // RUTAS DE ADMINISTRACIÓN (Company Admin + HR Manager, por permiso)
    // =========================================================================
    Route::prefix('admin')->group(function () {

        // Gestión de empleados
        Route::middleware('permission:users.manage')->group(function () {
            Route::apiResource('employees', UserController::class);
            Route::post('employees/{id}/assign-branch', [UserController::class, 'assignBranch']);
        });
        Route::post('employees/{id}/assign-shift', [UserController::class, 'assignShift'])
            ->middleware('permission:shifts.assign');

        // Gestión de sucursales y geocercas
        Route::apiResource('branches', BranchController::class)->middleware('permission:branches.manage');

        // Gestión de turnos (fijos y rotativos)
        Route::apiResource('shifts', ShiftController::class)->middleware('permission:shifts.manage');

        // Dashboard en tiempo real
        Route::middleware('permission:attendance.view_all')->group(function () {
            Route::get('dashboard/live',  [AttendanceController::class, 'liveDashboard']);
            Route::get('dashboard/stats', [AttendanceController::class, 'dashboardStats']);
            Route::get('dashboard/map',   [BranchController::class, 'mapData']);
        });

        // Reporte de asistencias
        Route::get('reports/attendance', [AttendanceController::class, 'report'])
            ->middleware('permission:reports.view');
        Route::get('reports/attendance/export', [AttendanceController::class, 'exportReport'])
            ->middleware('permission:reports.export');

        // Justificaciones
        Route::middleware('permission:attendance.review')->group(function () {
            Route::post('attendance/{id}/approve-justification', [AttendanceController::class, 'approveJustification']);
            Route::post('attendance/{id}/reject-justification',  [AttendanceController::class, 'rejectJustification']);
        });

        // Nómina / Boletas
        Route::middleware('permission:payslips.manage')->group(function () {
            Route::post('payslips/bulk', [PayslipController::class, 'bulkStore']);
            Route::apiResource('payslips', PayslipController::class)->except(['index', 'show']);
            Route::post('payslips/{id}/publish',     [PayslipController::class, 'publish']);
            Route::get('payslips/employee/{userId}', [PayslipController::class, 'byEmployee']);
        });

        // Marca blanca de la empresa
        Route::put('branding', [TenantController::class, 'updateBranding'])
            ->middleware('permission:tenants.branding');

        // Roles y permisos
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');

        // Visor de marcaciones (selfie, hora, sucursal, geocerca)
        Route::get('attendance/{id}', [AttendanceDetailController::class, 'show'])
            ->whereNumber('id')->middleware('permission:attendance.view_all');

        // Legajo digital: revisión de documentos
        Route::middleware('permission:documents.review')->group(function () {
            Route::get('documents',                  [DocumentReviewController::class, 'index']);
            Route::get('documents/{id}/url',         [DocumentReviewController::class, 'url'])->whereNumber('id');
            Route::post('documents/{id}/approve',    [DocumentReviewController::class, 'approve'])->whereNumber('id');
            Route::post('documents/{id}/reject',     [DocumentReviewController::class, 'reject'])->whereNumber('id');
            Route::post('employees/{id}/documents',  [DocumentReviewController::class, 'storeFor'])->whereNumber('id');
        });

        // Ficha laboral (régimen, CTS, verificación de cuenta)
        Route::middleware('permission:profiles.manage')->group(function () {
            Route::get('employees/{id}/profile',               [EmployeeProfileController::class, 'show'])->whereNumber('id');
            Route::put('employees/{id}/profile',               [EmployeeProfileController::class, 'update'])->whereNumber('id');
            Route::post('employees/{id}/payment-info/review',  [EmployeeProfileController::class, 'reviewPayment'])->whereNumber('id');
        });

        // Dotación de uniformes
        Route::middleware('permission:uniforms.manage')->group(function () {
            Route::get('uniforms',                       [UniformAdminController::class, 'index']);
            Route::get('uniforms/export',                [UniformAdminController::class, 'export']);
            Route::post('uniforms/deliveries',           [UniformAdminController::class, 'storeDelivery']);
            Route::put('employees/{id}/uniform-sizes',   [UniformAdminController::class, 'updateSizes'])->whereNumber('id');
        });

        // Cruce con planilla / nómina
        Route::get('payroll/export', [PayrollController::class, 'export'])
            ->middleware(['permission:payroll.export', 'throttle:10,1']);

        // Qué ve y edita el colaborador en la app
        Route::middleware('permission:company.configure')->group(function () {
            Route::get('company-configuration', [CompanyConfigurationController::class, 'show']);
            Route::put('company-configuration', [CompanyConfigurationController::class, 'update']);
        });
    });

    // =========================================================================
    // RUTAS DE SUPERVISOR (solo su equipo)
    // =========================================================================
    Route::middleware('permission:attendance.view_team')->prefix('supervisor')->group(function () {
        Route::get('team',            [UserController::class, 'myTeam']);
        Route::get('attendance/live', [AttendanceController::class, 'teamLive']);
        Route::get('attendance',      [AttendanceController::class, 'teamReport']);
    });
});

// =============================================================================
// RUTAS DE SUPER ADMIN (Cross-Tenant, sin middleware [tenant])
// =============================================================================
Route::prefix('superadmin')->group(function () {
    Route::post('auth/login', [AuthController::class, 'superAdminLogin'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
        Route::apiResource('tenants', TenantController::class);
        Route::post('tenants/{id}/suspend',  [TenantController::class, 'suspend']);
        Route::post('tenants/{id}/activate', [TenantController::class, 'activate']);
    });
});
