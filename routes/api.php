<?php

/**
 * =============================================================================
 * routes/api.php — Rutas API del sistema TuneSA
 *
 * Arquitectura de middlewares por grupo:
 *
 *  [Public]          Sin autenticación (login, register)
 *  [tenant]          ResolveTenant → identifica la empresa
 *  [tenant+auth]     ResolveTenant → Sanctum Auth → EnforceTenantScope
 *  [tenant+auth+role] + CheckRole para rutas de admin/RRHH
 * =============================================================================
 */

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\PayslipController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// =============================================================================
// RUTAS PÚBLICAS CON TENANT (requieren identificar la empresa)
// =============================================================================
Route::middleware(['tenant'])->group(function () {

    // Autenticación
    Route::prefix('auth')->group(function () {
        Route::post('login',    [AuthController::class, 'login']);
        Route::post('register', [AuthController::class, 'register']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    });
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

    // --- ASISTENCIAS (todos los empleados autenticados) ---
    Route::prefix('attendance')->group(function () {
        Route::get('today',      [AttendanceController::class, 'today']);
        Route::get('history',    [AttendanceController::class, 'history']);
        Route::post('clock-in',  [AttendanceController::class, 'clockIn']);
        Route::post('break-start', [AttendanceController::class, 'breakStart']);
        Route::post('break-end',   [AttendanceController::class, 'breakEnd']);
        Route::post('clock-out', [AttendanceController::class, 'clockOut']);
    });

    // --- BOLETAS DE PAGO (empleado ve las propias) ---
    Route::get('payslips',         [PayslipController::class, 'index']);
    Route::get('payslips/{id}',    [PayslipController::class, 'show']);
    Route::post('payslips/{id}/read', [PayslipController::class, 'markAsRead']);

    // --- TURNOS DEL EMPLEADO ---
    Route::get('my-shifts',  [ShiftController::class, 'myShifts']);
    Route::get('schedule',   [ShiftController::class, 'schedule']);  // Vista de calendario

    // --- SUCURSALES (para la app móvil, ver sucursales asignadas) ---
    Route::get('my-branches', [BranchController::class, 'myBranches']);

    // =========================================================================
    // RUTAS DE ADMINISTRACIÓN (Company Admin + HR Manager)
    // =========================================================================
    Route::middleware(['role:company_admin,hr_manager'])->prefix('admin')->group(function () {

        // Gestión de empleados
        Route::apiResource('employees', UserController::class);
        Route::post('employees/{id}/assign-branch', [UserController::class, 'assignBranch']);
        Route::post('employees/{id}/assign-shift',  [UserController::class, 'assignShift']);

        // Gestión de sucursales
        Route::apiResource('branches', BranchController::class);

        // Gestión de turnos
        Route::apiResource('shifts', ShiftController::class);

        // Dashboard en tiempo real
        Route::get('dashboard/live',      [AttendanceController::class, 'liveDashboard']);
        Route::get('dashboard/stats',     [AttendanceController::class, 'dashboardStats']);
        Route::get('dashboard/map',       [BranchController::class, 'mapData']);

        // Reporte de asistencias
        Route::get('reports/attendance',  [AttendanceController::class, 'report']);
        Route::get('reports/attendance/export', [AttendanceController::class, 'exportReport']);

        // Justificaciones
        Route::post('attendance/{id}/approve-justification', [AttendanceController::class, 'approveJustification']);
        Route::post('attendance/{id}/reject-justification',  [AttendanceController::class, 'rejectJustification']);

        // Nómina / Boletas
        Route::apiResource('payslips', PayslipController::class)->except(['index', 'show']);
        Route::post('payslips/{id}/publish', [PayslipController::class, 'publish']);
        Route::get('payslips/employee/{userId}', [PayslipController::class, 'byEmployee']);
    });

    // =========================================================================
    // RUTAS DE SUPERVISOR (solo su área)
    // =========================================================================
    Route::middleware(['role:supervisor'])->prefix('supervisor')->group(function () {
        Route::get('team',            [UserController::class, 'myTeam']);
        Route::get('attendance/live', [AttendanceController::class, 'teamLive']);
        Route::get('attendance',      [AttendanceController::class, 'teamReport']);
    });

    // =========================================================================
    // RUTAS DE SUPER ADMIN (Cross-Tenant)
    // =========================================================================
    Route::middleware(['role:super_admin'])->prefix('superadmin')->group(function () {
        Route::apiResource('tenants', \App\Http\Controllers\Api\TenantController::class);
        Route::post('tenants/{id}/suspend', [\App\Http\Controllers\Api\TenantController::class, 'suspend']);
        Route::post('tenants/{id}/activate', [\App\Http\Controllers\Api\TenantController::class, 'activate']);
    });
});
