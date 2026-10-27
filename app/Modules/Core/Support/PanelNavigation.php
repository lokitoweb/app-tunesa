<?php

namespace App\Modules\Core\Support;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\Ethics\Models\EthicsCase;
use App\Modules\Onboarding\Models\OnboardingProcess;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\ModuleRegistry;
use Illuminate\Http\Request;

/**
 * Menú lateral del panel: una sola definición para el layout Blade
 * (layouts/app.blade.php) y el layout Vue (Core/Layouts/AppLayout.vue).
 *
 * Cada entrada: módulo dueño, permiso(s) que la muestran (cualquiera basta),
 * ruta y patrones de ruta que la marcan activa. 'spa' = pantalla ya migrada a
 * Inertia (el layout Vue navega sin recargar).
 */
class PanelNavigation
{
    /** @return list<array{heading: string, items: list<array<string, mixed>>}> */
    public static function for(User $user, Request $request): array
    {
        $tenant    = app(TenantContext::class)->getOrNull();
        $hasTenant = $tenant !== null;

        $sections = [
            'Plataforma' => [
                ['Core', $user->isSuperAdmin(), 'companies.index', [], 'buildings', 'Empresas', $request->routeIs('companies*')],
            ],
            'Monitoreo' => [
                ['Core', true, 'dashboard', [], 'speedometer2', 'Dashboard', $request->routeIs('dashboard*')],
                ['Attendance', ['attendance.view_team', 'attendance.view_all'], 'live.index', [], 'broadcast', 'Tablero en vivo', $request->routeIs('live.*')],
                ['Attendance', 'attendance.view_all', 'attendance.index', [], 'clock-history', 'Marcaciones y GPS', $request->routeIs('attendance.index', 'attendance.show')],
                ['Attendance', ['attendance.view_team', 'attendance.view_all'], 'attendance.calendar', [], 'calendar3', 'Calendario', $request->routeIs('attendance.calendar*')],
                ['Attendance', 'attendance.view_all', 'attendance.attempts', [], 'slash-circle', 'Intentos rechazados', $request->routeIs('attendance.attempts')],
                ['Attendance', ['attendance.view_team', 'attendance.view_all'], 'attendance.absences', [], 'person-x', 'Control de faltas', $request->routeIs('attendance.absences')],
                ['Attendance', 'attendance.authorize_offshift', 'attendance.offshift.index', [], 'calendar-plus', 'Marcación fuera de turno', $request->routeIs('attendance.offshift.*')],
                ['HR', 'leave.approve_team', 'approvals.index', [], 'check2-square', 'Aprobaciones', $request->routeIs('approvals*'), fn () => $hasTenant ? self::pendingApprovals($user) : 0],
            ],
            'Administración' => [
                ['HR', 'users.manage', 'employees.index', [], 'people', 'Colaboradores', $request->routeIs('employees.index', 'employees.create', 'employees.edit')],
                ['Onboarding', 'onboarding.review', 'onboarding.index', [], 'person-badge', 'Incorporación', $request->routeIs('onboarding.*'), fn () => $hasTenant ? OnboardingProcess::where('status', OnboardingProcess::SUBMITTED)->count() : 0],
                ['Ethics', 'ethics.investigate', 'ethics.index', [], 'shield-exclamation', 'Canal ético', $request->routeIs('ethics.*'), fn () => $hasTenant ? EthicsCase::where('investigator_unread', true)->count() : 0],
                ['HR', 'shifts.manage', 'shifts.index', [], 'calendar-week', 'Horarios', $request->routeIs('shifts*') && ! $request->routeIs('shifts.schedule*'), null, true],
                ['HR', 'shifts.program', 'shifts.schedule', [], 'calendar3', 'Programación de horarios', $request->routeIs('shifts.schedule*')],
                ['HR', 'shifts.manage', 'holidays.index', [], 'calendar-heart', 'Feriados', $request->routeIs('holidays*')],
                ['Core', 'branches.manage', 'organization.index', [], 'diagram-3', 'Organización', $request->routeIs('organization*'), null, true],
                ['Core', 'branches.manage', 'branches.index', [], 'pin-map', 'Sucursales y geocercas', $request->routeIs('branches*')],
                ['Payroll', 'payslips.manage', 'payslips.index', [], 'file-earmark-text', 'Boletas de pago', $request->routeIs('payslips*')],
                ['Training', 'training.manage', 'training.index', [], 'mortarboard', 'Capacitaciones', $request->routeIs('training.*'), null, true],
            ],
            'Comunidad' => [
                ['Announcements', 'announcements.manage', 'announcements.index', [], 'megaphone', 'Comunicados', $request->routeIs('announcements.*'), null, true],
                ['Benefits', 'benefits.manage', 'benefits.index', [], 'gift', 'Beneficios', $request->routeIs('benefits.*'), null, true],
                ['Birthdays', 'birthdays.manage', 'birthdays.index', [], 'balloon-heart', 'Cumpleaños', $request->routeIs('birthdays.*'), null, true],
                ['CompanyDocs', 'company_docs.manage', 'company-docs.index', [], 'folder2-open', 'Documentos de la empresa', $request->routeIs('company-docs.*'), null, true],
            ],
            'Compras y tesorería' => [
                ['Finance', 'requisitions.create', 'requisitions.index', [], 'cart3', 'Requerimientos y compras', $request->routeIs('requisitions.*'),
                    fn () => $hasTenant && $user->hasPermission('requisitions.approve') ? \App\Modules\Finance\Models\Requisition::where('stage', 'requested')->count() : 0],
                ['Finance', 'treasury.view', 'treasury.index', [], 'bank', 'Tesorería y pagos', $request->routeIs('treasury.*'),
                    fn () => $hasTenant && \App\Modules\Finance\Models\Signatory::canAuthorize($user) ? \App\Modules\Finance\Models\PaymentInstallment::where('status', 'pending')->whereNotNull('scheduled_for')->whereNull('authorized_at')->count() : 0],
                ['Finance', 'treasury.view', 'cash-flow.index', [], 'graph-down-arrow', 'Flujo de caja', $request->routeIs('cash-flow.*')],
                ['Finance', 'fixed_costs.manage', 'fixed-costs.index', [], 'calendar2-range', 'Costos fijos', $request->routeIs('fixed-costs.*')],
                ['Finance', 'fixed_costs.manage', 'petty-cash.index', [], 'wallet2', 'Caja chica', $request->routeIs('petty-cash.*')],
                ['Finance', 'suppliers.view', 'suppliers.index', [], 'truck', 'Proveedores', $request->routeIs('suppliers.*')],
                ['Finance', 'payroll.payments', 'payroll-payments.index', [], 'people', 'Pagos de RRHH', $request->routeIs('payroll-payments.*')],
                ['Finance', 'suppliers.view', 'purchases.by-product', [], 'search', 'Compras por producto', $request->routeIs('purchases.*')],
                ['Finance', 'purchasing.manage', 'products.index', [], 'box', 'Productos', $request->routeIs('products.*')],
                ['Finance', 'inventory.manage', 'inventory.index', [], 'box-seam', 'Almacén y kardex', $request->routeIs('inventory.*'),
                    fn () => $hasTenant ? \App\Modules\Finance\Models\Requisition::where('stage', 'ordered')->whereNotNull('purchase_order_number')->whereNull('received_at')->count() : 0],
                ['Finance', 'fuel.manage', 'fuel.index', [], 'fuel-pump', 'Combustible', $request->routeIs('fuel.*')],
                ['Finance', 'fleet.manage', 'vehicles.index', [], 'bus-front', 'Vehículos', $request->routeIs('vehicles.*')],
                ['Finance', 'signatories.manage', 'signatories.index', [], 'pen', 'Apoderados y firmas', $request->routeIs('signatories.*')],
            ],
            'Legajo y planilla' => [
                ['HR', 'documents.review', 'documents.index', [], 'folder-check', 'Legajos digitales', $request->routeIs('documents*', 'employees.file*'), fn () => $hasTenant ? EmployeeDocument::where('status', 'pending')->count() : 0],
                ['HR', 'uniforms.manage', 'uniforms.index', [], 'bag', 'Dotación y tallas', $request->routeIs('uniforms*')],
                ['Payroll', 'payroll.export', 'payroll.index', [], 'cash-stack', 'Cruce con planilla', $request->routeIs('payroll*')],
                ['Attendance', 'reports.view', 'reports.index', [], 'bar-chart-line', 'Reportes', $request->routeIs('reports*')],
                ['Core', 'company.configure', 'settings.mobile', [], 'phone', 'Configuración de la app', $request->routeIs('settings.mobile*')],
                ['Core', 'company.configure', 'settings.branding', [], 'palette', 'Marca y app', $request->routeIs('settings.branding', 'settings.branding.update')],
                ['Integrations', 'company.configure', 'settings.integrations', [], 'plug', 'Integraciones', $request->routeIs('settings.integrations*'), null, true],
                ['Core', 'audit.view', 'audit.index', [], 'journal-text', 'Bitácora de auditoría', $request->routeIs('audit*'), null, true],
            ],
        ];

        $menu = [];
        foreach ($sections as $heading => $entries) {
            $items = [];
            foreach ($entries as $entry) {
                [$module, $permission, $route, $params, $icon, $label, $active] = $entry;
                $allowed = is_bool($permission)
                    ? $permission
                    : collect((array) $permission)->contains(fn ($p) => $user->can($p));

                if (! $allowed || ! ModuleRegistry::enabledFor($tenant, $module)) {
                    continue;
                }

                $items[] = [
                    'label'  => $label,
                    'icon'   => $icon,
                    'href'   => route($route, $params),
                    'active' => $active,
                    'badge'  => isset($entry[7]) ? (int) $entry[7]() : 0,
                    'spa'    => $entry[8] ?? false,
                ];
            }
            if ($items) {
                $menu[] = ['heading' => $heading, 'items' => $items];
            }
        }

        return $menu;
    }

    /** Solicitudes (y justificaciones) pendientes que este usuario puede resolver */
    private static function pendingApprovals(User $user): int
    {
        $overtime = app(\App\Modules\Attendance\Services\OvertimeApproval::class)->pendingCount($user);

        if ($user->can('leave.approve_all')) {
            return LeaveRequest::where('status', 'pending')->count() + $overtime
                + ($user->can('attendance.review') ? Attendance::where('justification_status', 'pending')->count() : 0);
        }

        return $overtime + LeaveRequest::approvableBy($user)->where('status', 'pending')->count();
    }
}
