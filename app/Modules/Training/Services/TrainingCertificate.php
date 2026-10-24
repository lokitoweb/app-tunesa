<?php

namespace App\Modules\Training\Services;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Finance\Models\Signatory;
use App\Modules\Training\Models\TrainingEnrollment;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * Constancia en PDF de una capacitación completada, con el logo de la empresa
 * y la firma escaneada del gerente (apoderados en Finanzas › Firmantes).
 */
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
            'logo'       => $this->logo($settings),
            'signer'     => $this->signer($tenant?->id),
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

    /** Logo de la empresa (o el institucional) como data URI: Dompdf no lee URLs */
    private function logo(TenantSetting $settings): ?string
    {
        $path = $settings->logoPath();
        if ($path && Storage::disk('public')->exists($path)) {
            return 'data:' . Storage::disk('public')->mimeType($path) . ';base64,' . base64_encode(Storage::disk('public')->get($path));
        }

        $default = public_path(TenantSetting::DEFAULT_LOGO);

        return is_file($default) ? 'data:' . mime_content_type($default) . ';base64,' . base64_encode(file_get_contents($default)) : null;
    }

    /**
     * Quién firma: el apoderado activo con firma escaneada cuyo cargo es de
     * gerente; si no hay, el primero activo con firma. Sin firmantes, la
     * constancia sale sin firma (solo la línea con el nombre de la empresa).
     *
     * @return array{name: string, position: ?string, signature: string}|null
     */
    private function signer(?int $tenantId): ?array
    {
        if (! $tenantId) {
            return null;
        }

        $candidates = Signatory::withoutGlobalScopes()->with('user:id,first_name,last_name')
            ->where('tenant_id', $tenantId)->where('is_active', true)->whereNotNull('signature_path')
            ->orderByRaw("CASE WHEN LOWER(position) LIKE '%gerente%' THEN 0 ELSE 1 END")->orderBy('id')
            ->get();

        foreach ($candidates as $signatory) {
            if ($signature = $signatory->signatureDataUri()) {
                return ['name' => $signatory->user?->full_name ?? '', 'position' => $signatory->position, 'signature' => $signature];
            }
        }

        return null;
    }
}
