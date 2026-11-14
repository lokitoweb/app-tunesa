<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\RolePermissions;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * «Eliminar colaborador» (registro por error): solo con employees.delete (Super Admin; él lo da a otros
 * roles), confirmación con su documento, borra todo lo suyo (UserPurger) y deja constancia en la bitácora.
 */
class EmployeeDeletionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function scenario(): array
    {
        Storage::fake('private');
        $this->seedRoles();
        $tunesa = $this->makeTenant('tunesa');
        $branch = $this->makeBranch($tunesa);
        $wrong = $this->makeUser($tunesa, 'employee', ['first_name' => 'Error', 'last_name' => 'Registro', 'document_number' => '71234567']);
        $keep = $this->makeUser($tunesa, 'employee', ['first_name' => 'Rosa', 'document_number' => '40000001']);
        app(TenantContext::class)->set($tunesa);
        Storage::disk('private')->put('legajo/error-dni.pdf', 'x');
        EmployeeDocument::create(['tenant_id' => $tunesa->id, 'user_id' => $wrong->id, 'type' => 'dni', 'disk' => 'private', 'path' => 'legajo/error-dni.pdf',
            'original_name' => 'dni.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => hash('sha256', 'x'), 'status' => 'pending']);
        Attendance::create(['tenant_id' => $tunesa->id, 'user_id' => $wrong->id, 'branch_id' => $branch->id, 'work_date' => '2026-11-10', 'status' => 'present']);
        Attendance::create(['tenant_id' => $tunesa->id, 'user_id' => $keep->id, 'branch_id' => $branch->id, 'work_date' => '2026-11-10', 'status' => 'present']);
        app(TenantContext::class)->clear();
        $wrong->createToken('app');

        return [$tunesa, $wrong, $keep];
    }

    public function test_super_admin_deletes_a_wrong_registration_with_everything_after_typing_the_document(): void
    {
        [$tunesa, $wrong, $keep] = $this->scenario();
        $root = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->get("/employees/{$wrong->id}/edit")->assertOk()
            ->assertSee('Zona de acciones peligrosas')->assertSee('1 jornadas de asistencia', false);
        // Desde el listado: ícono «Eliminar» que abre su ficha con la confirmación
        $this->get('/employees?search=Error')->assertOk()->assertSee("/employees/{$wrong->id}/edit#eliminar", false);

        // Documento equivocado: no borra nada
        $this->delete("/employees/{$wrong->id}", ['confirm_document' => '00000000'])->assertSessionHasErrorsIn('deleteEmployee', 'confirm_document');
        $this->assertTrue(DB::table('users')->where('id', $wrong->id)->exists());

        $this->delete("/employees/{$wrong->id}", ['confirm_document' => ' 7123 4567 '])->assertRedirect(route('employees.index'));

        $this->assertFalse(DB::table('users')->where('id', $wrong->id)->exists());
        $this->assertSame(0, DB::table('attendances')->where('user_id', $wrong->id)->count());
        $this->assertSame(0, DB::table('employee_documents')->where('user_id', $wrong->id)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $wrong->id)->count());
        Storage::disk('private')->assertMissing('legajo/error-dni.pdf');
        $this->assertTrue(DB::table('users')->where('id', $keep->id)->exists(), 'nadie más');
        $this->assertSame(1, DB::table('attendances')->where('user_id', $keep->id)->count());

        $log = AuditLog::withoutGlobalScopes()->where('action', 'employee.deleted_permanently')->sole();
        $this->assertSame($tunesa->id, $log->tenant_id, 'en la bitácora de su empresa');
        $this->assertSame('Error Registro', $log->meta['context']['name']);
        $this->assertStringContainsString('71234567', $log->meta['context']['document']);
    }

    public function test_only_the_super_admin_by_default_and_he_can_grant_it_with_limits(): void
    {
        [$tunesa, $wrong, $keep] = $this->scenario();
        $root = $this->makeUser(null, 'super_admin');
        $admin = $this->makeUser($tunesa, 'company_admin');
        $hr = $this->makeUser($tunesa, 'hr_manager');

        foreach ([$admin, $hr] as $who) {
            $this->actingAs($who)->get("/employees/{$wrong->id}/edit")->assertOk()->assertDontSee('Zona de acciones peligrosas');
            $this->get('/employees?search=Error')->assertOk()->assertDontSee('#eliminar', false);
            $this->actingAs($who)->delete("/employees/{$wrong->id}", ['confirm_document' => '71234567'])->assertForbidden();
        }
        $roles = app(RolePermissions::class);
        $this->assertNotContains('employees.delete', collect($roles->grantable($tunesa, $admin))->flatten(1)->pluck('slug'));

        // El Super Admin se lo da a RR. HH.: elimina a un colaborador, pero no a un administrador ni a sí mismo
        $roles->save($tunesa, 'hr_manager', array_merge($roles->effective($tunesa)['hr_manager'], ['employees.delete']), $root->id);
        User::forgetPermissionMemo();
        $this->actingAs($hr->fresh())->delete("/employees/{$admin->id}", ['confirm_document' => (string) $admin->document_number])->assertForbidden();
        $this->actingAs($hr->fresh())->delete("/employees/{$hr->id}", ['confirm_document' => (string) $hr->document_number])->assertForbidden();
        $this->actingAs($hr->fresh())->delete("/employees/{$wrong->id}", ['confirm_document' => '71234567'])->assertRedirect(route('employees.index'));
        $this->assertFalse(DB::table('users')->where('id', $wrong->id)->exists());

        // Un Super Admin no se elimina desde aquí
        $this->actingAs($root)->delete("/employees/{$root->id}", ['confirm_document' => 'x'])->assertNotFound();
    }
}
