<?php

namespace Tests\Feature;

use App\Models\CompanyConfiguration;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\UniformDelivery;
use App\Models\UniformSize;
use App\Support\Labels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * El diccionario App\Support\Labels cubre todo lo que puede llegar a la UI:
 * si alguien agrega una acción de auditoría o una columna sin etiqueta, falla.
 */
class LabelsCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Tablas cuyos campos pueden aparecer en el cuadro de cambios de la bitácora */
    private const AUDITED_TABLES = [
        'tenant_settings', 'company_configurations', 'branches', 'users', 'employee_profiles',
        'shifts', 'tenants', 'leave_requests', 'payslips', 'attendances',
    ];

    /** Ajustes nuevos (pueden no existir aún como columna en esta rama) */
    private const NEW_SETTINGS = [
        'mock_location_policy', 'max_time_drift_seconds', 'geocoding_provider', 'fcm_enabled', 'fcm_service_account_json',
    ];

    private const LOGGER_METHODS = 'log|logChanges|logModelUpdate|updateWithAudit|saveWithAudit|logCreated';

    public function test_every_audit_action_used_in_the_code_has_a_label(): void
    {
        $found = [];
        foreach ((new Finder())->files()->in(app_path())->name('*.php') as $file) {
            $code = $file->getContents();
            preg_match_all('/->(?:' . self::LOGGER_METHODS . ')\(\s*([\'"])([a-z_]+\.[^\'"]+)\1/', $code, $m);
            foreach ($m[2] as $action) {
                $found[$action] = $file->getRelativePathname();
            }
        }

        $this->assertNotEmpty($found);
        $this->assertArrayHasKey('tenant_settings.updated', $found);

        $missing = [];
        foreach ($found as $action => $file) {
            if (str_contains($action, '{')) {
                // Acción interpolada ("payment_info.{$decision}"): al menos una variante etiquetada
                $regex = '/^' . preg_replace('/\\\\\{.*?\\\\\}/', '[a-z_]+', preg_quote($action, '/')) . '$/';
                if (! preg_grep($regex, array_keys(Labels::ACTIONS))) {
                    $missing[] = "{$action} ({$file})";
                }
            } elseif (! isset(Labels::ACTIONS[$action])) {
                $missing[] = "{$action} ({$file})";
            }
        }

        $this->assertSame([], $missing, 'Acciones de auditoría sin etiqueta en App\Support\Labels::ACTIONS');
    }

    public function test_dynamic_action_variants_are_labeled(): void
    {
        foreach (['clock_in', 'clock_out'] as $moment) {
            $this->assertArrayHasKey("attendance.{$moment}_out_of_geofence", Labels::ACTIONS);
        }
        foreach (['verified', 'rejected'] as $decision) {
            $this->assertArrayHasKey("payment_info.{$decision}", Labels::ACTIONS);
        }
        foreach (['company_configuration.updated' => 'Configuración actualizada', 'tenant_settings.updated' => 'Marca y app actualizada',
            'attendance.clock_in' => 'Marcación de entrada', 'attendance.offline_synced' => 'Marcación sin conexión sincronizada',
            'leave_request.approved' => 'Permiso aprobado', 'auth.login' => 'Inicio de sesión'] as $action => $label) {
            $this->assertSame($label, Labels::action($action));
        }

        foreach (Labels::ACTIONS as $action => [$label, $tone, $icon]) {
            $this->assertContains($tone, ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'], $action);
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $icon, $action);
            $this->assertDoesNotMatchRegularExpression('/[a-z]_[a-z]/', $label, $action);
        }
    }

    public function test_every_column_of_audited_tables_has_a_field_label(): void
    {
        $missing = [];
        foreach (self::AUDITED_TABLES as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if (! Labels::hasField($column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }
        foreach (self::NEW_SETTINGS as $field) {
            if (! Labels::hasField($field)) {
                $missing[] = $field;
            }
        }

        $this->assertSame([], $missing, 'Columnas sin etiqueta en App\Support\Labels::FIELDS');

        foreach (Labels::FIELDS as $field => $label) {
            $this->assertDoesNotMatchRegularExpression('/[a-z]_[a-z]/', $label, $field);
        }
    }

    public function test_enum_sets_cover_the_values_defined_by_the_models(): void
    {
        $sets = [
            'document_status'     => array_keys(EmployeeDocument::STATUSES),
            'document_type'       => array_keys(EmployeeDocument::TYPES),
            'bank_account_type'   => array_keys(EmployeeProfile::BANK_ACCOUNT_TYPES),
            'pension_system'      => array_keys(EmployeeProfile::PENSION_SYSTEMS),
            'health_insurance'    => array_keys(EmployeeProfile::HEALTH_INSURANCES),
            'labor_regime'        => array_keys(EmployeeProfile::LABOR_REGIMES),
            'payment_info_status' => array_keys(EmployeeProfile::PAYMENT_STATUSES),
            'uniform_item'        => array_keys(UniformDelivery::ITEMS),
            'shoe_type'           => array_keys(UniformSize::SHOE_TYPES),
            'geofence_policy'     => array_keys(CompanyConfiguration::GEOFENCE_POLICIES),
            'user_role'           => Role::ALL,
            'attendance_status'   => ['present', 'late', 'absent', 'excused', 'half_day', 'holiday', 'day_off', 'incomplete'],
            'leave_status'        => ['pending', 'approved', 'rejected', 'cancelled'],
            'leave_type'          => ['vacation', 'permission', 'medical_leave'],
            'payslip_status'      => ['draft', 'published', 'archived'],
            'user_status'         => ['active', 'inactive', 'suspended'],
            'tenant_status'       => ['active', 'suspended', 'cancelled'],
            'tenant_plan'         => ['trial', 'basic', 'professional', 'enterprise'],
            'shift_type'          => ['fixed', 'rotating'],
            'justification_status' => ['none', 'pending', 'approved', 'rejected'],
            'notification_type'   => ['leave_request.submitted', 'leave_request.approved', 'leave_request.rejected', 'payslip.published',
                'document.approved', 'document.rejected', 'justification.approved', 'justification.rejected'],
        ];

        foreach ($sets as $set => $values) {
            foreach ($values as $value) {
                $this->assertArrayHasKey((string) $value, Labels::ENUMS[$set] ?? [], "{$set}.{$value}");
            }
        }
    }

    public function test_value_formatting_by_type(): void
    {
        $this->assertSame('Sí', Labels::value('lunch_paid', true));
        $this->assertSame('No', Labels::value('fcm_enabled', false));
        $this->assertSame('Rechazar', Labels::value('geofence_policy', 'reject'));
        $this->assertSame('Registrar como incidencia', Labels::value('geofence_policy', 'flag'));
        $this->assertSame('Registrar como sospechosa', Labels::value('mock_location_policy', 'flag'));
        $this->assertSame('OpenStreetMap (gratis)', Labels::value('geocoding_provider', 'nominatim'));
        $this->assertSame('Google Maps', Labels::value('geocoding_provider', 'google'));
        $this->assertSame('10 min', Labels::value('default_grace_minutes', 10));
        $this->assertSame('150 m', Labels::value('allowed_radius_meters', 150));
        $this->assertSame('30 s', Labels::value('max_time_drift_seconds', 30));
        $this->assertSame('Hereda de la plataforma', Labels::value('lunch_minutes', null, 'TenantSetting', inherits: true));
        $this->assertSame('—', Labels::value('lunch_minutes', null));
        $this->assertSame('••••••••1234', Labels::value('maps_api_key', 'AIzaSy-abcdef1234'));
        $this->assertSame('(cambiado)', Labels::value('fcm_service_account_json', '{"type":"service_account"}'));
        $this->assertSame('Lunes, Martes y Viernes', Labels::value('working_days', [1, 2, 5]));
        $this->assertSame('09/10/2026 07:30', Labels::value('published_at', '2026-10-09T12:30:00+00:00', timezone: 'America/Lima'));
        $this->assertSame('Aprobada', Labels::value('status', 'approved', 'LeaveRequest'));
        $this->assertSame('Publicada', Labels::value('status', 'published', 'Payslip'));
        $this->assertSame('Activa', Labels::enum('branch_status', true));
        $this->assertStringContainsString('#1E40AF', (string) Labels::value('primary_color', '#1e40af'));
        // Un arreglo nunca sale como JSON
        $this->assertStringNotContainsString('{', Labels::value('breakdown', ['a' => 1]));
        $this->assertStringNotContainsString('{', Labels::value('notes', ['foo_bar' => 'x']));
    }

    public function test_blade_helpers_and_status_badge_component(): void
    {
        $this->assertSame('Aprobada', label('leave_status', 'approved'));
        $this->assertSame('Tolerancia por defecto (min)', field_label('default_grace_minutes'));
        $this->assertSame('••••••••7890', masked('whsec_1234567890'));

        $html = Blade::render('<x-status-badge type="leave_status" :value="$v" />', ['v' => 'rejected']);
        $this->assertStringContainsString('Rechazada', $html);
        $this->assertStringContainsString('bg-danger-subtle', $html);

        $this->assertSame('Pendiente', trim(Blade::render("@label('leave_status', \$v)", ['v' => 'pending'])));
        $this->assertSame('Almuerzo pagado', trim(Blade::render("@fieldLabel('lunch_paid')")));
    }
}
