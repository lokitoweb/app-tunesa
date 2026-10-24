<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Signatory;
use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingEnrollment;
use App\Modules\Training\Services\TrainingCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** MEJ-01: la constancia lleva el logo de la empresa y la firma del gerente */
class TrainingCertificateBrandingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_certificate_includes_logo_and_manager_signature(): void
    {
        $this->seedRoles();
        Storage::fake('private');
        Storage::fake('public');
        $acme = $this->makeTenant('acme');
        $emp = $this->makeUser($acme);
        $course = TrainingCourse::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'title' => 'Atención al cliente', 'status' => 'published']);
        $enrollment = TrainingEnrollment::withoutGlobalScopes()->create([
            'tenant_id' => $acme->id, 'course_id' => $course->id, 'user_id' => $emp->id, 'assigned_at' => now(), 'completed_at' => now(), 'progress' => 100,
        ]);

        $captured = null;
        view()->composer('training.certificate', function ($view) use (&$captured) {
            $captured = $view->getData();
        });

        // Sin firmantes: sale con el logo institucional y sin firma
        $this->assertStringStartsWith('%PDF', app(TrainingCertificate::class)->render($enrollment));
        $this->assertStringStartsWith('data:image/', $captured['logo']);
        $this->assertNull($captured['signer']);

        // Con apoderados: firma el gerente (aunque haya otro registrado antes)
        $other = $this->makeUser($acme, 'company_admin', ['first_name' => 'Ana', 'last_name' => 'Contadora']);
        $manager = $this->makeUser($acme, 'company_admin', ['first_name' => 'Jorge', 'last_name' => 'Gerente']);
        foreach ([[$other, 'Contadora'], [$manager, 'Gerente general']] as [$user, $position]) {
            Signatory::withoutGlobalScopes()->create([
                'tenant_id' => $acme->id, 'user_id' => $user->id, 'position' => $position, 'is_active' => true,
                'signature_path' => UploadedFile::fake()->image("firma{$user->id}.png")->store('finance/signatures', 'private'),
            ]);
        }

        app(TrainingCertificate::class)->render($enrollment->fresh());
        $this->assertSame('Jorge Gerente', $captured['signer']['name']);
        $this->assertSame('Gerente general', $captured['signer']['position']);
        $this->assertStringStartsWith('data:image/png;base64,', $captured['signer']['signature']);
    }
}
