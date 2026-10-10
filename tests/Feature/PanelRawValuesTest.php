<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * El panel nunca muestra claves técnicas (snake_case, enums crudos, JSON):
 * los valores pasan por App\Support\Labels (label(), <x-status-badge>).
 */
class PanelRawValuesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Impresiones de valores crudos que deben ir por label() / <x-status-badge> */
    private const RAW_PATTERNS = [
        '/\{\{\s*\$[\w>\-?]+->(status|role|type|action|plan|item|document_type|gender|contract_type|justification_status|geofence_policy|mock_location_policy|geocoding_provider)\s*\}\}/',
        '/\?\?\s*\$[\w>\-?]+->(status|type|role|plan|item|action)\s*\}\}/',
        "/str_replace\\(\\s*'_'/",
        '/ucfirst\(\(string\)\s*\$/',
        '/json_encode\(/',
        '/@json\(\$[\w>\-]+->meta/',
    ];

    /** Textos de relleno prohibidos por producto */
    private const FORBIDDEN_COPY = '/no se usa|próximamente|proximamente/iu';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_views_do_not_print_raw_enum_values_or_json(): void
    {
        $offenders = [];
        foreach ((new Finder())->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            $code = $file->getContents();
            foreach (self::RAW_PATTERNS as $pattern) {
                if (preg_match($pattern, $code, $m)) {
                    $offenders[] = $file->getRelativePathname() . ': ' . $m[0];
                }
            }
            if (preg_match(self::FORBIDDEN_COPY, $code, $m)) {
                $offenders[] = $file->getRelativePathname() . ': ' . $m[0];
            }
        }

        $this->assertSame([], $offenders, 'Usa label()/<x-status-badge> (App\Support\Labels) en lugar de valores crudos');
    }

    public function test_employee_list_shows_role_status_and_document_labels(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $this->makeUser($acme, 'hr_manager', ['first_name' => 'Hilda', 'status' => 'suspended', 'document_type' => 'ce', 'document_number' => '001234567']);

        $this->actingAs($admin)->get('/employees')->assertOk()
            ->assertSee('RRHH')->assertSee('Suspendido')->assertSee('Carné de extranjería')
            ->assertDontSeeText('hr_manager')->assertDontSeeText('suspended')->assertDontSeeText('company_admin');
    }

    public function test_companies_and_approvals_show_labels(): void
    {
        $acme  = $this->makeTenant('acme', ['plan' => 'professional', 'status' => 'suspended']);
        $root  = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->get('/companies')->assertOk()
            ->assertSee('Profesional')->assertSee('Suspendida')
            ->assertDontSeeText('professional')->assertDontSeeText('super_admin');

        $hr  = $this->makeUser($acme, 'hr_manager');
        $acme->update(['status' => 'active']);
        $emp = $this->makeUser($acme, 'employee', ['first_name' => 'Ana']);
        LeaveRequest::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'type' => 'medical_leave',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-02', 'days' => 2, 'reason' => 'Gripe', 'status' => 'cancelled']);

        $this->actingAs($hr)->get('/approvals?tab=leave&status=cancelled')->assertOk()
            ->assertSee('Licencia médica')->assertSee('Cancelada')
            ->assertDontSeeText('medical_leave');
    }
}
