<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Documentos obligatorios: los de toda la empresa + los que pide el cargo */
class PositionRequiredDocumentsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_each_person_needs_the_company_documents_plus_those_of_their_position(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        // El examen médico es para todo el personal: va en la lista de la empresa
        CompanyConfiguration::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $acme->id], ['required_documents' => ['dni', 'medical_exam']]);

        $this->actingAs($admin)->post('/organization/positions', [
            'name' => 'Conductor', 'is_active' => true, 'is_roaming' => true, 'required_documents' => ['driver_license'],
        ])->assertSessionHasNoErrors();
        $this->post('/organization/positions', ['name' => 'Asistente', 'is_active' => true, 'is_roaming' => false])->assertSessionHasNoErrors();
        $this->post('/organization/positions', ['name' => 'Malo', 'is_active' => true, 'is_roaming' => false, 'required_documents' => ['inventado']])
            ->assertSessionHasErrors('required_documents.0');

        $driver = Position::withoutGlobalScopes()->where('name', 'Conductor')->sole();
        $office = Position::withoutGlobalScopes()->where('name', 'Asistente')->sole();
        $ana = $this->makeUser($acme, 'employee', ['position_id' => $driver->id]);
        $luis = $this->makeUser($acme, 'employee', ['position_id' => $office->id]);
        $config = CompanyConfiguration::forTenantId($acme->id);

        $this->assertEqualsCanonicalizing(['dni', 'medical_exam', 'driver_license'], $config->requiredDocumentsFor($ana));
        $this->assertEqualsCanonicalizing(['dni', 'medical_exam'], $config->requiredDocumentsFor($luis));
        $this->assertEqualsCanonicalizing(['dni', 'medical_exam'], $config->requiredDocumentsFor($this->makeUser($acme)), 'sin cargo: solo los de la empresa');

        // El legajo muestra lo que le falta a cada uno
        $this->get("/employees/{$ana->id}/file")->assertOk()->assertSee('Licencia de conducir');
        $this->get("/employees/{$luis->id}/file")->assertOk()->assertDontSee('Falta: Documento de identidad, Examen médico ocupacional, Licencia de conducir');
    }
}
