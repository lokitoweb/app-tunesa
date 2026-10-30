<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * QA3-06: subir más documentos al expediente (varios a la vez), ver, descargar y eliminar,
 * con validación en el servidor, bitácora y permisos. Casos T1 a T8 del informe de QA n.º 3.
 */
class EmployeeFileDocumentsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private User $hr;
    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seedRoles();
        $this->acme = $this->makeTenant('acme');
        $this->hr   = $this->makeUser($this->acme, 'hr_manager');
        $this->ana  = $this->makeUser($this->acme, 'employee', ['first_name' => 'Ana', 'last_name' => 'Peña']);
    }

    private function pdf(string $name, string $salt = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$name}{$salt}\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF");
    }

    private function upload(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->hr)->postJson("/employees/{$this->ana->id}/file/documents", $data);
    }

    /** T1 y T2: uno o varios archivos a la vez; los certificados no se reemplazan entre sí */
    public function test_t1_t2_upload_one_or_several_files_and_certificates_coexist(): void
    {
        $this->upload(['type' => 'certificate', 'files' => [$this->pdf('cert-1.pdf')]])->assertOk()->assertJsonPath('stored', 1);
        $this->upload(['type' => 'certificate', 'title' => 'Certificados de trabajo', 'files' => [$this->pdf('cert-2.pdf'), $this->pdf('cert-3.pdf'), $this->pdf('cert-4.pdf')]])
            ->assertOk()->assertJsonPath('stored', 3)->assertJsonPath('message', '3 documentos agregados al legajo.');

        $certs = EmployeeDocument::withoutGlobalScopes()->where('user_id', $this->ana->id)->where('type', 'certificate')->get();
        $this->assertCount(4, $certs);
        $this->assertSame(['approved'], $certs->pluck('status')->unique()->values()->all()); // RR. HH. sube ya aprobado; ninguno quedó reemplazado

        // Un DNI nuevo sí reemplaza al anterior (no es de los que admiten varios)
        $this->upload(['type' => 'dni', 'files' => [$this->pdf('dni-a.pdf')]])->assertOk();
        $this->upload(['type' => 'dni', 'files' => [$this->pdf('dni-b.pdf')]])->assertOk();
        $this->assertSame(['superseded', 'approved'], EmployeeDocument::withoutGlobalScopes()->where('type', 'dni')->orderBy('id')->pluck('status')->all());
    }

    /** T3 y T4: tipo y tamaño se validan en el servidor; un repetido se informa sin perder los demás */
    public function test_t3_t4_server_rejects_bad_type_size_and_reports_duplicates(): void
    {
        $exe = UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream');
        $this->upload(['type' => 'other', 'files' => [$exe]])->assertStatus(422)->assertJsonValidationErrors('files.0');

        $huge = UploadedFile::fake()->create('grande.pdf', 6 * 1024, 'application/pdf'); // el máximo de la empresa es 5 MB
        $this->upload(['type' => 'other', 'files' => [$huge]])->assertStatus(422)->assertJsonValidationErrors('files.0');

        $tooMany = array_map(fn ($i) => $this->pdf("f{$i}.pdf"), range(1, 11));
        $this->upload(['type' => 'other', 'files' => $tooMany])->assertStatus(422)->assertJsonValidationErrors('files');

        $this->upload(['type' => 'other', 'files' => [$this->pdf('a.pdf')]])->assertOk();
        $this->upload(['type' => 'other', 'files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')]])->assertOk()
            ->assertJsonPath('stored', 1)->assertJsonPath('failed.0', 'a.pdf: Este archivo ya está en el legajo del colaborador.');
        $this->assertSame(2, EmployeeDocument::withoutGlobalScopes()->count());
    }

    /** T5 y T6: eliminar (queda en la bitácora) y descargar el archivo correcto */
    public function test_t5_t6_delete_is_audited_and_download_returns_the_file(): void
    {
        $this->upload(['type' => 'certificate', 'files' => [$this->pdf('Constancia año 2024.pdf')]])->assertOk();
        $doc = EmployeeDocument::withoutGlobalScopes()->sole();

        // El nombre con tildes va en filename* (lo que usa el navegador); filename lleva el respaldo sin tildes
        $disposition = $this->actingAs($this->hr)->get(route('documents.download', $doc->id))->assertOk()->headers->get('content-disposition');
        $this->assertStringContainsString("filename*=utf-8''" . rawurlencode('Constancia año 2024.pdf'), $disposition);
        $this->assertTrue(AuditLog::where('action', 'document.downloaded')->exists());

        $this->actingAs($this->hr)->deleteJson(route('employees.file.documents.destroy', [$this->ana->id, $doc->id]))->assertOk();
        $this->assertSoftDeleted('employee_documents', ['id' => $doc->id]);
        $log = AuditLog::where('action', 'document.deleted')->sole();
        $this->assertSame('Constancia año 2024.pdf', $log->meta['context']['original_name'] ?? null);

        // No se puede borrar el documento de otra persona por la URL de otro colaborador
        $this->upload(['type' => 'certificate', 'files' => [$this->pdf('otro.pdf')]])->assertOk();
        $other = $this->makeUser($this->acme, 'employee');
        $this->actingAs($this->hr)->deleteJson(route('employees.file.documents.destroy', [$other->id, EmployeeDocument::withoutGlobalScopes()->latest('id')->first()->id]))->assertNotFound();
    }

    /** T7: un adicional no cambia el contador de obligatorios; el resumen distingue aprobados y por revisar */
    public function test_t7_additional_documents_do_not_count_and_the_summary_is_clear(): void
    {
        $required = \App\Modules\Core\Models\CompanyConfiguration::forTenantId($this->acme->id)->requiredDocumentsFor($this->ana);
        $this->assertNotEmpty($required);
        $type = $required[0];

        // La colaboradora sube un obligatorio desde la app (queda por revisar) y RR. HH. un certificado adicional
        EmployeeDocument::withoutGlobalScopes()->create(['tenant_id' => $this->acme->id, 'user_id' => $this->ana->id, 'uploaded_by' => $this->ana->id,
            'type' => $type, 'disk' => 'private', 'path' => 'x.pdf', 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => 'x', 'status' => 'pending']);
        $this->upload(['type' => 'certificate', 'files' => [$this->pdf('extra.pdf')]])->assertOk();

        $html = $this->actingAs($this->hr)->get("/employees/{$this->ana->id}/file", ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringContainsString('0 / ' . count($required) . ' aprobados', $html);
        $this->assertStringContainsString('1 por revisar', $html);
        $this->assertStringContainsString('Agregar documento', $html);
        $this->assertStringContainsString('Descargar', $html);
    }

    /** T8: sin permiso de expedientes no se sube, no se descarga ni se elimina */
    public function test_t8_users_without_permission_cannot_upload_download_or_delete(): void
    {
        $this->upload(['type' => 'certificate', 'files' => [$this->pdf('c.pdf')]])->assertOk();
        $doc = EmployeeDocument::withoutGlobalScopes()->sole();
        $accountant = $this->makeUser($this->acme, 'accountant');

        $this->upload(['type' => 'certificate', 'files' => [$this->pdf('d.pdf')]], $accountant)->assertForbidden();
        $this->actingAs($accountant)->get(route('documents.download', $doc->id))->assertForbidden();
        $this->actingAs($accountant)->deleteJson(route('employees.file.documents.destroy', [$this->ana->id, $doc->id]))->assertForbidden();
        $this->assertNotSoftDeleted('employee_documents', ['id' => $doc->id]);
    }
}
