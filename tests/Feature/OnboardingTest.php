<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendancePunchAttempt;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\UniformSize;
use App\Modules\Onboarding\Models\OnboardingProcess;
use App\Modules\Onboarding\Models\OnboardingStage;
use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Incorporación con candado: un ingreso nuevo no marca hasta que completa sus
 * etapas y RRHH aprueba. Los colaboradores que ya existían quedan aprobados.
 */
class OnboardingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $admin;

    private User $hr;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Lima'])]);
        $this->travelTo(Carbon::parse('2026-10-09 09:00:00', 'America/Lima'));

        $this->acme     = $this->makeTenant('acme');
        $this->admin    = $this->makeUser($this->acme, 'company_admin');
        $this->hr       = $this->makeUser($this->acme, 'hr_manager');
        $this->branchId = $this->makeBranch($this->acme)->id;
        TenantSetting::editableRowFor($this->acme)->fill(['selfie_required' => false, 'offline_mode_enabled' => true, 'offline_max_hours' => 24])->save();
    }

    private function hire(string $doc = '70123456'): User
    {
        $this->actingAs($this->hr)->post('/employees', [
            'first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => "{$doc}@acme.pe", 'password' => 'Temporal123',
            'role' => 'employee', 'document_type' => 'dni', 'document_number' => $doc,
            'branch_ids' => [$this->branchId], 'primary_branch_id' => $this->branchId,
        ])->assertSessionHasNoErrors();

        $user = User::withoutGlobalScopes()->where('document_number', $doc)->sole();
        $user->forceFill(['must_change_password' => false])->saveQuietly();

        return $user;
    }

    private function web(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->actingAs($user);
    }

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->clear();

        return $this->actingIn($user, $this->acme);
    }

    private function gps(array $extra = []): array
    {
        return array_merge(['latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 10, 'device_os' => 'android'], $extra);
    }

    private function completeStages(User $user): void
    {
        EmployeeProfile::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id, 'user_id' => $user->id,
            'address' => 'Av. Arequipa 123', 'district' => 'Lince', 'emergency_contact_name' => 'Luis', 'emergency_contact_phone' => '999888777',
            'bank_name' => 'BCP', 'bank_account_number' => '19112345678', 'pension_system' => 'onp',
        ]);
        UniformSize::withoutGlobalScopes()->create(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'shirt_size' => 'M', 'pants_size' => '32', 'shoe_size' => '40']);
        foreach (CompanyConfiguration::DEFAULT_REQUIRED_DOCUMENTS as $type) {
            EmployeeDocument::withoutGlobalScopes()->create([
                'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'uploaded_by' => $user->id, 'type' => $type,
                'disk' => 'private', 'path' => "x/{$type}.pdf", 'original_name' => "{$type}.pdf", 'mime_type' => 'application/pdf',
                'size_bytes' => 100, 'sha256' => str_repeat('a', 64), 'status' => 'pending',
            ]);
        }
    }

    public function test_existing_staff_is_approved_and_new_hires_start_pending(): void
    {
        $veteran = $this->makeUser($this->acme, 'employee');
        $this->assertSame('approved', $veteran->fresh()->onboarding_status);

        $rosa = $this->hire();
        $this->assertSame('pending', $rosa->onboarding_status);
        $this->assertSame('pending', OnboardingProcess::withoutGlobalScopes()->where('user_id', $rosa->id)->value('status'));

        $this->api($rosa)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.onboarding_status', 'pending');
    }

    public function test_pending_hire_cannot_punch_online_or_offline_and_the_attempt_is_logged(): void
    {
        $rosa = $this->hire();

        $this->api($rosa)->postJson('/api/attendance/clock-in', $this->gps())
            ->assertForbidden()->assertJsonPath('code', 'ONBOARDING_PENDING')
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.next_stage', 'personal_data');

        $at = '2026-10-09T08:00:00-05:00';
        $this->api($rosa)->postJson('/api/v1/attendances/sync-batch', ['punches' => [
            $this->gps(['type' => 'clock_in', 'client_uuid' => (string) Str::uuid(), 'offline_recorded_at' => $at]),
        ]])->assertForbidden()->assertJsonPath('code', 'ONBOARDING_PENDING');

        $attempts = AttendancePunchAttempt::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame(['ONBOARDING_PENDING', 'ONBOARDING_PENDING'], $attempts->pluck('error_code')->all());
        $this->assertSame([false, true], $attempts->pluck('offline')->all());
        $this->assertEqualsWithDelta(-12.09418, $attempts[0]->latitude, 0.00001);

        // El historial (lectura) no tiene candado
        $this->api($rosa)->getJson('/api/attendance/today')->assertOk();
    }

    public function test_hire_completes_stages_submits_and_hr_approves(): void
    {
        $rosa = $this->hire();

        $summary = $this->api($rosa)->getJson('/api/onboarding')->assertOk()->json('data');
        $this->assertSame(['personal_data', 'uniforms', 'documents', 'induction'], array_column($summary['stages'], 'key'));
        $this->assertSame(['pending', 'pending', 'pending', 'not_applicable'], array_column($summary['stages'], 'status'));
        $this->assertFalse($summary['can_submit']);
        $this->assertContains('Documento de identidad', $summary['stages'][2]['missing']);

        $this->api($rosa)->postJson('/api/onboarding/submit')->assertStatus(422)
            ->assertJsonPath('errors.onboarding.0', 'Completa la etapa «Datos personales» antes de enviar.');

        $this->completeStages($rosa);
        $this->api($rosa)->getJson('/api/onboarding')->assertJsonPath('data.can_submit', true)->assertJsonPath('data.next_stage', null);

        $this->api($rosa)->postJson('/api/onboarding/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->api($rosa)->postJson('/api/onboarding/submit')->assertStatus(422);
        $this->assertSame(1, $this->hr->notifications()->where('data->type', 'onboarding.submitted')->count());
        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'onboarding.submitted')->count());

        // Panel: aparece en «Por revisar» y RRHH la aprueba
        $this->web($this->hr)->get(route('onboarding.index'))->assertOk()->assertSee('Rosa Quispe')->assertSee('Revisar');
        $this->actingAs($this->hr)->get(route('onboarding.show', $rosa->id))->assertOk()->assertSee('Aprobar incorporación');
        $this->actingAs($this->hr)->get(route('onboarding.show', $rosa->id), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Rosa Quispe')->assertSee('Etapas de incorporación')->assertSee('Aprobar incorporación');
        $this->actingAs($this->hr)->post(route('onboarding.approve', $rosa->id))->assertRedirect(route('onboarding.index'));

        $this->assertSame('approved', $rosa->fresh()->onboarding_status);
        $this->assertSame(1, $rosa->notifications()->where('data->type', 'onboarding.approved')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'onboarding.approved', 'subject_id' => $rosa->id]);

        $this->api($rosa->fresh())->postJson('/api/attendance/clock-in', $this->gps())->assertCreated();
    }

    public function test_hr_rejects_with_a_reason_and_the_hire_resubmits(): void
    {
        $rosa = $this->hire();
        $this->completeStages($rosa);
        $this->api($rosa)->postJson('/api/onboarding/submit')->assertOk();

        $this->web($this->hr)->post(route('onboarding.reject', $rosa->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->hr)->post(route('onboarding.reject', $rosa->id), ['reason' => 'La foto del DNI está borrosa'])->assertRedirect();

        $this->assertSame('rejected', $rosa->fresh()->onboarding_status);
        $this->api($rosa)->getJson('/api/onboarding')
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'La foto del DNI está borrosa')
            ->assertJsonPath('data.can_submit', true);
        $this->api($rosa)->postJson('/api/attendance/clock-in', $this->gps())->assertForbidden();
        $this->api($rosa)->postJson('/api/onboarding/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
    }

    public function test_review_permissions_rank_and_self(): void
    {
        $rosa = $this->hire();
        $sup  = $this->makeUser($this->acme, 'supervisor');
        $boss = $this->makeUser($this->acme, 'company_admin');
        $boss->forceFill(['onboarding_status' => 'pending'])->saveQuietly();
        $this->app['auth']->forgetGuards();

        $this->actingAs($sup)->get(route('onboarding.index'))->assertForbidden();
        $this->actingAs($sup)->post(route('onboarding.approve', $rosa->id))->assertForbidden();
        $this->actingAs($this->hr)->post(route('onboarding.approve', $boss->id))->assertForbidden();
        $this->actingAs($boss)->post(route('onboarding.approve', $boss->id))->assertForbidden();
        $this->assertSame('pending', $rosa->fresh()->onboarding_status);
    }

    public function test_settings_change_required_stages_documents_and_induction_course(): void
    {
        $rosa   = $this->hire();
        $course = TrainingCourse::withoutGlobalScopes()->create(['tenant_id' => $this->acme->id, 'title' => 'Bienvenida Tunesa', 'category' => 'general', 'status' => 'published']);

        $this->actingAs($this->admin)->get(route('onboarding.settings'))->assertOk()->assertSee('Etapas de incorporación')->assertSee('Bienvenida Tunesa');
        $this->actingAs($this->admin)->get(route('onboarding.settings'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Etapas de incorporación')->assertSee('Bienvenida Tunesa')->assertSee('modal_req_');
        $this->actingAs($this->hr)->get(route('onboarding.settings'))->assertOk();
        $this->actingAs($this->makeUser($this->acme, 'supervisor'))->get(route('onboarding.settings'))->assertForbidden();

        $this->actingAs($this->admin)->put(route('onboarding.settings.update'), [
            'required' => ['documents', 'induction'], 'course_id' => $course->id,
        ])->assertSessionHasNoErrors();
        // Los documentos exigidos salen de Configuración de la app
        CompanyConfiguration::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $this->acme->id], ['required_documents' => ['dni', 'cv']]);

        $stages = collect($this->api($rosa)->getJson('/api/onboarding')->json('data.stages'))->keyBy('key');
        $this->assertFalse($stages['personal_data']['required']);
        $this->assertSame(['Documento de identidad', 'Currículum (CV)'], $stages['documents']['missing']);
        $this->assertSame(['pending', ['Bienvenida Tunesa']], [$stages['induction']['status'], $stages['induction']['missing']]);

        TrainingEnrollment::withoutGlobalScopes()->create(['tenant_id' => $this->acme->id, 'course_id' => $course->id, 'user_id' => $rosa->id, 'assigned_at' => now(), 'completed_at' => now()]);
        $this->assertSame('completed', collect($this->api($rosa)->getJson('/api/onboarding')->json('data.stages'))->firstWhere('key', 'induction')['status']);

        $this->assertFalse(OnboardingStage::withoutGlobalScopes()->where('key', 'uniforms')->value('is_required'));

        // Si la app no muestra el legajo, la etapa de documentos no aplica
        CompanyConfiguration::withoutGlobalScopes()->where('tenant_id', $this->acme->id)->update(['mobile_documents_enabled' => false]);
        $this->assertSame('not_applicable', collect($this->api($rosa)->getJson('/api/onboarding')->json('data.stages'))->firstWhere('key', 'documents')['status']);
    }

    public function test_company_with_the_module_off_has_no_lock(): void
    {
        $rosa = $this->hire();
        $this->acme->forceFill(['settings' => ['disabled_modules' => ['Onboarding']]])->save();
        cache()->flush();

        $this->api($rosa)->postJson('/api/attendance/clock-in', $this->gps())->assertCreated();
    }
}
