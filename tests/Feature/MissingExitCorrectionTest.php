<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * HQ-03 (QA Ronda 2): completar la salida de una jornada con entrada y sin salida.
 * Un control positivo (RR. HH. y supervisor del equipo) y un caso por cada bloqueo
 * de ManualAttendanceEdit: sin permiso attendance.edit, corregirse a sí mismo,
 * supervisor que no supervisa a la persona y fecha futura.
 */
class MissingExitCorrectionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const OPEN_DAY = '2026-10-09';
    private const NO_PERMISSION = 'Tu perfil no tiene permiso para ver esta sección.';

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
        $this->emp = $this->makeUser($this->acme, 'employee');
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
    }

    /** Jornada como la deja clockIn: entrada, sin salida, estado de la entrada */
    private function openDay(User $u, string $date = self::OPEN_DAY, string $in = '14:00'): Attendance
    {
        return Attendance::create(['tenant_id' => $this->acme->id, 'user_id' => $u->id, 'branch_id' => $this->branch->id,
            'work_date' => $date, 'status' => 'present', 'clock_in_at' => "{$date} {$in}:00"]);
    }

    private function url(User $u, string $date = self::OPEN_DAY): string
    {
        return route('attendance.manual.edit', ['id' => $u->id, 'date' => $date]);
    }

    private function fixExit(User $editor, User $u, string $date = self::OPEN_DAY)
    {
        return $this->actingAs($editor)->put($this->url($u, $date), [
            'clock_in' => '14:00', 'clock_out' => '21:00', 'reason' => 'Olvidó marcar la salida.',
        ]);
    }

    private function assertStillOpen(Attendance $a): void
    {
        $this->assertNull($a->fresh()->clock_out_at);
        $this->assertSame(0, AuditLog::where('action', 'attendance.manual_edit')->count());
    }

    public function test_control_rrhh_and_team_supervisor_complete_the_missing_exit(): void
    {
        $a = $this->openDay($this->emp);

        // La celda del día cerrado sale "incomplete" y el formulario trae la entrada ya escrita
        $this->actingAs($this->hr)->get($this->url($this->emp))->assertOk()->assertSee('value="14:00"', false);
        $this->fixExit($this->hr, $this->emp)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('2026-10-09 21:00', $a->fresh()->clock_out_at->format('Y-m-d H:i'));

        $b = $this->openDay($this->emp, '2026-10-08');
        $this->fixExit($this->supervisor, $this->emp, '2026-10-08')->assertSessionHasNoErrors();
        $this->assertNotNull($b->fresh()->clock_out_at);
        $this->assertSame(2, AuditLog::where('action', 'attendance.manual_edit')->count());
    }

    public function test_block_1_without_attendance_edit_permission(): void
    {
        // Contabilidad ve toda la asistencia (view_all) pero no tiene attendance.edit
        $accountant = $this->makeUser($this->acme, 'accountant');
        $a = $this->openDay($this->emp);

        $this->assertTrue($accountant->can('attendance.view_all'));
        $this->assertFalse($accountant->can('attendance.edit'));
        $this->actingAs($accountant)->get(route('attendance.calendar.person', ['id' => $this->emp->id, 'month' => '2026-10']))
            ->assertOk()->assertDontSee($this->url($this->emp), false);
        $this->actingAs($accountant)->get($this->url($this->emp))->assertForbidden()->assertSee(self::NO_PERMISSION);
        $this->fixExit($accountant, $this->emp)->assertForbidden();
        $this->assertStillOpen($a);
    }

    public function test_block_1_manager_grant_exists_only_in_the_seeder(): void
    {
        // Gerencia recibió attendance.edit en el seeder (64c0a4c) sin migración que lo aplique;
        // deploy.sh solo corre migraciones. Base desplegada = rol sin el permiso.
        $manager = $this->makeUser($this->acme, 'manager');
        $this->assertTrue(in_array('attendance.edit', RolesAndPermissionsSeeder::expand(RolesAndPermissionsSeeder::ROLES['manager'][2]), true));
        $this->assertSame(1, Permission::where('slug', 'attendance.edit')->count());

        Role::whereNull('tenant_id')->where('slug', 'manager')->sole()
            ->permissions()->detach(Permission::where('slug', 'attendance.edit')->value('id'));
        $manager = User::find($manager->id);
        $a = $this->openDay($this->emp);

        $this->actingAs($manager)->get($this->url($this->emp))->assertForbidden()->assertSee(self::NO_PERMISSION);
        $this->fixExit($manager, $this->emp)->assertForbidden();
        $this->assertStillOpen($a);
    }

    public function test_migration_restores_the_missing_grants_and_rolls_back_only_what_it_added(): void
    {
        // Base desplegada solo con migraciones: Gerencia sin attendance.edit y sin el permiso de fuera de turno
        $edit = Permission::where('slug', 'attendance.edit')->sole();
        $managerRole = Role::whereNull('tenant_id')->where('slug', 'manager')->sole();
        $managerRole->permissions()->detach($edit->id);
        Permission::where('slug', 'attendance.authorize_offshift')->sole()->delete();
        $before = DB::table('permission_role')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($r) => (array) $r)->all();

        $migration = require database_path('../app/Modules/Core/Database/Migrations/2026_11_01_000001_sync_attendance_correction_permissions.php');
        $migration->up();

        $manager = $this->makeUser($this->acme, 'manager');
        $a = $this->openDay($this->emp);
        $this->fixExit($manager, $this->emp)->assertSessionHasNoErrors();
        $this->assertNotNull($a->fresh()->clock_out_at);
        $this->assertTrue(User::find($this->hr->id)->can('attendance.authorize_offshift'));
        $this->assertTrue(User::find($manager->id)->can('attendance.authorize_offshift'));
        // Regla vigente 11: el supervisor también autoriza (a su equipo)
        $this->assertTrue(User::find($this->supervisor->id)->can('attendance.authorize_offshift'));

        // Rollback: quita solo lo que agregó; lo que ya existía (attendance.edit de RR. HH.) queda
        $migration->down();
        $this->assertSame($before, DB::table('permission_role')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame(0, Permission::where('slug', 'attendance.authorize_offshift')->count());
        $this->assertTrue(User::find($this->hr->id)->can('attendance.edit'));
    }

    public function test_block_2_nobody_corrects_their_own_day(): void
    {
        $a = $this->openDay($this->supervisor);

        $this->actingAs($this->supervisor)->get($this->url($this->supervisor))->assertForbidden()->assertSee(self::NO_PERMISSION);
        $this->fixExit($this->supervisor, $this->supervisor)->assertForbidden();
        $this->assertStillOpen($a);

        // Lo corrige RR. HH.
        $this->fixExit($this->hr, $this->supervisor)->assertSessionHasNoErrors();
        $this->assertNotNull($a->fresh()->clock_out_at);
    }

    public function test_block_3_supervisor_outside_their_team(): void
    {
        $other = $this->makeUser($this->acme, 'supervisor');
        $this->makeBranch($this->acme, ['supervisor_id' => $other->id]);
        $a = $this->openDay($this->emp);

        $this->actingAs($other)->get($this->url($this->emp))->assertForbidden()->assertSee(self::NO_PERMISSION);
        $this->fixExit($other, $this->emp)->assertForbidden();
        $this->assertStillOpen($a);
    }

    public function test_block_4_future_date_never_applies_to_a_missing_exit(): void
    {
        // Hoy (aún abierta) sí se corrige; la salida de un día pasado nunca es futura
        $today = $this->openDay($this->emp, '2026-10-12', '08:00');
        $this->actingAs($this->hr)->put($this->url($this->emp, '2026-10-12'), [
            'clock_in' => '08:00', 'clock_out' => '11:30', 'reason' => 'Salió por una cita médica.',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($today->fresh()->clock_out_at);

        // Mañana: no puede tener entrada, el bloqueo solo rechaza días por venir
        $this->fixExit($this->hr, $this->emp, '2026-10-13')
            ->assertSessionHasErrors(['date' => 'No se puede registrar asistencia de días que aún no llegan.']);
        $this->assertSame(0, Attendance::where('work_date', '2026-10-13')->count());
    }

    public function test_only_the_exit_with_the_entry_field_cleared(): void
    {
        $a = $this->openDay($this->emp);

        // Si borra la entrada y deja solo la salida
        $this->actingAs($this->hr)->put($this->url($this->emp), ['clock_in' => '', 'clock_out' => '21:00', 'reason' => 'Olvidó marcar la salida.'])
            ->assertSessionHasErrors(['clock_in' => 'Indica la hora de entrada.']);
        $this->assertStillOpen($a);
    }
}
