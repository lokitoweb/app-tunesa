<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\RolePermissionOverride;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\RolePermissions;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\EmployeeTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Traslado de un colaborador a otra empresa del grupo: cese con su historia en el origen, registro nuevo
 * con sus datos personales y el mismo acceso en el destino, permisos (RR. HH. y Super Admin; el Super
 * Admin lo da a otros roles; el administrador de empresa no) y validaciones.
 */
class EmployeeTransferTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function scenario(): array
    {
        Storage::fake('private');
        $this->seedRoles();
        $tunesa = $this->makeTenant('tunesa');
        $negreiros = $this->makeTenant('negreiros');
        $trujillo = $this->makeBranch($tunesa, ['name' => 'Ag. Trujillo']);
        $lima = $this->makeBranch($negreiros, ['name' => 'Terminal Lima']);
        $hr = $this->makeUser($tunesa, 'hr_manager');
        $ana = $this->makeUser($tunesa, 'employee', ['first_name' => 'Ana', 'last_name' => 'Díaz', 'email' => 'ana@gmail.com',
            'document_number' => '45678912', 'hire_date' => '2025-11-01', 'base_salary' => 1500, 'fcm_token' => 'telefono-ana']);
        $ana->branches()->attach($trujillo->id, ['is_primary' => true, 'assigned_from' => '2025-11-01']);
        $ana->createToken('app');
        app(TenantContext::class)->set($tunesa);
        EmployeeProfile::create(['tenant_id' => $tunesa->id, 'user_id' => $ana->id, 'address' => 'Jr. Pizarro 123', 'bank_name' => 'BCP',
            'bank_account_number' => '191-1234567-0-12', 'pension_system' => 'afp', 'pension_fund' => 'Prima', 'payment_info_status' => 'verified']);
        foreach (['dni' => 'legajo/ana-dni.pdf', 'contract' => 'legajo/ana-contrato.pdf'] as $type => $path) {
            Storage::disk('private')->put($path, "pdf {$type}");
            EmployeeDocument::create(['tenant_id' => $tunesa->id, 'user_id' => $ana->id, 'type' => $type, 'disk' => 'private', 'path' => $path,
                'original_name' => "{$type}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 7, 'sha256' => hash('sha256', "pdf {$type}"), 'status' => 'approved']);
        }
        Attendance::create(['tenant_id' => $tunesa->id, 'user_id' => $ana->id, 'branch_id' => $trujillo->id, 'work_date' => '2026-11-10', 'status' => 'present']);
        app(TenantContext::class)->clear();

        return [$tunesa, $negreiros, $trujillo, $lima, $hr, $ana];
    }

    private function form(int $tenantId, int $branchId, array $extra = []): array
    {
        return array_merge(['tenant_id' => $tenantId, 'effective_date' => today()->toDateString(), 'branch_id' => $branchId,
            'role' => 'employee', 'base_salary' => 1800, 'copy_documents' => '1', 'onboarding_required' => '0', 'notes' => 'Reasignación'], $extra);
    }

    public function test_hr_transfers_an_employee_history_stays_and_she_keeps_her_login_in_the_new_company(): void
    {
        [$tunesa, $negreiros, , $lima, $hr, $ana] = $this->scenario();

        $this->actingAs($hr)->get("/employees/{$ana->id}/edit")->assertOk()->assertSee('Trasladar a otra empresa');
        $this->getJson("/employees/{$ana->id}/transfer/options?tenant_id={$negreiros->id}")->assertOk()->assertJsonPath('branches.0.name', 'Terminal Lima');
        $this->post("/employees/{$ana->id}/transfer", $this->form($negreiros->id, $lima->id))->assertSessionHasNoErrors()->assertRedirect("/employees/{$ana->id}/edit");

        // Origen: cese el día anterior, sin acceso; su historia sigue aquí
        $old = User::withoutGlobalScopes()->find($ana->id);
        $this->assertSame('inactive', $old->status);
        $this->assertSame(today()->subDay()->toDateString(), $old->termination_date->toDateString());
        $this->assertSame("ana+trasladado-{$ana->id}@gmail.com", $old->email);
        $this->assertNull($old->fcm_token);
        $this->assertSame(0, $old->tokens()->count());
        $this->assertSame(1, Attendance::withoutGlobalScopes()->where('user_id', $ana->id)->count());
        $this->assertSame(2, EmployeeDocument::withoutGlobalScopes()->where('user_id', $ana->id)->count());

        // Destino: registro nuevo con sus datos personales y los de la nueva empresa
        $new = User::withoutGlobalScopes()->where('email', 'ana@gmail.com')->sole();
        $this->assertSame([$negreiros->id, 'Ana', '45678912', 'employee', 'active', today()->toDateString(), 1800.0],
            [$new->tenant_id, $new->first_name, $new->document_number, $new->role, $new->status, $new->hire_date->toDateString(), (float) $new->base_salary]);
        $this->assertSame([$lima->id], DB::table('employee_branch')->where('user_id', $new->id)->where('is_primary', true)->pluck('branch_id')->all());
        $this->assertSame('approved', $new->onboarding_status, 'sin pedir incorporación marca desde su primer día');
        $profile = EmployeeProfile::withoutGlobalScopes()->where('user_id', $new->id)->sole();
        $this->assertSame([$negreiros->id, 'Jr. Pizarro 123', '191-1234567-0-12', 'Prima', 'pending'],
            [$profile->tenant_id, $profile->address, $profile->bank_account_number, $profile->pension_fund, $profile->payment_info_status]);
        $docs = EmployeeDocument::withoutGlobalScopes()->where('user_id', $new->id)->get();
        $this->assertSame(['dni'], $docs->pluck('type')->all(), 'el contrato es del empleador anterior');
        Storage::disk('private')->assertExists($docs->first()->path);
        $this->assertNotSame('legajo/ana-dni.pdf', $docs->first()->path);

        // Mismo correo y contraseña: la app entra directo a la nueva empresa
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@gmail.com', 'password' => 'Secret123'])
            ->assertOk()->assertJsonPath('data.user.company.slug', 'negreiros');

        // Enlace entre ambos registros y bitácora en cada empresa
        $transfer = EmployeeTransfer::sole();
        $this->assertSame([$ana->id, $new->id, 1], [$transfer->from_user_id, $transfer->to_user_id, $transfer->documents_copied]);
        $this->assertSame($tunesa->id, AuditLog::withoutGlobalScopes()->where('action', 'employee.transferred_out')->value('tenant_id'));
        $this->assertSame($negreiros->id, AuditLog::withoutGlobalScopes()->where('action', 'employee.transferred_in')->value('tenant_id'));
        $this->actingAs($hr)->get("/employees/{$ana->id}/edit")->assertOk()->assertSee('Trasladado a Negreiros', false)->assertDontSee('data-bs-target="#transferModal"', false);
        $root = $this->makeUser(null, 'super_admin');
        $this->actingAs($root)->get("/employees/{$new->id}/edit")->assertOk()->assertSee('Trasladado desde Tunesa', false);
    }

    public function test_who_can_transfer_hr_and_super_admin_yes_company_admin_no_and_only_the_super_admin_grants_it(): void
    {
        [$tunesa, $negreiros, , $lima, $hr, $ana] = $this->scenario();
        $admin = $this->makeUser($tunesa, 'company_admin');
        $supervisor = $this->makeUser($tunesa, 'supervisor');
        $root = $this->makeUser(null, 'super_admin');

        foreach ([$admin, $supervisor] as $who) {
            $this->actingAs($who)->post("/employees/{$ana->id}/transfer", $this->form($negreiros->id, $lima->id))->assertForbidden();
            $this->actingAs($who)->get("/employees/{$ana->id}/edit")->assertDontSee('Trasladar a otra empresa');
        }
        $this->assertTrue($hr->fresh()->hasPermission('employees.transfer'));

        // El administrador de la empresa no ve el permiso en Roles y permisos, ni lo da, ni lo quita al restablecer
        $roles = app(RolePermissions::class);
        $slugs = fn (User $u) => collect($roles->grantable($tunesa, $u))->flatten(1)->pluck('slug');
        $this->assertNotContains('employees.transfer', $slugs($admin));
        $this->assertContains('employees.transfer', $slugs($root));
        $roles->save($tunesa, 'supervisor', array_merge($roles->effective($tunesa)['supervisor'], ['employees.transfer']), $admin->id);
        $this->assertFalse(User::forgetPermissionMemo() ?? $supervisor->fresh()->hasPermission('employees.transfer'));

        // El Super Admin se lo da al supervisor; si el administrador restablece el rol, se conserva
        $roles->save($tunesa, 'supervisor', array_merge($roles->effective($tunesa)['supervisor'], ['employees.transfer']), $root->id);
        $roles->reset($tunesa, 'supervisor', $admin);
        User::forgetPermissionMemo();
        $this->assertTrue($supervisor->fresh()->hasPermission('employees.transfer'));
        $this->assertTrue(RolePermissionOverride::withoutGlobalScopes()->where('role', 'supervisor')
            ->where('permission_id', Permission::where('slug', 'employees.transfer')->value('id'))->exists());

        // El Super Admin, también desde la vista global
        $this->actingAs($root)->post("/employees/{$ana->id}/transfer", $this->form($negreiros->id, $lima->id))->assertSessionHasNoErrors();
        $this->assertSame(1, EmployeeTransfer::count());
    }

    public function test_validations_keep_everything_consistent(): void
    {
        [$tunesa, $negreiros, $trujillo, $lima, $hr, $ana] = $this->scenario();
        $this->actingAs($hr);
        $post = fn (array $extra) => $this->post("/employees/{$ana->id}/transfer", $this->form($negreiros->id, $lima->id, $extra));

        $post(['tenant_id' => $tunesa->id, 'branch_id' => $trujillo->id])->assertSessionHasErrors('tenant_id');
        $post(['effective_date' => today()->addDay()->toDateString()])->assertSessionHasErrors('effective_date');
        $post(['branch_id' => $trujillo->id])->assertSessionHasErrors('branch_id');
        $post(['role' => 'hr_manager'])->assertForbidden(); // RR. HH. no da un rol de su mismo rango
        $post(['effective_date' => '2025-11-01'])->assertStatus(422); // antes de su ingreso actual
        $this->assertSame(0, EmployeeTransfer::count());
        $this->assertSame('active', $ana->fresh()->status, 'nada cambió');
        $this->assertSame('ana@gmail.com', $ana->fresh()->email);

        // Pidiendo la incorporación, en la nueva empresa espera su aprobación; sin documentos no copia nada
        $post(['onboarding_required' => '1', 'copy_documents' => '0'])->assertSessionHasNoErrors();
        $new = User::withoutGlobalScopes()->where('email', 'ana@gmail.com')->sole();
        $this->assertSame('pending', $new->onboarding_status);
        $this->assertSame(0, EmployeeDocument::withoutGlobalScopes()->where('user_id', $new->id)->count());

        // Ya trasladada: no se vuelve a trasladar desde el registro antiguo
        $this->post("/employees/{$ana->id}/transfer", $this->form($negreiros->id, $lima->id))->assertStatus(422);
        $this->assertSame(1, DB::table('employee_transfers')->count());
        $this->assertTrue(Hash::check('Secret123', $new->password));
    }
}
