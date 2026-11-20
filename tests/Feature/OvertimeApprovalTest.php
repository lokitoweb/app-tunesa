<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Payroll\Services\PayrollExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Fase 2 · aprobación de horas extra: quedan por aprobar al cerrar la
 * jornada, la jefatura las aprueba (todas o menos) o rechaza, y a planilla
 * van solo las aprobadas.
 */
class OvertimeApprovalTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private User $supervisor;
    private User $emp;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');

        $this->acme       = $this->makeTenant('acme');
        $this->supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->branch     = $this->makeBranch($this->acme, ['supervisor_id' => $this->supervisor->id]);
        $this->supervisor->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->emp        = $this->employee('Rosa', 'Quispe', $this->branch);
    }

    private function employee(string $first, string $last, Branch $branch): User
    {
        $user = $this->makeUser($this->acme, 'employee', ['first_name' => $first, 'last_name' => $last]);
        $user->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        return $user;
    }

    /** Jornada real por la API: entrada 08:00 y $minutes trabajados más el descanso fijo de 45 min (sin turno: 8 h diarias) */
    private function workDay(User $user, string $date, int $minutes): Attendance
    {
        $gps = ['latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12, 'device_id' => 'test-device', 'device_os' => 'android'];

        $this->travelTo(Carbon::parse("{$date} 08:00:00", 'America/Lima'));
        $this->actingIn($user, $this->acme)->post('/api/attendance/clock-in', $gps + ['photo' => UploadedFile::fake()->image('in.jpg')])->assertCreated();
        $this->travel($minutes + 45)->minutes();
        $this->actingIn($user, $this->acme)->post('/api/attendance/clock-out', $gps + ['photo' => UploadedFile::fake()->image('out.jpg')])->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return Attendance::withoutGlobalScopes()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function test_overtime_is_pending_from_fifteen_minutes(): void
    {
        $long  = $this->workDay($this->emp, '2026-10-05', 10 * 60 + 30);
        $short = $this->workDay($this->emp, '2026-10-06', 8 * 60 + 10);

        $this->assertEquals(2.5, (float) $long->overtime_hours);
        $this->assertSame('pending', $long->overtime_status);
        $history = collect($this->actingIn($this->emp, $this->acme)->getJson('/api/attendance/history')->assertOk()->json('data'));
        $this->assertSame(['pending', null], [
            $history->firstWhere('id', $long->id)['summary']['overtime_status'],
            $history->firstWhere('id', $short->id)['summary']['overtime_status'],
        ]);

        $this->assertGreaterThan(0, (float) $short->overtime_hours);
        $this->assertNull($short->overtime_status, 'unos minutos de más no se piden a aprobación');
    }

    public function test_supervisor_approves_part_and_payroll_counts_only_approved_hours(): void
    {
        $day1 = $this->workDay($this->emp, '2026-10-05', 10 * 60 + 30); // 2.5 h extra
        $day2 = $this->workDay($this->emp, '2026-10-06', 9 * 60 + 30);  // 1.5 h extra, queda por aprobar

        $this->actingAs($this->supervisor)->get(route('approvals.index', ['tab' => 'overtime']))->assertOk()
            ->assertSee('Horas extra')->assertSee('Rosa Quispe')->assertSee('2 h 30 min');

        $this->actingAs($this->supervisor)->post(route('approvals.overtime.review', $day1->id), ['decision' => 'approved', 'hours' => 2])
            ->assertRedirect()->assertSessionHas('success');

        $day1->refresh();
        $this->assertSame(['approved', 2.0, $this->supervisor->id], [$day1->overtime_status, (float) $day1->overtime_approved_hours, $day1->overtime_reviewed_by]);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'attendance.overtime_approved')->count());
        $this->assertSame('overtime.approved', $this->emp->notifications()->first()->data['type']);
        $this->assertStringContainsString('2 h extra del 05/10/2026', $this->emp->notifications()->first()->data['body']);

        // No se puede volver a revisar
        $this->actingAs($this->supervisor)->post(route('approvals.overtime.review', $day1->id), ['decision' => 'rejected', 'notes' => 'x'])
            ->assertSessionHasErrors('decision');

        // Planilla: 2 h aprobadas; 1.5 h por aprobar aparte
        $row = app(PayrollExportService::class)->rows(2026, 10)->firstWhere('full_name', $this->emp->full_name);
        $this->assertSame([2.0, 1.5], [$row['overtime_hours'], $row['overtime_pending_hours']]);
        // A pagar: 8 h base por día + solo las 2 h aprobadas (las 0.5 rechazadas y las 1.5 pendientes no)
        $this->assertSame(18.0, $row['payable_hours']);

        // Calendario: la marca y los totales
        $cells = collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-10'))->items())
            ->firstWhere('user_id', $this->emp->id);
        $this->assertSame(['approved', 2.0], [$cells['cells']['2026-10-05']['overtime_status'], $cells['cells']['2026-10-05']['overtime_hours']]);
        $this->assertSame(['pending', 1.5], [$cells['cells']['2026-10-06']['overtime_status'], $cells['cells']['2026-10-06']['overtime_hours']]);
        $this->assertSame([2.0, 1.5], [$cells['totals']['overtime_approved'], $cells['totals']['overtime_pending']]);
    }

    public function test_rejection_needs_a_reason_and_nobody_reviews_their_own(): void
    {
        $day = $this->workDay($this->emp, '2026-10-05', 10 * 60);
        $own = $this->workDay($this->supervisor, '2026-10-05', 10 * 60);

        $this->actingAs($this->supervisor)->post(route('approvals.overtime.review', $day->id), ['decision' => 'rejected'])
            ->assertSessionHasErrors('notes');
        $this->actingAs($this->supervisor)->post(route('approvals.overtime.review', $day->id), ['decision' => 'rejected', 'notes' => 'No autorizadas'])
            ->assertSessionHas('success');
        $this->assertSame(['rejected', null], [$day->fresh()->overtime_status, $day->fresh()->overtime_approved_hours]);
        $this->assertSame('overtime.rejected', $this->emp->notifications()->first()->data['type']);

        $hr = $this->makeUser($this->acme, 'hr_manager');
        $this->actingAs($hr)->get(route('approvals.index', ['tab' => 'overtime']))->assertOk()->assertSee($this->supervisor->full_name);
        // Su propia jornada no está en su bandeja; RRHH sí la ve, pero tampoco aprobaría las suyas
        $this->actingAs($this->supervisor)->post(route('approvals.overtime.review', $own->id), ['decision' => 'approved'])->assertNotFound();
        $hrDay = Attendance::create(['tenant_id' => $this->acme->id, 'user_id' => $hr->id, 'branch_id' => $this->branch->id, 'work_date' => '2026-10-06', 'status' => 'present',
            'clock_in_at' => '2026-10-06 08:00:00', 'clock_out_at' => '2026-10-06 18:00:00', 'worked_hours' => 10, 'overtime_hours' => 2, 'overtime_status' => 'pending']);
        $this->actingAs($hr)->post(route('approvals.overtime.review', $hrDay->id), ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->actingAs($hr)->post(route('approvals.overtime.review', $own->id), ['decision' => 'approved'])->assertSessionHas('success');
        $this->assertSame(2.0, (float) $own->fresh()->overtime_approved_hours, 'sin horas indicadas se aprueban todas');
    }

    public function test_bulk_rejection_needs_a_reason_and_rejected_hours_never_add_up_anywhere(): void
    {
        $day1 = $this->workDay($this->emp, '2026-10-05', 10 * 60);       // 8 h de jornada + 2 h extra
        $day2 = $this->workDay($this->emp, '2026-10-06', 9 * 60 + 30);   // 8 h de jornada + 1 h 30 min extra

        // Rechazar varias exige el motivo
        $this->actingAs($this->supervisor)->post(route('approvals.overtime.bulk'), ['ids' => [$day1->id, $day2->id], 'decision' => 'rejected'])
            ->assertSessionHasErrors('notes');
        $this->assertSame('pending', $day1->fresh()->overtime_status);

        $this->actingAs($this->supervisor)->post(route('approvals.overtime.bulk'), ['ids' => [$day1->id, $day2->id], 'decision' => 'rejected', 'notes' => 'No autorizadas'])
            ->assertSessionHas('success', 'Se rechazaron 2 jornadas con horas extra.');
        foreach ([$day1, $day2] as $d) {
            $d->refresh();
            $this->assertSame(['rejected', 'No autorizadas', null], [$d->overtime_status, $d->overtime_review_notes, $d->overtime_approved_hours]);
            $this->assertSame(8.0, $d->regularHours(), 'la jornada no incluye las horas extra');
            $this->assertSame(8.0, $d->payableHours(), 'a pagar: solo la jornada');
        }
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('action', 'attendance.overtime_rejected')->count());

        // Planilla: 16 h a pagar (8 + 8), 0 extra aprobadas ni por aprobar
        $row = app(PayrollExportService::class)->rows(2026, 10)->firstWhere('full_name', $this->emp->full_name);
        $this->assertSame([16.0, 0.0, 0.0], [$row['payable_hours'], $row['overtime_hours'], $row['overtime_pending_hours']]);

        // Calendario: horas de jornada 16 h, sin extras aprobadas ni por aprobar
        $totals = collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-10'))->items())
            ->firstWhere('user_id', $this->emp->id)['totals'];
        $this->assertSame([16.0, 0.0, 0.0], [$totals['worked_hours'], $totals['overtime_approved'], $totals['overtime_pending']]);

        // Excel de Marcaciones y GPS: horas:minutos:segundos y el rechazo a la vista
        $hr = $this->makeUser($this->acme, 'hr_manager');
        $csv = $this->actingAs($hr)->get(route('reports.export.excel', ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']))->streamedContent();
        $rows = collect(explode("\n", trim($csv)))->map(fn ($l) => str_getcsv($l, ';'));
        $head = array_map(fn ($h) => trim($h, "\xEF\xBB\xBF"), $rows->first());
        $line = $rows->first(fn ($r) => ($r[1] ?? null) === '2026-10-05');
        $col = fn (string $name) => $line[array_search($name, $head, true)];
        $this->assertSame(['00:00:00', '08:00:00', '02:00:00', 'Rechazadas', '00:00:00'],
            [$col('Tardanza'), $col('Horas de jornada'), $col('Horas extra'), $col('Estado horas extra'), $col('Horas extra aprobadas')]);
    }

    public function test_supervisor_only_sees_and_bulk_approves_their_team(): void
    {
        $other = $this->employee('Ajeno', 'Lima', $this->makeBranch($this->acme, ['name' => 'Lima', 'supervisor_id' => $this->makeUser($this->acme, 'supervisor')->id]));
        $mine1 = $this->workDay($this->emp, '2026-10-05', 10 * 60);
        $mine2 = $this->workDay($this->emp, '2026-10-06', 9 * 60);
        $theirs = $this->workDay($other, '2026-10-05', 10 * 60);

        $this->actingAs($this->supervisor)->get(route('approvals.index', ['tab' => 'overtime']))->assertOk()
            ->assertSee('Rosa Quispe')->assertDontSee('Ajeno Lima');
        $this->actingAs($this->supervisor)->post(route('approvals.overtime.review', $theirs->id), ['decision' => 'approved'])->assertNotFound();

        $this->actingAs($this->supervisor)->post(route('approvals.overtime.bulk'), ['ids' => [$mine1->id, $mine2->id, $theirs->id]])
            ->assertSessionHas('success', 'Se aprobaron 2 jornadas con horas extra.');

        $this->assertSame(['approved', 'approved', 'pending'], [$mine1->fresh()->overtime_status, $mine2->fresh()->overtime_status, $theirs->fresh()->overtime_status]);
    }
}
