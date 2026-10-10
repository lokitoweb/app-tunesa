<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\Payslip;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * PayslipImportService
 *
 * Carga masiva de boletas PDF de un período. Cada archivo se asigna al
 * colaborador cuyo DNI/documento o código de empleado aparece en el nombre
 * del archivo (ej: "boleta_45871236.pdf", "EMP-003_octubre.pdf").
 *
 * Si el colaborador ya tiene boleta en el período: se reemplaza el PDF si
 * está en borrador; si ya fue publicada se omite (no se pisa lo que el
 * colaborador ya vio).
 */
class PayslipImportService
{
    /**
     * @param  UploadedFile[]  $files
     * @return array{created: array, replaced: array, skipped: array}
     */
    public function import(array $files, int $month, int $year, int $uploadedBy, bool $publish = false): array
    {
        $tenantId  = current_tenant_id();
        $employees = User::where('status', '!=', 'inactive')
            ->whereNotIn('role', ['super_admin'])
            ->get(['id', 'first_name', 'last_name', 'document_number', 'employee_code']);

        $result = ['created' => [], 'replaced' => [], 'skipped' => []];
        $seen   = [];

        foreach ($files as $file) {
            $name     = $file->getClientOriginalName();
            $employee = $this->matchEmployee($name, $employees);

            if (! $employee) {
                $result['skipped'][] = ['file' => $name, 'reason' => 'No se encontró un colaborador con ese DNI o código en el nombre del archivo.'];
                continue;
            }

            if (isset($seen[$employee->id])) {
                $result['skipped'][] = ['file' => $name, 'reason' => "Archivo duplicado para {$employee->full_name}."];
                continue;
            }
            $seen[$employee->id] = true;

            $existing = Payslip::where('user_id', $employee->id)
                ->where('period_month', $month)
                ->where('period_year', $year)
                ->first();

            if ($existing && $existing->isPublished()) {
                $result['skipped'][] = ['file' => $name, 'reason' => "{$employee->full_name} ya tiene una boleta publicada en el período."];
                continue;
            }

            $mm   = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
            $path = $file->storeAs(
                "payslips/{$tenantId}/{$employee->id}/{$year}/{$mm}",
                "boleta_{$year}_{$mm}_{$employee->id}_" . uniqid() . '.pdf',
                'private'
            );

            DB::transaction(function () use ($existing, $employee, $path, $file, $month, $year, $uploadedBy, $publish, $mm, &$result, $name) {
                $data = [
                    'user_id'      => $employee->id,
                    'uploaded_by'  => $uploadedBy,
                    'title'        => "Boleta de Pago - {$mm}/{$year}",
                    'period_month' => $month,
                    'period_year'  => $year,
                    'file_path'    => $path,
                    'file_name'    => $file->getClientOriginalName(),
                    'file_size'    => $file->getSize(),
                    'mime_type'    => 'application/pdf',
                    'status'       => $publish ? 'published' : 'draft',
                    'published_at' => $publish ? now() : null,
                    'read_at'      => null,
                ];

                if ($existing) {
                    Storage::disk('private')->delete($existing->file_path);
                    $existing->update($data);
                    $result['replaced'][] = ['file' => $name, 'employee' => $employee->full_name];
                } else {
                    Payslip::create($data);
                    $result['created'][] = ['file' => $name, 'employee' => $employee->full_name];
                }
            });
        }

        return $result;
    }

    /**
     * Busca en el nombre del archivo el documento o el código de empleado.
     * Se prefiere la coincidencia más larga para no confundir "EMP-1" con "EMP-12".
     */
    private function matchEmployee(string $fileName, $employees): ?User
    {
        $haystack = strtoupper(pathinfo($fileName, PATHINFO_FILENAME));
        $tokens   = preg_split('/[^A-Z0-9-]+/', $haystack, -1, PREG_SPLIT_NO_EMPTY);

        $best = null;
        $bestLength = 0;

        foreach ($employees as $employee) {
            foreach ([$employee->document_number, $employee->employee_code] as $key) {
                $key = strtoupper(trim((string) $key));
                if ($key === '' || strlen($key) < 3) {
                    continue;
                }

                // Coincidencia por token completo (evita que "123" encaje en "41234")
                if (in_array($key, $tokens, true) && strlen($key) > $bestLength) {
                    $best = $employee;
                    $bestLength = strlen($key);
                }
            }
        }

        return $best;
    }
}
