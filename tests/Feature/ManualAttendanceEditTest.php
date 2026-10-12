<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Corrección manual de marcaciones desde el panel: RRHH a toda la empresa,
 * supervisor solo a su equipo, nunca a sí mismo; horas recalculadas con las
 * reglas de la app y antes/después en la bitácora.
 */
class ManualAttendanceEditTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private Branch $branch;
    private User $hr;
    private User $supervisor;
    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-12 12:00', 'America/Lima'));

        $this->acme       = $this->makeTenant('acme');
        $this->hr         = $this->makeUser($this->acme, 'hr_manager');
        $this->supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->branch     = $this->makeBranch($this->acme, ['supervisor_id' => $this->supervisor->id]);
        $this->supervisor->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->emp = $this->makeUser($this->acme, 'employee', ['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
    }

    private function url(User $u, string $date): string
    {
        return route('attendance.manual.edit', ['id' => $u->id, 'date' => $date]);
    }

    public function test_hr_creates_a_missing_day_with_hours_recalculated_and_audited(): void
    {
        $this->actingAs($this->hr)->get($this->url($this->emp, '2026-10-09'))->assertOk()
            ->assertSee('Corregir asistencia')->assertSee('No hay marcación este día');

        $this->actingAs($this->hr)->put($this->url($this->emp, '2026-10-09'), [
            'clock_in' => '08:00', 'clock_out' => '18:45', 'reason' => 'Olvidó marcar; confirmado con su jefe.',
        ])->assertRedirect(route('attendance.calendar.person', ['id' => $this->emp->id, 'month' => '2026-10']))->assertSessionHas('success');

        $a = Attendance::where('user_id', $this->emp->id)->sole();
        // 10 h 45 de permanencia - 45 min de descanso fijo = 10 h; 2 h extra por aprobar
        $this->assertSame(['2026-10-09 08:00', '2026-10-09 18:45'], [$a->clock_in_at->format('Y-m-d H:i'), $a->clock_out_at->format('Y-m-d H:i')]);
        $this->assertEquals([10.0, 2.0], [(float) $a->worked_hours, (float) $a->overtime_hours]);
        $this->assertSame([Attendance::OVERTIME_PENDING, 'present', $this->hr->id], [$a->overtime_status, $a->status, $a->manual_edited_by]);

        $log = AuditLog::where('action', 'attendance.manual_edit')->sole();
        $this->assertSame('Olvidó marcar; confirmado con su jefe.', $log->meta['context']['reason']);
        $this->assertSame('2026-10-09 18:45', collect($log->meta['changes'])->firstWhere('field', 'clock_out_at')['new']);

        // Calendario de la persona: lápiz y marca "Corregida"
        $this->actingAs($this->hr)->get(route('attendance.calendar.person', ['id' => $this->emp->id, 'month' => '2026-10']))
            ->assertOk()->assertSee('Corregida')->assertSee($this->url($this->emp, '2026-10-09'), false);
        $this->actingAs($this->hr)->get(route('attendance.calendar.day', ['date' => '2026-10-09']))
            ->assertOk()->assertSee($this->url($this->emp, '2026-10-09'), false);
    }

    public function test_overnight_exit_and_marking_an_absence(): void
    {
        $this->actingAs($this->hr)->put($this->url($this->emp, '2026-10-10'), [
            'clock_in' => '22:00', 'clock_out' => '06:00', 'break_minutes' => 60, 'reason' => 'Turno noche sin señal.',
        ])->assertSessionHasNoErrors();
        $a = Attendance::where('user_id', $this->emp->id)->sole();
        $this->assertSame('2026-10-11 06:00', $a->clock_out_at->format('Y-m-d H:i'));
        $this->assertEquals(7.0, (float) $a->worked_hours, '8 h - 60 min marcados');

        // Dejar la entrada vacía: no vino
        $this->actingAs($this->hr)->put($this->url($this->emp, '2026-10-10'), ['clock_in' => '', 'clock_out' => '', 'reason' => 'No vino, se registró por error.'])
            ->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame(['absent', null, 0.0], [$a->status, $a->clock_in_at, (float) $a->worked_hours]);
        $this->assertSame(2, AuditLog::where('action', 'attendance.manual_edit')->count());
    }

    public function test_changed_overtime_goes_back_to_pending(): void
    {
        $a = Attendance::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'branch_id' => $this->branch->id,
            'work_date' => '2026-10-08', 'status' => 'present', 'clock_in_at' => '2026-10-08 08:00:00', 'clock_out_at' => '2026-10-08 18:45:00',
            'worked_hours' => 10, 'overtime_hours' => 2, 'overtime_status' => 'approved', 'overtime_approved_hours' => 2]);

        $this->actingAs($this->hr)->put($this->url($this->emp, '2026-10-08'), ['clock_in' => '08:00', 'clock_out' => '17:45', 'reason' => 'Salió antes, corregido.'])
            ->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertEquals(1.0, (float) $a->overtime_hours);
        $this->assertSame([Attendance::OVERTIME_PENDING, null], [$a->overtime_status, $a->overtime_approved_hours]);
    }

    public function test_validation_and_who_can_edit(): void
    {
        // Motivo obligatorio, formato 24 h, sin días futuros
        $this->actingAs($this->hr)->put($this->url($this->emp, '2026-10-09'), ['clock_in' => '8 am', 'reason' => ''])
            ->assertSessionHasErrors(['clock_in', 'reason']);
        $this->actingAs($this->hr)->put($this->url($this->emp, '2026-10-20'), ['clock_in' => '08:00', 'clock_out' => '17:00', 'reason' => 'Prueba de futuro.'])
            ->assertSessionHasErrors('date');

        // Supervisor: su equipo sí, a sí mismo no; fuera de su equipo, no
        $this->actingAs($this->supervisor)->get($this->url($this->emp, '2026-10-09'))->assertOk();
        $this->actingAs($this->supervisor)->get($this->url($this->supervisor, '2026-10-09'))->assertForbidden();
        $other = $this->makeUser($this->acme, 'employee');
        $other->branches()->attach($this->makeBranch($this->acme)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->actingAs($this->supervisor)->get($this->url($other, '2026-10-09'))->assertForbidden();

        // Colaborador: no entra al panel; otra empresa: no existe
        $this->actingAs($this->emp)->get($this->url($other, '2026-10-09'))->assertRedirect();
        $this->assertFalse($this->emp->can('attendance.edit'));
        $globex = $this->makeTenant('globex');
        $stranger = $this->makeUser($globex, 'employee');
        $this->actingAs($this->hr)->get($this->url($stranger, '2026-10-09'))->assertNotFound();
        $this->assertSame(0, Attendance::count());
    }
}
