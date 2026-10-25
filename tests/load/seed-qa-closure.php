<?php

/**
 * Complemento de seed-load.php para el cierre de QA (k6 qa-closure.js), sobre la
 * MISMA copia de la base:
 *   DB_CONNECTION=mysql DB_DATABASE=tunesa_load php tests/load/seed-qa-closure.php empresa-demo /ruta/load-users.json
 *
 * A los colaboradores de carga les asigna un turno de toda la semana con sábado
 * corto (ejercita Shift::forDate) y les publica una boleta PDF a cada uno.
 * Se puede correr varias veces.
 */

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Facades\Storage;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$slug, $usersFile] = [$argv[1] ?? 'empresa-demo', $argv[2] ?? 'load-users.json'];
$connection = config('database.default');
if (! str_contains((string) config("database.connections.{$connection}.database"), 'load')) {
    exit("Solo sobre una base de prueba: DB_DATABASE debe contener «load»\n");
}

$tenant = Tenant::where('slug', $slug)->firstOrFail();
$t = $tenant->id;
$branch = Branch::withoutGlobalScopes()->where('tenant_id', $t)->firstOrFail();
$hr = User::withoutGlobalScopes()->where('tenant_id', $t)->where('role', 'hr_manager')->firstOrFail();
$data = json_decode(file_get_contents($usersFile), true);

$shift = Shift::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $t, 'name' => 'Carga 7x7'], [
    'type' => 'fixed', 'profile' => 'sales', 'start_time' => '00:00', 'end_time' => '23:59', 'grace_period_minutes' => 10,
    'break_allowed' => true, 'break_duration_minutes' => 60, 'daily_hours' => 8, 'working_days' => [0, 1, 2, 3, 4, 5, 6],
    'color' => '#0284C7', 'is_active' => true,
    'day_schedules' => ['6' => ['start_time' => '00:00', 'end_time' => '23:00', 'break_duration_minutes' => 0, 'daily_hours' => 5]],
]);

// PDF mínimo válido (lo que la app valida: empieza con %PDF)
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

foreach ($data['employees'] as $emp) {
    EmployeeShift::withoutGlobalScopes()->firstOrCreate(
        ['tenant_id' => $t, 'user_id' => $emp['id'], 'shift_id' => $shift->id],
        ['branch_id' => $branch->id, 'effective_from' => '2020-01-01', 'is_active' => true],
    );
    $path = "payslips/load/{$emp['id']}.pdf";
    Storage::disk('private')->put($path, $pdf);
    Payslip::withoutGlobalScopes()->updateOrCreate(
        ['tenant_id' => $t, 'user_id' => $emp['id'], 'period_month' => 9, 'period_year' => 2026],
        ['uploaded_by' => $hr->id, 'title' => 'Boleta carga', 'file_path' => $path, 'file_name' => 'boleta.pdf',
            'file_size' => strlen($pdf), 'mime_type' => 'application/pdf', 'status' => 'published', 'published_at' => now()],
    );
}

echo 'Listo: turno de carga y una boleta para ' . count($data['employees']) . " colaboradores\n";
