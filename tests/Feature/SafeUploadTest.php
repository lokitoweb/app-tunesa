<?php

namespace Tests\Feature;

use App\Modules\Core\Rules\SafeUpload;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\Requisition;
use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Validación estricta de PDFs/imágenes y entrega privada de adjuntos (Bloque 1) */
class SafeUploadTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function pdf(string $body = 'BT /F1 12 Tf (Voucher) Tj ET'): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
            . "3 0 obj << /Length " . strlen($body) . " >> stream\n{$body}\nendstream endobj\ntrailer << /Root 1 0 R >>\n%%EOF";
    }

    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_rule_accepts_clean_files_and_rejects_active_or_fake_ones(): void
    {
        $this->assertNull(SafeUpload::inspect($this->file('ok.pdf', $this->pdf())));
        $this->assertNull(SafeUpload::inspect(UploadedFile::fake()->image('foto.jpg')));

        $cases = [
            'pdf con JavaScript'       => $this->pdf() . "\n4 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj",
            'pdf que lanza programas'  => $this->pdf() . "\n4 0 obj << /S /Launch /F (cmd.exe) >> endobj",
            'pdf con archivo incrustado' => $this->pdf() . "\n4 0 obj << /Type /EmbeddedFile >> endobj",
            'ejecutable como pdf'      => "MZ\x90\x00" . str_repeat("\x00", 200),
            'html como pdf'            => '<html><script>alert(1)</script></html>',
        ];
        foreach ($cases as $label => $content) {
            $this->assertNotNull(SafeUpload::inspect($this->file('x.pdf', $content)), $label);
        }
        // "/JSFoo" no es un marcador de JavaScript
        $this->assertNull(SafeUpload::inspect($this->file('ok.pdf', $this->pdf('(/JSONData) Tj'))));
        $this->assertNotNull(SafeUpload::pdf()->inspect(UploadedFile::fake()->image('foto.jpg'), [SafeUpload::PDF]), 'solo PDF');
    }

    public function test_treasury_rejects_active_pdf_and_attachments_are_served_privately(): void
    {
        Storage::fake('private');
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $accountant = $this->makeUser($acme, 'accountant');
        $manager = $this->makeUser($acme, 'manager');
        $r = Requisition::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'series' => 'RQ', 'number' => 1, 'requested_at' => now(), 'detail' => 'x', 'stage' => 'ordered', 'currency' => 'PEN', 'amount' => 100, 'installments' => 1]);
        $p = PaymentInstallment::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'requisition_id' => $r->id, 'installment' => 1, 'due_date' => now(), 'amount' => 100,
            'status' => 'pending', 'scheduled_for' => now(), 'authorized_by_id' => $manager->id, 'authorized_at' => now()]);
        $account = CompanyBankAccount::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'bank' => 'BCP', 'account_number' => '1', 'currency' => 'PEN']);
        $pay = ['paid_at' => now()->toDateString(), 'amount' => 100, 'company_bank_account_id' => $account->id, 'operation_number' => 'OP'];

        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay + ['voucher' => $this->file('v.pdf', $this->pdf() . "\n<< /S /JavaScript /JS (x) >>")])
            ->assertSessionHasErrors('voucher');
        $this->assertSame('pending', $p->fresh()->status, 'no se registró el pago');

        app(\App\Modules\Core\Services\TenantContext::class)->clear();
        // Adjunto de un permiso: se sirve privado (no cacheable), con nosniff y sandbox
        $emp = $this->makeUser($acme, 'employee');
        Storage::disk('private')->put('leave-requests/1/x.pdf', $this->pdf());
        $leave = LeaveRequest::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'type' => 'medical_leave', 'start_date' => now(), 'end_date' => now(),
            'reason' => 'x', 'status' => 'pending', 'attachment_path' => 'leave-requests/1/x.pdf']);
        $res = $this->actingAs($this->makeUser($acme, 'hr_manager'))->get("/approvals/leave/{$leave->id}/attachment")->assertOk();
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $res->headers->get('Cache-Control'));
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));

        // Otra empresa no lo ve
        app(\App\Modules\Core\Services\TenantContext::class)->clear();
        $this->actingAs($this->makeUser($this->makeTenant('otra'), 'hr_manager'))->get("/approvals/leave/{$leave->id}/attachment")->assertNotFound();
    }
}
