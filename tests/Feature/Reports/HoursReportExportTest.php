<?php

namespace Tests\Feature\Reports;

use App\Models\Attendance;
use App\Models\EmployeeShift;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MonthlyReportReady;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;
use ZipArchive;

/**
 * Panel y exportaciones del "Reporte de horas y faltas" (Excel / PDF) y el
 * reporte mensual automático (reports:send-monthly).
 */
class HoursReportExportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'America/Lima'));

        $this->tenant   = $this->makeTenant('acme', ['company_name' => 'Acme SAC']);
        $branch         = $this->makeBranch($this->tenant, ['name' => 'Sede Centro']);
        $this->employee = $this->makeUser($this->tenant, 'employee', ['first_name' => 'Ana', 'last_name' => 'Rojas', 'employee_code' => 'E001']);
        $this->employee->branches()->attach($branch->id, ['is_primary' => true]);

        $shift = Shift::create(['tenant_id' => $this->tenant->id, 'name' => 'L-V', 'start_time' => '08:00', 'end_time' => '17:00',
            'daily_hours' => 8, 'working_days' => [1, 2, 3, 4, 5]]);
        EmployeeShift::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id, 'shift_id' => $shift->id,
            'branch_id' => $branch->id, 'effective_from' => '2026-01-01', 'is_active' => true]);

        // Lunes 05/10: 08:00–19:00 con 1 h de refrigerio → 10 h, 2 h extra diurnas
        Attendance::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id, 'branch_id' => $branch->id,
            'shift_id' => $shift->id, 'work_date' => '2026-10-05', 'clock_in_at' => '2026-10-05 08:00:00',
            'clock_out_at' => '2026-10-05 19:00:00', 'break_minutes' => 60, 'late_minutes' => 0, 'status' => 'present']);
        // 06/10 a 08/10 sin marcación → 3 faltas injustificadas
    }

    private const RANGE = ['from' => '2026-10-05', 'to' => '2026-10-08'];

    private function xlsxFromResponse($response): ZipArchive
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'xt');
        copy($path, $copy);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($copy) === true, 'El .xlsx debe ser un zip válido');

        return $zip;
    }

    /** Texto de los streams del PDF (descomprimidos) */
    private function pdfText(string $pdf): string
    {
        $text = '';
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
        foreach ($m[1] as $stream) {
            $text .= @gzuncompress($stream) ?: $stream;
        }

        return $text;
    }

    public function test_preview_page_shows_summary_and_export_buttons(): void
    {
        $this->actingAs($this->makeUser($this->tenant, 'company_admin'))
            ->get(route('reports.hours', self::RANGE))
            ->assertOk()
            ->assertSee('Reporte de horas y faltas')
            ->assertSee('Ana Rojas')
            ->assertSee(route('reports.hours.excel', self::RANGE))
            ->assertViewHas('totals', fn ($t) => $t['absences_unjustified'] === 3 && $t['worked_hours'] === 10.0);
    }

    public function test_supervisor_previews_team_but_cannot_export(): void
    {
        $supervisor = $this->makeUser($this->tenant, 'supervisor');
        $this->employee->branches()->first()->update(['supervisor_id' => $supervisor->id]);
        $this->makeUser($this->tenant, 'employee', ['first_name' => 'Otro', 'last_name' => 'Equipo']);

        $this->actingAs($supervisor)->get(route('reports.hours', self::RANGE))
            ->assertOk()->assertSee('Ana Rojas')->assertDontSee('Otro Equipo')
            ->assertDontSee(route('reports.hours.excel', self::RANGE));

        $this->get(route('reports.hours.excel', self::RANGE))->assertForbidden();
        $this->get(route('reports.hours.pdf', self::RANGE))->assertForbidden();
    }

    public function test_excel_export_is_a_valid_xlsx_with_summary_and_detail_sheets(): void
    {
        $response = $this->actingAs($this->makeUser($this->tenant, 'hr_manager'))
            ->get(route('reports.hours.excel', self::RANGE))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('horas-y-faltas_2026-10-05_al_2026-10-08.xlsx', $response->headers->get('Content-Disposition'));

        $zip = $this->xlsxFromResponse($response);
        $workbook = $zip->getFromName('xl/workbook.xml');
        $sheet1   = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet2   = $zip->getFromName('xl/worksheets/sheet2.xml');
        $strings  = $zip->getFromName('xl/sharedStrings.xml');

        $this->assertNotFalse($sheet1);
        $this->assertStringContainsString('name="Resumen"', $workbook);
        $this->assertStringContainsString('name="Detalle diario"', $workbook);
        $this->assertStringContainsString('state="frozen"', $sheet1);
        $this->assertStringContainsString('Ana Rojas', $strings);
        $this->assertStringContainsString('Faltas injustificadas', $strings);
        $this->assertStringContainsString('Falta injustificada', $strings);

        // Fila 2 del resumen: programados 4, trabajados 1, 10 h, 2 h extra diurnas, 3 faltas
        $this->assertMatchesRegularExpression('/<c r="E2"[^>]*><v>4(\.0)?<\/v>/', $sheet1);
        $this->assertMatchesRegularExpression('/<c r="F2"[^>]*><v>1(\.0)?<\/v>/', $sheet1);
        $this->assertMatchesRegularExpression('/<c r="G2"[^>]*><v>10(\.0)?<\/v>/', $sheet1);
        $this->assertMatchesRegularExpression('/<c r="K2"[^>]*><v>2(\.0)?<\/v>/', $sheet1);
        $this->assertMatchesRegularExpression('/<c r="N2"[^>]*><v>3(\.0)?<\/v>/', $sheet1);
        // Detalle: 1 día trabajado + 3 faltas (+ encabezado)
        $this->assertSame(5, substr_count($sheet2, '<row '));
    }

    public function test_global_view_export_adds_company_column(): void
    {
        $zip = $this->xlsxFromResponse(
            $this->actingAs($this->makeUser(null, 'super_admin'))->get(route('reports.hours.excel', self::RANGE))->assertOk()
        );

        $strings = $zip->getFromName('xl/sharedStrings.xml');
        $this->assertStringContainsString('Empresa', $strings);
        $this->assertStringContainsString('Acme SAC', $strings);
    }

    public function test_pdf_export_is_a_real_pdf_with_rows(): void
    {
        $response = $this->actingAs($this->makeUser($this->tenant, 'company_admin'))
            ->get(route('reports.hours.pdf', self::RANGE))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('/MediaBox', $pdf);

        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('Ana Rojas', $text);
        $this->assertStringContainsString('Reporte de horas y faltas', $text);
    }

    public function test_monthly_command_stores_excel_and_notifies_admins_with_signed_link(): void
    {
        Storage::fake('private');
        $admin = $this->makeUser($this->tenant, 'company_admin');
        $hr    = $this->makeUser($this->tenant, 'hr_manager');
        $sup   = $this->makeUser($this->tenant, 'supervisor');
        $other = $this->makeUser($this->makeTenant('globex'), 'company_admin');

        $this->artisan('reports:send-monthly')->assertSuccessful();

        // Hoy es 09/10/2026 → el mes anterior es 2026-09
        Storage::disk('private')->assertExists('reports/' . $this->tenant->id . '/horas-y-faltas_2026-09.xlsx');

        $notification = $admin->notifications()->sole();
        $this->assertSame('report.monthly_ready', $notification->data['type']);
        $this->assertSame('2026-09', $notification->data['data']['period']);
        $this->assertSame(1, $hr->notifications()->count());
        $this->assertSame(0, $sup->notifications()->count());

        $url = $notification->data['data']['url'];
        $this->actingAs($admin)->get($url)->assertOk()->assertDownload('horas-y-faltas_2026-09.xlsx');

        // Sin firma, o desde otra empresa: prohibido
        $this->get(route('reports.monthly.download', ['tenant' => $this->tenant->id, 'period' => '2026-09']))->assertForbidden();
        $this->actingAs($other)->get($url)->assertForbidden();

        // La campana genera un enlace firmado nuevo
        $this->actingAs($admin)->post(route('notifications.read', $notification->id))
            ->assertRedirectContains('/reports/monthly/' . $this->tenant->id . '/2026-09');
    }

    public function test_monthly_command_accepts_month_and_tenant_options(): void
    {
        Storage::fake('private');
        $this->makeTenant('globex');

        $this->artisan('reports:send-monthly', ['--month' => '2026-10', '--tenant' => 'acme'])->assertSuccessful();

        Storage::disk('private')->assertExists('reports/' . $this->tenant->id . '/horas-y-faltas_2026-10.xlsx');
        $this->assertCount(1, Storage::disk('private')->allFiles('reports'));

        $this->artisan('reports:send-monthly', ['--month' => '2026-13'])->assertFailed();
    }

    public function test_monthly_report_is_scheduled_on_day_one_at_seven(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'reports:send-monthly'));

        $this->assertNotNull($event);
        $this->assertSame('0 7 1 * *', $event->expression);
    }

    public function test_signed_link_expires(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('reports/' . $this->tenant->id . '/horas-y-faltas_2026-09.xlsx', 'x');
        $admin = $this->makeUser($this->tenant, 'company_admin');

        $url = URL::temporarySignedRoute('reports.monthly.download', now()->addDay(), ['tenant' => $this->tenant->id, 'period' => '2026-09']);
        $this->travel(2)->days();

        $this->actingAs($admin)->get($url)->assertForbidden();
        $this->assertSame(30, MonthlyReportReady::LINK_DAYS);
    }
}
