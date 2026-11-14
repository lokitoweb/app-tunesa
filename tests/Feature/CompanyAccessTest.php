<?php

namespace Tests\Feature;

use App\Modules\Attendance\Notifications\AttendanceOutsideGeofence;
use App\Modules\Attendance\Services\Attendance\AttendanceAlertNotifier;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Acceso a varias empresas: el Super Admin asigna a una persona otras empresas para supervisarlas con su
 * mismo rol (permisos de esa empresa para su rol). Sigue siendo de su empresa: lo personal va siempre a la suya.
 */
class CompanyAccessTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function scenario(): array
    {
        $this->seedRoles();
        $tunesa = $this->makeTenant('tunesa');
        $negreiros = $this->makeTenant('negreiros');
        $hillary = $this->makeTenant('hillary');
        $trujillo = $this->makeBranch($tunesa, ['name' => 'Ag. Trujillo']);
        $lima = $this->makeBranch($negreiros, ['name' => 'Terminal Lima']);
        $piura = $this->makeBranch($negreiros, ['name' => 'Ag. Piura']);
        $this->makeBranch($hillary, ['name' => 'Sede Hillary']);
        $juan = $this->makeUser($tunesa, 'supervisor', ['first_name' => 'Juan', 'last_name' => 'Pérez']);
        $root = $this->makeUser(null, 'super_admin');

        return [$tunesa, $negreiros, $hillary, $trujillo, $lima, $piura, $juan, $root];
    }

    private function names(): array
    {
        return collect($this->get('/shifts/schedule')->assertOk()->viewData('page')['props']['branches'])->pluck('name')->sort()->values()->all();
    }

    public function test_only_the_super_admin_assigns_companies_and_it_is_audited(): void
    {
        [$tunesa, $negreiros, $hillary, , , , $juan, $root] = $this->scenario();
        $admin = $this->makeUser($tunesa, 'company_admin');
        $hr = $this->makeUser($tunesa, 'hr_manager');
        $clerk = $this->makeUser($tunesa, 'employee', ['first_name' => 'Cajero']);

        foreach ([$admin, $hr] as $who) {
            $this->actingAs($who)->get('/settings/company-access')->assertForbidden();
            $this->actingAs($who)->put('/settings/company-access', ['people' => [$juan->id], 'access' => [$juan->id => [$negreiros->id]]])->assertForbidden();
        }

        $this->actingAs($root)->get('/settings/company-access')->assertOk()->assertSee('Juan Pérez')->assertDontSee('Cajero');
        $this->put('/settings/company-access', [
            'people' => [$juan->id, $clerk->id],
            // la suya y un colaborador sin rol de gestión se ignoran
            'access' => [$juan->id => [$negreiros->id, $hillary->id, $tunesa->id], $clerk->id => [$negreiros->id]],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$negreiros->id, $hillary->id], $juan->fresh()->extraTenantIds());
        $this->assertSame([], $clerk->fresh()->extraTenantIds());
        $log = AuditLog::withoutGlobalScopes()->where('action', 'users.tenant_access_changed')->sole();
        $this->assertSame('Solo su empresa', $log->meta['context']['before']);

        // Quitar una
        $this->put('/settings/company-access', ['people' => [$juan->id], 'access' => [$juan->id => [$negreiros->id]]]);
        $this->assertSame([$negreiros->id], $juan->fresh()->extraTenantIds());
    }

    public function test_he_switches_company_and_works_there_with_his_role_while_personal_things_stay_in_his_company(): void
    {
        [$tunesa, $negreiros, $hillary, $trujillo, $lima, $piura, $juan, $root] = $this->scenario();
        $juan->extraTenants()->attach($negreiros->id, ['granted_by' => $root->id]);
        $lima->syncSupervisors([$juan->id]);
        $piura->syncSupervisors([$this->makeUser($negreiros, 'supervisor')->id]);

        // En su empresa: lo suyo, con selector de empresas (la suya y la asignada, sin "Todas")
        $this->actingAs($juan);
        $this->assertSame(['Ag. Trujillo'], $this->names());
        $this->get('/shifts/schedule')->assertInertia(fn ($p) => $p->where('switchTenants', fn ($t) => collect($t)->pluck('id')->sort()->values()->all() === collect([$tunesa->id, $negreiros->id])->sort()->values()->all()));

        // Cambia a TURISMO NEGREIROS: ve sus agencias como supervisor (la suya y ninguna de otro supervisor)
        $this->post('/admin/switch-tenant', ['tenant_id' => $negreiros->id])->assertRedirect();
        $this->assertSame(['Terminal Lima'], $this->names());
        // Rigen los permisos de esa empresa para su rol: el supervisor estándar no administra Sedes
        $this->get('/branches')->assertForbidden();

        // Lo personal sigue en su empresa
        $this->get('/account')->assertOk();
        $this->assertSame($tunesa->id, app(\App\Modules\Core\Services\TenantContext::class)->id());

        // No puede entrar a una empresa no asignada
        $this->post('/admin/switch-tenant', ['tenant_id' => $hillary->id])->assertForbidden();

        // Permisos de esa empresa para su rol: si TURISMO NEGREIROS le quita programar al supervisor, allí no programa
        $roles = app(RolePermissions::class);
        $roles->save($negreiros, 'supervisor', array_values(array_diff($roles->effective($negreiros)['supervisor'], ['shifts.program'])), $root->id);
        User::forgetPermissionMemo();
        $this->actingAs($juan->fresh())->get('/shifts/schedule')->assertForbidden();

        // Le quitan el acceso: en el siguiente clic vuelve a su empresa
        $juan->extraTenants()->detach();
        User::forgetPermissionMemo();
        $this->actingAs($juan->fresh());
        $this->assertSame(['Ag. Trujillo'], $this->names());
        $this->post('/admin/switch-tenant', ['tenant_id' => $negreiros->id])->assertForbidden();
    }

    public function test_he_can_be_named_supervisor_of_the_other_companys_branches_and_gets_their_alerts(): void
    {
        [$tunesa, $negreiros, $hillary, , $lima, , $juan, $root] = $this->scenario();
        $hrNegreiros = $this->makeUser($negreiros, 'hr_manager');

        // Sin acceso asignado no aparece entre los supervisores de la otra empresa
        $this->actingAs($hrNegreiros)->get('/branches')->assertOk()->assertDontSee('Juan Pérez');
        $this->put("/branches/{$lima->id}", ['name' => $lima->name, 'address' => 'Av. Lima 1', 'latitude' => -12.09, 'longitude' => -77.03,
            'allowed_radius_meters' => 50, 'branch_type' => 'agency', 'is_active' => 1, 'supervisors_sent' => 1, 'supervisor_ids' => [$juan->id]])
            ->assertSessionHasErrors('supervisor_ids.0');

        // Con acceso: RR. HH. de esa empresa lo elige como supervisor de su agencia y la tarjeta lo muestra
        $juan->extraTenants()->attach($negreiros->id, ['granted_by' => $root->id]);
        $this->get('/branches')->assertOk()->assertSee('Juan');
        $this->put("/branches/{$lima->id}", ['name' => $lima->name, 'address' => 'Av. Lima 1', 'latitude' => -12.09, 'longitude' => -77.03,
            'allowed_radius_meters' => 50, 'branch_type' => 'agency', 'is_active' => 1, 'supervisors_sent' => 1, 'supervisor_ids' => [$juan->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame([$juan->id], Branch::withoutGlobalScopes()->find($lima->id)->supervisors->pluck('id')->all());
        $this->get('/branches')->assertSee('Juan Pérez');

        // Los avisos de marcación de esa agencia le llegan; sin acceso, ya no
        Notification::fake();
        $emp = $this->makeUser($negreiros, 'employee');
        app(AttendanceAlertNotifier::class)->outsideGeofence($emp, ['branch_id' => $lima->id, 'distance_meters' => 300], 'clock_in', false);
        Notification::assertSentTo($juan, AttendanceOutsideGeofence::class);
        $juan->extraTenants()->detach();
        Notification::fake();
        app(AttendanceAlertNotifier::class)->outsideGeofence($emp, ['branch_id' => $lima->id, 'distance_meters' => 300], 'clock_out', false);
        Notification::assertNotSentTo($juan, AttendanceOutsideGeofence::class);
    }
}
