<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Importar colaboradores desde Excel (sisgere FrmIplanilla): solo altas nuevas, todo o nada */
class EmployeeImportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function csv(string $content): UploadedFile
    {
        $file = tempnam(sys_get_temp_dir(), 'emp') . '.csv';
        file_put_contents($file, $content);

        return new UploadedFile($file, 'planilla.csv', 'text/csv', null, true);
    }

    public function test_preview_then_create_only_new_employees(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $old = $this->makeUser($acme, 'employee');
        $old->update(['document_number' => '40000001']);
        $branch = $this->makeBranch($acme);

        $good = "Colaborador,DNI,Cargo,Correo,Sueldo,Banco,Tipo,Cuenta\n"
            . "NEGREIROS PIZAN ROCIO JANETT,8131621,Asistente,,1500,BCP,Ahorros,191-1\n"   // 7 dígitos: Excel quitó el cero
            . "PEÑA BALTAZAR JAIME,41692865,Conductor,jaime@correo.test,2000,,,\n"
            . "Ya Registrado Uno,40000001,Conductor,,,,,\n";

        $this->actingAs($hr)->post('/employees/import/preview', ['file' => $this->csv("Colaborador,DNI\nSin Dni Valido,123\nOtro Repetido Dos,41692865\nOtro Repetido Tres,41692865\n")])
            ->assertSessionHasErrors('file');
        $this->postJson('/employees/import/preview', ['file' => $this->csv($good)])->assertOk()
            ->assertJsonCount(2, 'new')->assertJsonPath('existing', 1)
            ->assertJsonPath('new.0.dni', '08131621')->assertJsonPath('new.0.last_name', 'Negreiros Pizan')->assertJsonPath('new.0.first_name', 'Rocio Janett');
        $this->assertSame(0, User::where('document_number', '41692865')->count()); // revisar no crea

        $this->postJson('/employees/import', ['file' => $this->csv($good)])->assertStatus(422)->assertJsonValidationErrors('branch_id');
        $this->postJson('/employees/import', ['file' => $this->csv($good), 'branch_id' => $branch->id])->assertOk()->assertJsonPath('created', 2);

        $rocio = User::where('document_number', '08131621')->sole();
        $this->assertSame(['employee', 'active', 'Asistente', '1500.00'], [$rocio->role, $rocio->status, $rocio->position, (string) $rocio->base_salary]);
        $this->assertSame(['BCP', 'savings', '191-1', 'pending'], [$rocio->profile->bank_name, $rocio->profile->bank_account_type, $rocio->profile->bank_account_number, $rocio->profile->payment_info_status]);
        $this->assertTrue($rocio->branches()->wherePivot('is_primary', true)->exists());
        $this->assertSame('jaime@correo.test', User::where('document_number', '41692865')->value('email'));

        // Volver a importar no duplica a nadie
        $this->postJson('/employees/import', ['file' => $this->csv($good), 'branch_id' => $branch->id])->assertOk()->assertJsonPath('created', 0);
        $this->assertSame(1, User::where('document_number', '08131621')->count());
        // Un colaborador común no importa
        $this->assertContains($this->actingAs($old)->postJson('/employees/import/preview', ['file' => $this->csv($good)])->status(), [302, 403]); // sin acceso al panel
    }

    public function test_city_column_places_each_employee_in_that_city_branch_like_sisgere(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $lima = $this->makeBranch($acme, ['name' => 'Sede Lima', 'city' => 'Lima']);
        $chiclayo = $this->makeBranch($acme, ['name' => 'Agencia Chiclayo', 'city' => 'Chiclayo']);
        // La sede de otra empresa con la misma ciudad nunca se usa
        $this->makeBranch($this->makeTenant('otra'), ['name' => 'Otra Trujillo', 'city' => 'Trujillo']);

        $csv = "Colaborador,DNI,Ciudad\nRUIZ PEREZ ANA,41111111,CHICLAYO\nSOTO DIAZ LUIS,42222222,\nVEGA LUNA EVA,43333333,Trujillo\n";
        $this->actingAs($hr)->postJson('/employees/import', ['file' => $this->csv($csv), 'branch_id' => $lima->id])->assertOk()->assertJsonPath('created', 3);

        $primary = fn ($dni) => User::where('document_number', $dni)->sole()->primaryBranch()->value('branches.id');
        $this->assertSame([$chiclayo->id, $lima->id, $lima->id], [$primary('41111111'), $primary('42222222'), $primary('43333333')]);
    }
}
