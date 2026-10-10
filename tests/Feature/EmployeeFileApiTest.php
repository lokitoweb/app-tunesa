<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Fase 3 — Legajo digital, datos de pago y dotación (API).
 * Foco QA: cero fuga entre empresas, cada empleado solo ve lo suyo,
 * validación de formato/tamaño y URLs temporales.
 */
class EmployeeFileApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');
    }

    private function upload(User $user, Tenant $tenant, string $type = 'dni', ?UploadedFile $file = null)
    {
        return $this->actingIn($user, $tenant)->post('/api/employee/documents', [
            'type' => $type,
            'file' => $file ?? UploadedFile::fake()->image('dni.jpg', 800, 600),
        ]);
    }

    private function docFor(User $user, string $type = 'dni', string $status = 'pending'): EmployeeDocument
    {
        $path = "documents/{$user->tenant_id}/{$user->id}/{$type}/" . uniqid() . '.pdf';
        Storage::disk('private')->put($path, '%PDF-1.4 ' . uniqid());

        return EmployeeDocument::create([
            'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'uploaded_by' => $user->id,
            'type' => $type, 'disk' => 'private', 'path' => $path, 'original_name' => "{$type}.pdf",
            'mime_type' => 'application/pdf', 'size_bytes' => 12, 'sha256' => hash('sha256', $path), 'status' => $status,
        ]);
    }

    // -------------------------------------------------------------------------
    // Subida, formato y tamaño
    // -------------------------------------------------------------------------

    public function test_employee_uploads_a_document_to_the_private_disk_with_sha256(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        $file = UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n% CV de Ana");

        $this->upload($ana, $acme, 'cv', $file)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.type', 'cv')
            ->assertJsonPath('data.sha256', hash('sha256', "%PDF-1.4\n% CV de Ana"));

        $doc = EmployeeDocument::withoutGlobalScopes()->firstOrFail();
        Storage::disk('private')->assertExists($doc->path);
        $this->assertStringStartsWith("documents/{$acme->id}/{$ana->id}/cv/", $doc->path);
        $this->assertStringNotContainsString('cv.pdf', $doc->path, 'El nombre en disco no debe ser el original');
    }

    public function test_rejects_disallowed_formats_and_oversized_files(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);

        $this->upload($ana, $acme, 'dni', UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->upload($ana, $acme, 'dni', UploadedFile::fake()->createWithContent('dni.txt', 'texto plano'))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        // Un .php renombrado a .jpg no pasa: se valida el contenido real
        $this->upload($ana, $acme, 'dni', UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo "x";'))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->upload($ana, $acme, 'dni', UploadedFile::fake()->create('dni.pdf', 6 * 1024, 'application/pdf'))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->upload($ana, $acme, 'contract')->assertStatus(422)->assertJsonValidationErrors('type');

        $this->assertSame(0, EmployeeDocument::withoutGlobalScopes()->count());
    }

    public function test_company_max_upload_size_is_enforced_and_duplicates_rejected(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        CompanyConfiguration::create(['tenant_id' => $acme->id, 'max_upload_mb' => 1]);

        $this->upload($ana, $acme, 'cv', UploadedFile::fake()->create('cv.pdf', 1500, 'application/pdf'))
            ->assertStatus(422);

        $same = "%PDF-1.4 recibo de luz";
        $this->upload($ana, $acme, 'proof_of_address', UploadedFile::fake()->createWithContent('a.pdf', $same))->assertCreated();
        $this->upload($ana, $acme, 'proof_of_address', UploadedFile::fake()->createWithContent('b.pdf', $same))
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_new_pending_upload_replaces_previous_pending_of_same_type(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);

        $this->upload($ana, $acme, 'dni', UploadedFile::fake()->image('a.jpg'))->assertCreated();
        $first = EmployeeDocument::withoutGlobalScopes()->firstOrFail();

        $this->upload($ana, $acme, 'dni', UploadedFile::fake()->image('b.png'))->assertCreated();

        $this->assertSame(1, EmployeeDocument::withoutGlobalScopes()->count());
        Storage::disk('private')->assertMissing($first->path);
    }

    // -------------------------------------------------------------------------
    // Cada empleado solo ve lo suyo
    // -------------------------------------------------------------------------

    public function test_employee_only_sees_and_opens_own_documents(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        $luis = $this->makeUser($acme);
        $anaDoc = $this->docFor($ana);
        $this->docFor($luis, 'cv');

        $this->actingIn($luis, $acme)->getJson('/api/employee/documents')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'cv');

        $this->actingIn($luis, $acme)->getJson("/api/employee/documents/{$anaDoc->id}/url")->assertNotFound();
        $this->actingIn($luis, $acme)->deleteJson("/api/employee/documents/{$anaDoc->id}")->assertNotFound();

        $this->actingIn($ana, $acme)->getJson("/api/employee/documents/{$anaDoc->id}/url")
            ->assertOk()->assertJsonStructure(['data' => ['url', 'expires_in']]);
    }

    public function test_employees_cannot_use_admin_review_endpoints(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        $doc  = $this->docFor($ana);

        $this->actingIn($ana, $acme)->getJson('/api/admin/documents')->assertForbidden();
        $this->actingIn($ana, $acme)->postJson("/api/admin/documents/{$doc->id}/approve")->assertForbidden();
        $this->actingIn($ana, $acme)->getJson("/api/admin/employees/{$ana->id}/profile")->assertForbidden();
        $this->actingIn($ana, $acme)->getJson('/api/admin/payroll/export?year=2026&month=9')->assertForbidden();
    }

    public function test_approved_documents_cannot_be_deleted_by_the_employee(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        $doc  = $this->docFor($ana, 'dni', 'approved');

        $this->actingIn($ana, $acme)->deleteJson("/api/employee/documents/{$doc->id}")->assertStatus(409);
    }

    // -------------------------------------------------------------------------
    // Cero fuga entre empresas
    // -------------------------------------------------------------------------

    public function test_no_cross_tenant_leak_of_documents_or_bank_data(): void
    {
        $acme   = $this->makeTenant('acme');
        $globex = $this->makeTenant('globex');
        $ana    = $this->makeUser($acme);
        $hrAcme = $this->makeUser($acme, 'hr_manager');
        $hrGlob = $this->makeUser($globex, 'hr_manager');
        $empGlob = $this->makeUser($globex);
        $doc    = $this->docFor($ana);
        EmployeeProfile::create([
            'tenant_id' => $acme->id, 'user_id' => $ana->id, 'bank_name' => 'BCP',
            'bank_account_number' => '19112345678901', 'pension_system' => 'onp',
        ]);

        // RRHH de otra empresa: ni la lista, ni el archivo, ni la ficha
        $this->actingIn($hrGlob, $globex)->getJson('/api/admin/documents?status=pending')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingIn($hrGlob, $globex)->getJson("/api/admin/documents/{$doc->id}/url")->assertNotFound();
        $this->actingIn($hrGlob, $globex)->postJson("/api/admin/documents/{$doc->id}/approve")->assertNotFound();
        $this->actingIn($hrGlob, $globex)->getJson("/api/admin/employees/{$ana->id}/profile?reveal=1")->assertNotFound();

        // La planilla de otra empresa no incluye la cuenta de Ana
        $csv = $this->actingIn($hrGlob, $globex)->get('/api/admin/payroll/export?year=2026&month=9')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('19112345678901', $csv);
        $this->assertStringNotContainsString($ana->last_name, $csv);

        // Un token de Acme no sirve con la cabecera de Globex
        $this->actingIn($ana, $globex)->getJson('/api/employee/profile')->assertStatus(403);

        // Empleado de otra empresa tampoco ve el documento
        $this->actingIn($empGlob, $globex)->getJson("/api/employee/documents/{$doc->id}/url")->assertNotFound();

        // RRHH de la misma empresa sí
        $this->actingIn($hrAcme, $acme)->getJson("/api/admin/documents/{$doc->id}/url")->assertOk();
    }

    // -------------------------------------------------------------------------
    // URLs temporales firmadas
    // -------------------------------------------------------------------------

    public function test_signed_urls_expire_and_cannot_be_tampered(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        $doc  = $this->docFor($ana);
        $other = $this->docFor($this->makeUser($acme), 'cv');

        $url = $this->actingIn($ana, $acme)->getJson("/api/employee/documents/{$doc->id}/url")->json('data.url');
        app(TenantContext::class)->clear();
        $this->app['auth']->forgetGuards();

        $this->withHeaders([])->get($url)->assertOk()->assertHeader('cache-control', 'max-age=0, no-store, private');

        // Cambiar el id en una URL firmada invalida la firma
        $this->get(str_replace("/document/{$doc->id}", "/document/{$other->id}", $url))->assertForbidden();

        // Sin firma
        $this->get("/secure-files/document/{$doc->id}")->assertForbidden();

        // Vencida
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Datos de pago y previsión
    // -------------------------------------------------------------------------

    public function test_payment_info_is_encrypted_masked_and_locked_after_verification(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        $hr   = $this->makeUser($acme, 'hr_manager');

        $payload = [
            'bank_name' => 'BCP', 'bank_account_type' => 'savings', 'bank_account_currency' => 'pen',
            'bank_account_number' => '191-12345678-0-12', 'interbank_code' => '00219111234567801254',
            'pension_system' => 'afp', 'pension_fund' => 'AFP Integra', 'pension_code' => '123456ABCDE1',
        ];

        $this->actingIn($ana, $acme)->putJson('/api/employee/payment-info', $payload)
            ->assertOk()
            ->assertJsonPath('data.profile.payment.status', 'pending')
            ->assertJsonPath('data.profile.payment.bank_account_number', '••••••8012')
            ->assertJsonPath('data.profile.payment.bank_account_currency', 'PEN');

        $raw = DB::table('employee_profiles')->where('user_id', $ana->id)->value('bank_account_number');
        $this->assertStringNotContainsString('19112345678012', $raw, 'La cuenta debe estar cifrada en la base de datos');

        // AFP exige el fondo
        $this->actingIn($ana, $acme)->putJson('/api/employee/payment-info', array_merge($payload, ['pension_fund' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('pension_fund');

        // RRHH ve el número completo solo con reveal=1, y queda en la bitácora
        $this->actingIn($hr, $acme)->getJson("/api/admin/employees/{$ana->id}/profile?reveal=1")
            ->assertOk()->assertJsonPath('data.profile.payment.bank_account_number', '19112345678012');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_info.revealed', 'subject_id' => $ana->id]);

        $this->actingIn($hr, $acme)->postJson("/api/admin/employees/{$ana->id}/payment-info/review", ['decision' => 'verified'])
            ->assertOk();

        $this->actingIn($ana, $acme)->putJson('/api/employee/payment-info', $payload)
            ->assertStatus(423)->assertJsonPath('code', 'PAYMENT_INFO_LOCKED');
    }

    // -------------------------------------------------------------------------
    // Flags de la empresa
    // -------------------------------------------------------------------------

    public function test_company_flags_hide_modules_and_block_edits(): void
    {
        $acme  = $this->makeTenant('acme');
        $ana   = $this->makeUser($acme);
        $admin = $this->makeUser($acme, 'company_admin');
        $hr    = $this->makeUser($acme, 'hr_manager');

        $this->actingIn($ana, $acme)->putJson('/api/employee/uniform-sizes', ['shirt_size' => 'M', 'pants_size' => '32', 'shoe_size' => '41'])
            ->assertOk()->assertJsonPath('data.shirt_size', 'M');

        // RRHH no configura la app; el administrador sí
        $this->actingIn($hr, $acme)->putJson('/api/admin/company-configuration', ['mobile_uniforms_edit' => false])->assertForbidden();
        $this->actingIn($admin, $acme)->putJson('/api/admin/company-configuration', [
            'mobile_uniforms_edit' => false, 'mobile_payment_info_enabled' => false,
        ])->assertOk();

        $this->actingIn($ana, $acme)->getJson('/api/employee/config')
            ->assertOk()
            ->assertJsonPath('data.modules.uniforms', true)
            ->assertJsonPath('data.permissions.edit_uniforms', false)
            ->assertJsonPath('data.modules.payment_info', false)
            ->assertJsonPath('data.permissions.edit_payment_info', false);

        $this->actingIn($ana, $acme)->putJson('/api/employee/uniform-sizes', ['shirt_size' => 'L', 'pants_size' => '34', 'shoe_size' => '42'])
            ->assertForbidden()->assertJsonPath('code', 'MODULE_DISABLED');
        $this->actingIn($ana, $acme)->putJson('/api/employee/payment-info', [])->assertForbidden();
    }

    public function test_profile_reports_onboarding_progress(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        $this->docFor($ana, 'dni', 'approved');

        $this->actingIn($ana, $acme)->getJson('/api/employee/profile')
            ->assertOk()
            ->assertJsonPath('data.onboarding.progress', 0)
            ->assertJsonPath('data.documents.missing', ['cv', 'proof_of_address'])
            ->assertJsonStructure(['data' => ['user', 'profile' => ['payment', 'pension', 'employment'], 'uniforms', 'config', 'catalogs']]);
    }

    // -------------------------------------------------------------------------
    // Revisión de RRHH
    // -------------------------------------------------------------------------

    public function test_hr_approves_and_rejects_documents_and_new_approval_supersedes_old(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $ana  = $this->makeUser($acme);
        $old  = $this->docFor($ana, 'dni', 'approved');
        $new  = $this->docFor($ana, 'dni');
        $cv   = $this->docFor($ana, 'cv');

        $this->actingIn($hr, $acme)->postJson("/api/admin/documents/{$cv->id}/reject", [])->assertStatus(422);
        $this->actingIn($hr, $acme)->postJson("/api/admin/documents/{$cv->id}/reject", ['reason' => 'Ilegible, vuelve a tomar la foto'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->actingIn($hr, $acme)->postJson("/api/admin/documents/{$new->id}/approve", ['expires_at' => '2030-01-01'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.expires_at', '2030-01-01');

        $this->assertSame('superseded', $old->fresh()->status);
        $this->actingIn($hr, $acme)->postJson("/api/admin/documents/{$new->id}/approve")->assertStatus(409);
    }

    public function test_uniform_report_and_delivery_history(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $ana  = $this->makeUser($acme);

        $this->actingIn($ana, $acme)->putJson('/api/employee/uniform-sizes', ['shirt_size' => 'S', 'pants_size' => '28', 'shoe_size' => '37', 'shoe_type' => 'safety'])->assertOk();
        $this->actingIn($ana, $acme)->putJson('/api/employee/uniform-sizes', ['shirt_size' => 'XXS', 'pants_size' => '28', 'shoe_size' => '37'])->assertStatus(422);

        $this->actingIn($hr, $acme)->postJson('/api/admin/uniforms/deliveries', [
            'user_id' => $ana->id, 'item' => 'shoes', 'size' => '37', 'quantity' => 1, 'delivered_at' => now()->toDateString(),
        ])->assertCreated();

        $this->actingIn($hr, $acme)->getJson('/api/admin/uniforms')
            ->assertOk()->assertJsonPath('data.summary.shirt.S', 1)->assertJsonPath('data.summary.missing', 0);

        $csv = $this->actingIn($hr, $acme)->get('/api/admin/uniforms/export')->assertOk()->streamedContent();
        $this->assertStringContainsString($ana->full_name, $csv);
        $this->assertStringContainsString('Calzado de seguridad', $csv);

        $this->actingIn($ana, $acme)->getJson('/api/employee/uniform-sizes')
            ->assertOk()->assertJsonCount(1, 'data.deliveries');
    }
}
