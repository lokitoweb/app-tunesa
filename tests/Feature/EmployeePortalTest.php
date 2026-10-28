<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Mi espacio: portal web del colaborador, aislado del panel y solo con lo propio */
class EmployeePortalTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_an_employee_enters_mi_espacio_and_never_the_panel(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $ana = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.test']);

        $this->post('/login', ['email' => 'ana@acme.test', 'password' => 'Secret123'])->assertRedirect('/mi-espacio');
        $this->assertAuthenticatedAs($ana);

        // El panel la devuelve a su portal (sin datos de la empresa)
        $this->get('/dashboard')->assertRedirect(route('portal.home'));
        $this->get('/employees')->assertRedirect(route('portal.home'));
        $this->get('/mi-espacio')->assertOk()->assertInertia(fn ($p) => $p->component('HR/Pages/Portal/Home')->where('portal.attendance', true));
    }

    public function test_without_the_portal_permission_the_login_is_refused_and_panel_users_stay_in_the_panel(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $this->makeUser($acme, 'employee', ['email' => 'luis@acme.test']);

        // La empresa le quita el portal al rol Colaborador (Roles y permisos)
        $standard = \Database\Seeders\RolesAndPermissionsSeeder::expand(\Database\Seeders\RolesAndPermissionsSeeder::ROLES['employee'][2]);
        $this->actingAs($admin)->put('/settings/roles/employee', ['permissions' => array_values(array_diff($standard, ['portal.access']))])->assertSessionHasNoErrors();
        $this->post('/logout');

        $this->post('/login', ['email' => 'luis@acme.test', 'password' => 'Secret123'])->assertRedirect('/login');
        $this->assertGuest();

        // Quien tiene panel no usa el portal
        $this->actingAs($admin)->get('/mi-espacio')->assertRedirect(route('dashboard'));
    }

    public function test_attendance_and_justification_are_only_the_employees_own(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme);
        $ana = $this->makeUser($acme);
        $beto = $this->makeUser($acme);
        $day = now(tenant_timezone())->startOfMonth()->addDays(1)->toDateString();
        $mine = Attendance::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'branch_id' => $branch->id, 'work_date' => $day,
            'clock_in_at' => "{$day} 08:20:00", 'status' => 'late', 'late_minutes' => 20]);
        $other = Attendance::create(['tenant_id' => $acme->id, 'user_id' => $beto->id, 'branch_id' => $branch->id, 'work_date' => $day,
            'clock_in_at' => "{$day} 08:00:00", 'status' => 'present']);

        $this->actingAs($ana)->get('/mi-espacio/asistencia')->assertOk()
            ->assertInertia(fn ($p) => $p->component('HR/Pages/Portal/Attendance')->has('days', 1)->where('days.0.id', $mine->id));

        $this->post("/mi-espacio/asistencia/{$mine->id}/justificacion", ['notes' => 'Olvidé marcar: el bus se malogró'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $mine->fresh()->justification_status);
        $this->post("/mi-espacio/asistencia/{$other->id}/justificacion", ['notes' => 'x'])->assertNotFound();
        $this->assertNotSame('pending', $other->fresh()->justification_status);
    }

    public function test_payslips_follow_what_the_company_shows_and_only_own_published_ones_download(): void
    {
        Storage::fake('private');
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $ana = $this->makeUser($acme);
        $beto = $this->makeUser($acme);
        $hr = $this->makeUser($acme, 'hr_manager');
        $slip = fn ($user, $status) => Payslip::create(['tenant_id' => $acme->id, 'user_id' => $user->id, 'uploaded_by' => $hr->id, 'title' => 'Boleta',
            'period_month' => $status === 'draft' ? 10 : 9, 'period_year' => 2026,
            'file_path' => Storage::disk('private')->putFileAs('payslips', \Illuminate\Http\UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'), "{$user->id}-{$status}.pdf"),
            'file_name' => 'b.pdf', 'status' => $status, 'published_at' => $status === 'published' ? now() : null]);
        $own = $slip($ana, 'published');
        $draft = $slip($ana, 'draft');
        $foreign = $slip($beto, 'published');

        $this->actingAs($ana)->get('/mi-espacio/boletas')->assertOk()->assertInertia(fn ($p) => $p->has('payslips', 1)->where('payslips.0.id', $own->id));
        $this->get("/mi-espacio/boletas/{$own->id}/descargar")->assertOk();
        $this->get("/mi-espacio/boletas/{$draft->id}/descargar")->assertNotFound();
        $this->get("/mi-espacio/boletas/{$foreign->id}/descargar")->assertNotFound();

        // La empresa apaga "Boletas de pago" para el colaborador: tampoco en la web
        CompanyConfiguration::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $acme->id], ['mobile_payslips_enabled' => false]);
        $this->get('/mi-espacio/boletas')->assertNotFound();
        $this->get('/mi-espacio')->assertInertia(fn ($p) => $p->where('portal.payslips', false));
    }

    public function test_contingency_plan_lets_covered_people_punch_from_mi_espacio_without_gps(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $lima = $this->makeBranch($acme, ['name' => 'Lima']);
        $trujillo = $this->makeBranch($acme, ['name' => 'Trujillo']);
        $ana = $this->makeUser($acme);
        $beto = $this->makeUser($acme);
        $ana->branches()->attach($lima->id, ['is_primary' => true]);
        $beto->branches()->attach($trujillo->id, ['is_primary' => true]);
        // Sin programación obligatoria: el turno no es lo que se prueba aquí
        \App\Modules\Core\Models\TenantSetting::updateOrCreate(['tenant_id' => $acme->id], ['punch_requires_schedule' => false]);

        // Sin plan: no se marca desde la web
        $this->actingAs($ana)->post('/mi-espacio/marcar', ['type' => 'clock_in'])->assertSessionHas('error');
        $this->assertSame(0, \App\Modules\Attendance\Models\AttendancePunch::count());

        // Un colaborador no puede activarlo; RR. HH. sí, para la sede de Lima
        $this->actingAs($ana)->get('/attendance-contingency')->assertRedirect(route('portal.home'));
        $this->actingAs($hr)->post('/attendance-contingency', ['scope' => 'branch', 'scope_ids' => [$lima->id], 'reason' => 'Caída de la app', 'hours' => 4])
            ->assertSessionHasNoErrors();
        $plan = \App\Modules\Attendance\Models\AttendanceContingency::sole();

        // Ana (Lima) ve el plan y marca en orden; Beto (Trujillo) no está cubierto
        $this->actingAs($ana)->get('/mi-espacio')->assertInertia(fn ($p) => $p->where('contingency.reason', 'Caída de la app'));
        $this->post('/mi-espacio/marcar', ['type' => 'break_end'])->assertSessionHas('error');
        // Misma regla que la app: con la incorporación pendiente no se marca
        $this->post('/mi-espacio/marcar', ['type' => 'clock_in'])->assertSessionHas('error', \App\Modules\Onboarding\Middleware\EnsureOnboardingApproved::MESSAGE);
        $ana->forceFill(['onboarding_status' => \App\Modules\Onboarding\Models\OnboardingProcess::APPROVED])->save();
        $this->post('/mi-espacio/marcar', ['type' => 'clock_in'])->assertSessionHas('success');
        $punch = \App\Modules\Attendance\Models\AttendancePunch::sole();
        $this->assertSame([$plan->id, null, null], [$punch->contingency_id, $punch->latitude, $punch->within_geofence]);
        $this->assertNull(Attendance::where('user_id', $ana->id)->value('clock_in_within_geofence'), 'sin GPS: ni dentro ni fuera');
        $this->actingAs($beto)->post('/mi-espacio/marcar', ['type' => 'clock_in'])->assertSessionHas('error');

        // El cierre muestra quién marcó; al desactivarlo ya no se marca por la web
        $this->actingAs($hr)->get("/attendance-contingency?plan={$plan->id}")
            ->assertInertia(fn ($p) => $p->where('report.covered', 1)->where('report.marked', 1));
        $this->post("/attendance-contingency/{$plan->id}/end")->assertSessionHasNoErrors();
        $this->actingAs($ana)->post('/mi-espacio/marcar', ['type' => 'clock_out'])->assertSessionHas('error');

        // La marca sigue siendo inmutable
        $this->expectException(\LogicException::class);
        $punch->update(['contingency_id' => null]);
    }

    public function test_profile_masks_accounts_and_password_change_returns_to_the_portal(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $ana = $this->makeUser($acme);
        EmployeeProfile::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'bank_name' => 'BCP', 'bank_account_number' => '19112345678901']);

        $this->actingAs($ana)->get('/mi-espacio/perfil')->assertOk()
            ->assertInertia(fn ($p) => $p->component('HR/Pages/Portal/Profile')->where('data.profile.payment.bank_account_number', fn ($v) => ! str_contains($v, '1911234')));

        $this->put('/mi-espacio/perfil/password', ['current_password' => 'Secret123', 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])
            ->assertRedirect(route('portal.profile'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NuevaClave2026', $ana->fresh()->password));
    }
}
