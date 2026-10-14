<?php

namespace Tests\Feature;

use App\Modules\CompanyDocs\Models\CompanyDocument;
use App\Modules\CompanyDocs\Models\CompanyDocumentFolder;
use App\Modules\CompanyDocs\Models\CompanyDocumentRead;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Documentos de la empresa: carpetas, PDF privado, público, lectura y "Leído y conforme" */
class CompanyDocumentsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $admin;

    private Branch $lima;

    private User $emp;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seedRoles();
        $this->acme  = $this->makeTenant('acme');
        $this->admin = $this->makeUser($this->acme, 'hr_manager');
        $this->lima  = $this->makeBranch($this->acme, ['name' => 'Lima']);
        $this->emp   = $this->makeUser($this->acme, 'employee', ['first_name' => 'Ana']);
        $this->other = $this->makeUser($this->acme, 'employee', ['first_name' => 'Luis']);
        $this->emp->branches()->attach($this->lima->id, ['is_primary' => true]);
        app(TenantContext::class)->clear();
    }

    private function pdf(string $name = 'rit.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function upload(array $overrides = []): CompanyDocument
    {
        $this->actingAs($this->admin)->get('/company-documents')->assertOk();
        $folder = CompanyDocumentFolder::where('tenant_id', $this->acme->id)->first();

        $this->actingAs($this->admin)->post('/company-documents', array_merge([
            'title' => 'Reglamento Interno de Trabajo', 'version' => '2026', 'folder_id' => $folder->id, 'file' => $this->pdf(),
            'requires_acknowledgement' => '1', 'acknowledge_by' => now()->addWeek()->toDateString(), 'status' => 'published',
            'targets' => [['type' => 'branch', 'id' => $this->lima->id]],
        ], $overrides))->assertSessionHasNoErrors()->assertRedirect();
        app(TenantContext::class)->clear();

        return CompanyDocument::latest('id')->firstOrFail();
    }

    public function test_upload_publish_notifies_audience_only(): void
    {
        $this->assertSame(0, CompanyDocumentFolder::count());
        $doc = $this->upload();

        $this->assertSame(count(CompanyDocumentFolder::DEFAULTS), CompanyDocumentFolder::where('tenant_id', $this->acme->id)->count());
        Storage::disk('private')->assertExists($doc->file_path);
        $note = $this->emp->notifications()->where('type', 'company_document.published')->sole();
        $this->assertSame('Documento por leer y confirmar', $note->data['title']);
        $this->assertSame('company_documents', $note->data['data']['screen']);
        $this->assertSame(0, $this->other->notifications()->count());

        $this->actingAs($this->admin)->get("/company-documents/{$doc->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('CompanyDocs/Pages/Documents/Show')->where('stats.audience', 1)->where('stats.read', 0));
        $this->get("/company-documents/{$doc->id}/file")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_employee_reads_and_acknowledges(): void
    {
        $doc = $this->upload();

        $this->actingIn($this->emp, $this->acme)->getJson('/api/company-documents')->assertOk()
            ->assertJsonPath('data.0.title', 'Reglamento Interno de Trabajo')
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('data.0.pending_acknowledgement', true)
            ->assertJsonPath('meta.pending_acknowledgement', 1)
            ->assertJsonPath('meta.folders.0.count', 1);

        $this->get("/api/company-documents/{$doc->id}/file")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/company-documents/{$doc->id}/acknowledge")->assertOk()->assertJsonPath('data.pending_acknowledgement', false);
        $first = CompanyDocumentRead::where('user_id', $this->emp->id)->sole()->acknowledged_at;
        $this->postJson("/api/company-documents/{$doc->id}/acknowledge")->assertOk();
        $this->assertEquals($first, CompanyDocumentRead::where('user_id', $this->emp->id)->sole()->acknowledged_at);

        $this->flushHeaders()->actingAs($this->admin)->get("/company-documents/{$doc->id}")
            ->assertInertia(fn ($p) => $p->where('stats.read', 1)->where('stats.acknowledged', 1));
        $csv = $this->get("/company-documents/{$doc->id}/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('Ana', $csv);
    }

    public function test_outsiders_drafts_and_other_tenants_cannot_read(): void
    {
        $doc   = $this->upload();
        $draft = $this->upload(['status' => 'draft', 'title' => 'Borrador', 'targets' => [['type' => 'all']]]);

        $this->actingIn($this->other, $this->acme)->getJson('/api/company-documents')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/company-documents/{$doc->id}/file")->assertNotFound();
        $this->postJson("/api/company-documents/{$doc->id}/acknowledge")->assertNotFound();
        $this->getJson("/api/company-documents/{$draft->id}")->assertNotFound();

        app(TenantContext::class)->clear();
        $globex = $this->makeTenant('globex');
        $alien  = $this->makeUser($globex, 'company_admin');
        app(TenantContext::class)->clear();
        $this->actingIn($alien, $globex)->getJson("/api/company-documents/{$doc->id}")->assertNotFound();
        $this->flushHeaders()->actingAs($alien)->get("/company-documents/{$doc->id}/file")->assertNotFound();
    }

    public function test_validation_folders_and_replacing_the_pdf(): void
    {
        $doc = $this->upload();
        $oldPath = $doc->file_path;

        $this->actingAs($this->admin)->post('/company-documents', ['title' => 'Sin PDF', 'status' => 'draft', 'folder_id' => $doc->folder_id, 'targets' => [['type' => 'all']]])
            ->assertSessionHasErrors('file');
        $this->post('/company-documents', ['title' => 'Imagen', 'status' => 'draft', 'folder_id' => $doc->folder_id, 'file' => UploadedFile::fake()->image('x.png'), 'targets' => [['type' => 'all']]])
            ->assertSessionHasErrors('file');

        $this->put("/company-documents/{$doc->id}", ['title' => 'RIT v2', 'status' => 'published', 'folder_id' => $doc->folder_id, 'file' => $this->pdf('rit-v2.pdf'), 'targets' => [['type' => 'all']]])
            ->assertSessionHasNoErrors();
        $doc->refresh();
        $this->assertNotSame($oldPath, $doc->file_path);
        Storage::disk('private')->assertMissing($oldPath);
        Storage::disk('private')->assertExists($doc->file_path);
        $this->assertSame(1, $this->emp->notifications()->count(), 'Editar un documento ya publicado no vuelve a avisar');

        $this->post('/company-documents/folders', ['name' => 'Comunicados', 'icon' => 'megaphone', 'color' => '#123456'])->assertSessionHasNoErrors();
        $this->delete("/company-documents/folders/{$doc->folder_id}")->assertSessionHas('error');
    }
}
