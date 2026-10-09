<?php

/**
 * =============================================================================
 * routes/api.php — Rutas API del sistema TuneSA
 *
 * Arquitectura de middlewares por grupo:
 *
 *  [tenant]                 ResolveTenant → identifica la empresa (X-Tenant-Slug,
 *                           subdominio o dominio propio) y activa los global scopes
 *  [tenant+auth]            + Sanctum Auth + EnforceTenantScope
 *  [... + permission:x]     + CheckPermission (matriz sembrada por RolesAndPermissionsSeeder)
 *  [superadmin]             Sanctum Auth + role:super_admin, SIN tenant (cross-tenant)
 * =============================================================================
 */

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\LeaveRequestController;
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
        Route::post('register',        [AuthController::class, 'register'])->middleware('throttle:5,1');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('reset-password',  [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    });

    // Marca blanca (la app la pide antes del login)
    Route::get('tenant/branding', [TenantController::class, 'branding']);
});

// =============================================================================
// RUTAS PROTEGIDAS (Tenant + Auth + TenantScope)
// =============================================================================
Route::middleware(['tenant', 'auth:sanctum', 'tenant.scope'])->group(function () {

    // --- AUTH ---
    Route::prefix('auth')->group(function () {
        Route::post('logout',         [AuthController::class, 'logout']);
        Route::get('me',              [AuthController::class, 'me']);
        Route::put('profile',         [AuthController::class, 'updateProfile']);
        Route::put('change-password', [AuthController::class, 'changePassword']);
        Route::post('refresh-fcm',    [AuthController::class, 'updateFcmToken']);
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
