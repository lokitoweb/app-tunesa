<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\Shift;
use App\Modules\Payroll\Services\PayrollExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * «Tareo por colaborador» (propuesta aprobada por el ingeniero, 26/11/2026):
 *  - Botón en Marcaciones y GPS que descarga el Excel con el periodo y la agencia del filtro.
 *  - Un renglón por día y colaborador: 4 marcaciones, HT 1 / HT 2, total por semana, tardanza 1 y 2,
 *    horas extra con su estado y, aparte, las horas que se pagan (duda 2 = A).
 *  - Tardanza 2 (retorno del refrigerio) se descuenta en planilla como la de entrada (duda 1 = B).
 */
class TareoReportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private Branch $branch;
    private User $emp;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->withoutPunchTimeLimits();
        Storage::fake('public');
        Storage::fake('private');

        $this->acme   = $this->makeTenant('acme');
        $this->branch = $this->makeBranch($this->acme, ['name' => 'Ag. Trujillo']);
        $this->hr     = $this->makeUser($this->acme, 'hr_manager');
        $this->emp    = $this->makeUser($this->acme, 'employee', ['first_name' => 'Segundo', 'last_name' => 'Ventura Romero', 'position' => 'ADM', 'hire_date' => '2020-01-01']);
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        // ADM-LV de lunes a viernes, 08:00 · 13:00 · 14:00 · 17:00, tolerancia de entrada 10 min
        $shift = Shift::create(['tenant_id' => $this->acme->id, 'branch_id' => $this->branch->id, 'name' => 'ADM-LV', 'type' => 'fixed',
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 10, 'break_allowed' => true, 'break_duration_minutes' => 60,
            'break_start_time' => '13:00', 'break_end_time' => '14:00', 'working_days' => [1, 2, 3, 4, 5], 'daily_hours' => 8, 'color' => '#3C729E']);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $shift->id,
            'branch_id' => $this->branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function punch(string $at, string $url, bool $selfie = true): void
    {
        $this->travelTo(Carbon::parse($at, 'America/Lima'));
        $data = ['latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12]
            + ($selfie ? ['device_id' => 'pixel', 'device_os' => 'android', 'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640)] : []);
        $response = $selfie ? $this->actingIn($this->emp, $this->acme)->post($url, $data) : $this->actingIn($this->emp, $this->acme)->postJson($url, $data);
        $response->assertSuccessful();
    }

    /** Lunes 02/11: entra 08:15 (tardanza 1 = 15), vuelve 14:20 (tardanza 2 = 20) y sale 18:00 (horas extra) */
    private function monday(): Attendance
    {
        $this->punch('2026-11-02 08:15:00', '/api/attendance/clock-in');
        $this->punch('2026-11-02 13:00:00', '/api/attendance/break-start', false);
        $this->punch('2026-11-02 14:20:00', '/api/attendance/break-end', false);
        $this->punch('2026-11-02 18:00:00', '/api/attendance/clock-out');

        return Attendance::where('user_id', $this->emp->id)->sole();
    }

    public function test_tardiness_two_is_deducted_in_payroll_like_entry_tardiness(): void
    {
        $att = $this->monday();
        $this->assertSame([15, 20], [(int) $att->late_minutes, (int) $att->break_late_minutes]);
        $this->assertSame(35, $att->tardinessMinutes());
        $this->assertSame(35 + (int) $att->early_exit_minutes, $att->justifiedMinutesAtStake());

        $row = app(PayrollExportService::class)->rows(2026, 11)->firstWhere('employee_code', $this->emp->employee_code);
        $this->assertSame(35, $row['late_minutes'], 'planilla: tardanza 1 + tardanza 2');
        $this->assertSame(1, $row['late_count']);

        // Con la justificación aprobada (con goce) los dos minutos salen de tardanza y van a justificados
        $att->update(['justification_status' => 'approved', 'justification_paid' => true]);
        $row = app(PayrollExportService::class)->rows(2026, 11)->firstWhere('employee_code', $this->emp->employee_code);
        $this->assertSame([0, 0, 35], [$row['late_minutes'], $row['late_count'], $row['justified_minutes_paid']]);
    }

    public function test_button_downloads_the_tareo_with_one_row_per_day_and_the_period_summary(): void
    {
        $att = $this->monday();
        $att->update(['overtime_status' => Attendance::OVERTIME_APPROVED, 'overtime_approved_hours' => $att->overtime_hours]);
        $att->refresh();
        Holiday::create(['tenant_id' => $this->acme->id, 'date' => '2026-11-04', 'name' => 'Feriado de prueba', 'kind' => 'holiday']);
        $this->travelTo(Carbon::parse('2026-11-09 09:00:00', 'America/Lima'));

        // Botón en Marcaciones y GPS, con el periodo y la agencia del filtro
        $this->actingAs($this->hr)->get('/attendance?start_date=2026-11-02&end_date=2026-11-08&branch_id=' . $this->branch->id)->assertOk()
            ->assertSee('Tareo por colaborador')
            ->assertSee(e(route('reports.tareo.excel', ['from' => '2026-11-02', 'to' => '2026-11-08', 'branch_id' => $this->branch->id])), false);

        $response = $this->actingAs($this->hr)->get(route('reports.tareo.excel', ['from' => '2026-11-02', 'to' => '2026-11-08', 'branch_id' => $this->branch->id]));
        $response->assertOk()->assertDownload('tareo-por-colaborador_2026-11-02_al_2026-11-08.xlsx');

        $sheet = IOFactory::load($response->getFile()->getPathname())->getSheetByName('Tareo');
        $headers = $sheet->rangeToArray('A1:X1')[0];
        $this->assertSame(\App\Modules\Attendance\Services\Reports\TareoExporter::HEADERS, $headers);
        $col = fn (string $name) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search($name, $headers, true) + 1);
        $val = fn (string $name, int $row) => $sheet->getCell($col($name) . $row)->getValue();
        $min = fn (string $name, int $row) => (int) round((float) $val($name, $row) * 1440);

        // Lunes: 4 marcaciones, HT 1 = 4:45, HT 2 = 3:40, tardanzas 15 + 20 y semana en la primera fila
        $this->assertSame(['ADM', 'Ag. Trujillo', 'Ventura Romero, Segundo', 2, 'lunes, 2 de noviembre de 2026'],
            [$val('Cargo', 2), $val('Sede', 2), $val('Colaborador', 2), $val('N.°', 2), $val('Día', 2)]);
        $this->assertSame(['08:15', '13:00', '14:20', '18:00'], [$val('Marcación 1', 2), $val('Marcación 2', 2), $val('Marcación 3', 2), $val('Marcación 4', 2)]);
        $this->assertSame([285, 220, 505, 505], [$min('HT 1', 2), $min('HT 2', 2), $min('Total HT', 2), $min('Total por semana', 2)]);
        $this->assertSame(['08:00', '14:00', '17:00'], [$val('Hora ingreso 1', 2), $val('Hora ingreso 2', 2), $val('Hora salida', 2)]);
        $this->assertSame([15, 20, 35], [$min('Tardanza 1', 2), $min('Tardanza 2', 2), $min('Total tardanza', 2)]);
        $overtime = Attendance::minutes($att->overtime_hours);
        $this->assertGreaterThan(0, $overtime);
        $this->assertSame([$overtime, 'Aprobadas', $overtime], [$min('Horas extra', 2), $val('Estado horas extra', 2), $min('Total horas extra (aprobadas)', 2)]);
        $this->assertSame($att->regularMinutes() + $overtime, $min('Horas que se pagan', 2));

        // Martes sin marcación: falta; miércoles feriado; sábado y domingo: descanso (todos los días salen)
        $this->assertSame('martes, 3 de noviembre de 2026', $val('Día', 3));
        $this->assertSame('FALTÓ', $val('Marcación 1', 3), 'etiquetas oficiales del estado del día (lang/es/attendance.php)');
        $this->assertSame('FERIADO', $val('Marcación 1', 4));
        $this->assertSame('DESCANSO', $val('Marcación 1', 7));
        $this->assertSame('domingo, 8 de noviembre de 2026', $val('Día', 8));
        $this->assertArrayHasKey('F4:L4', $sheet->getMergeCells());

        // Resumen del periodo: lo marcado real y, aparte, las horas que se pagan
        $this->assertSame('Resumen del periodo', $val('Día', 9));
        $this->assertSame([505, 15, 20, 35, $overtime], [$min('Total HT', 9), $min('Tardanza 1', 9), $min('Tardanza 2', 9), $min('Total tardanza', 9), $min('Total horas extra (aprobadas)', 9)]);
        $this->assertSame($att->regularMinutes() + $overtime, $min('Horas que se pagan', 9));
        $this->assertStringContainsString('Días trabajados: 1', (string) $val('Observación', 9));
    }

    public function test_tareo_needs_report_export_permission(): void
    {
        $this->monday();
        $employee = $this->makeUser($this->acme, 'employee');
        $response = $this->actingAs($employee)->get(route('reports.tareo.excel'));
        $this->assertContains($response->getStatusCode(), [302, 403], 'sin permiso de exportar reportes no descarga');
        $response->assertHeaderMissing('content-disposition');
    }
}
