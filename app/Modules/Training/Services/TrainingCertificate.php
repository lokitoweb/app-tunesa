<?php

namespace App\Modules\Training\Services;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Training\Models\TrainingEnrollment;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Constancia en PDF de una capacitación completada */
class TrainingCertificate
{
    public function render(TrainingEnrollment $enrollment): string
    {
        $enrollment->loadMissing(['course', 'user.tenant']);
        $tenant   = $enrollment->user->tenant;
        $settings = TenantSetting::forTenant($tenant);

        $html = view('training.certificate', [
            'enrollment' => $enrollment,
            'course'     => $enrollment->course,
            'employee'   => $enrollment->user,
            'company'    => $settings->tradeName(),
            'color'      => $settings->primaryColor(),
            'code'       => strtoupper(substr(hash('sha256', $enrollment->id . '|' . $enrollment->completed_at?->timestamp . '|' . config('app.key')), 0, 12)),
        ])->render();

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return $pdf->output();
    }
}
