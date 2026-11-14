<?php

namespace Tests\Feature;

use App\Modules\Attendance\Notifications\AttendanceOutsideGeofence;
use App\Modules\Attendance\Services\Attendance\AttendanceAlertNotifier;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Varios supervisores por sede (Sedes › Supervisores / Encargados): selección múltiple, sin
 * supervisor = la ven todos los supervisores, y todo lo que depende de quién supervisa
 * (visibilidad, avisos, aprobaciones, bitácora, API anterior) usa la misma lista.
 */
class BranchSupervisorsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function form(Branch $branch, array $extra): array
    {
        return array_merge([
            'name' => $branch->name, 'address' => $branch->address ?: 'Av. Siempre Viva 123', 'latitude' => -12.09418, 'longitude' => -77.0356,
            'allowed_radius_meters' => 50, 'branch_type' => 'agency', 'is_active' => 1,
        ], $extra);
    }

    public function test_the_branch_form_saves_several_supervisors_clears_them_and_never_drops_them_on_a_partial_save(): void
    {
        $this->seedRoles();
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $admin = $this->makeUser($acme, 'company_admin');
        $ana   = $this->makeUser($acme, 'supervisor', ['first_name' => 'Ana']);
        $juan  = $this->makeUser($acme, 'supervisor', ['first_name' => 'Juan']);
        $off   = $this->makeUser($acme, 'supervisor', ['first_name' => 'Inactivo', 'status' => 'inactive']);
        $alien = $this->makeUser($other, 'supervisor');
        $clerk = $this->makeUser($acme, 'employee');
        $branch = $this->makeBranch($acme, ['name' => 'Ag. Huamachuco', 'address' => 'Jr. Suárez 721']);

        // Dos supervisores a la vez (mañana y tarde)
        $this->actingAs($admin)->put("/branches/{$branch->id}", $this->form($branch, ['supervisors_sent' => 1, 'supervisor_ids' => [$juan->id, $ana->id]]))
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$ana->id, $juan->id], $branch->fresh()->supervisors->pluck('id')->all());
        $this->assertContains($branch->fresh()->supervisor_id, [$ana->id, $juan->id], 'supervisor_id queda como el primero (compatibilidad)');
        $log = AuditLog::where('action', 'branch.supervisors_changed')->sole();
        $this->assertSame('Sin supervisor', $log->meta['context']['before'] ?? $log->meta['before'] ?? null);

        // El listado los muestra a los dos
        $this->get('/branches')->assertOk()->assertSee('Supervisores:')->assertSee('Ana')->assertSee('Juan');

        // Guardado sin la lista (otra pantalla o integración): no se borran
        $this->put("/branches/{$branch->id}", $this->form($branch, ['name' => 'Ag. Huamachuco Centro']))->assertSessionHasNoErrors();
        $this->assertCount(2, $branch->fresh()->supervisors);

        // Nadie marcado: queda sin supervisor (la ven todos)
        $this->put("/branches/{$branch->id}", $this->form($branch, ['supervisors_sent' => 1]))->assertSessionHasNoErrors();
        $this->assertCount(0, $branch->fresh()->supervisors);
        $this->assertNull($branch->fresh()->supervisor_id);

        // Solo usuarios activos de esta empresa con rol que supervisa
        foreach ([$alien, $off, $clerk] as $wrong) {
            $this->put("/branches/{$branch->id}", $this->form($branch, ['supervisors_sent' => 1, 'supervisor_ids' => [$ana->id, $wrong->id]]))
                ->assertSessionHasErrors('supervisor_ids.1');
        }
        $this->assertCount(0, $branch->fresh()->supervisors);

        // Al crear también
        $this->post('/branches', $this->form(new Branch(['name' => 'Ag. Nueva']), ['supervisors_sent' => 1, 'supervisor_ids' => [$ana->id]]))->assertSessionHasNoErrors();
        $this->assertSame([$ana->id], Branch::where('name', 'Ag. Nueva')->sole()->supervisors->pluck('id')->all());
    }

    public function test_each_supervisor_sees_their_branches_and_the_unassigned_ones_never_another_supervisors(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        [$ana, $juan, $pedro] = [$this->makeUser($acme, 'supervisor'), $this->makeUser($acme, 'supervisor'), $this->makeUser($acme, 'supervisor')];
        $shared = $this->makeBranch($acme, ['name' => 'Compartida']);
        $pedros = $this->makeBranch($acme, ['name' => 'De Pedro']);
        $free   = $this->makeBranch($acme, ['name' => 'Sin supervisor']);
        $shared->syncSupervisors([$ana->id, $juan->id]);
        $pedros->syncSupervisors([$pedro->id]);
        $worker = fn (Branch $b) => tap($this->makeUser($acme, 'employee'), fn (User $u) => $u->branches()->attach($b->id, ['is_primary' => true]));
        [$inShared, $inPedros, $inFree] = [$worker($shared), $worker($pedros), $worker($free)];
        app(TenantContext::class)->set($acme);

        $branches = fn (User $u) => Branch::visibleTo($u)->orderBy('name')->pluck('name')->all();
        $team = fn (User $u) => User::visibleTo($u)->whereIn('id', [$inShared->id, $inPedros->id, $inFree->id])->pluck('id')->sort()->values()->all();

        $this->assertSame(['Compartida', 'Sin supervisor'], $branches($ana));
        $this->assertSame(['Compartida', 'Sin supervisor'], $branches($juan));
        $this->assertSame(['De Pedro', 'Sin supervisor'], $branches($pedro));
        $this->assertSame(collect([$inShared->id, $inFree->id])->sort()->values()->all(), $team($ana));
        $this->assertSame(collect([$inPedros->id, $inFree->id])->sort()->values()->all(), $team($pedro));

        // Un colaborador sin permiso de equipo no gana nada por las sedes sin supervisor
        $this->assertSame([], Branch::query()->supervisedBy($inFree)->pluck('name')->all());

        // Aprobaciones: los dos supervisores de la sede revisan; el texto los nombra a ambos
        $leave = LeaveRequest::create(['tenant_id' => $acme->id, 'user_id' => $inShared->id, 'type' => 'permission',
            'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'days' => 1, 'reason' => 'Trámite', 'status' => 'pending']);
        $this->assertTrue(LeaveRequest::approvableBy($ana)->whereKey($leave->id)->exists());
        $this->assertTrue(LeaveRequest::approvableBy($juan)->whereKey($leave->id)->exists());
        $this->assertFalse(LeaveRequest::approvableBy($pedro)->whereKey($leave->id)->exists());
        $label = $leave->fresh()->load('employee.branches.supervisors')->reviewersLabel();
        $this->assertStringContainsString('(supervisores de sede)', $label);
        $this->assertStringContainsString($ana->full_name, $label);
        $this->assertStringContainsString($juan->full_name, $label);
    }

    public function test_attendance_alerts_reach_every_supervisor_of_the_branch(): void
    {
        $this->seedRoles();
        Notification::fake();
        $acme = $this->makeTenant('acme');
        [$ana, $juan, $pedro] = [$this->makeUser($acme, 'supervisor'), $this->makeUser($acme, 'supervisor'), $this->makeUser($acme, 'supervisor')];
        $off = $this->makeUser($acme, 'supervisor', ['status' => 'inactive']);
        $branch = $this->makeBranch($acme);
        $branch->syncSupervisors([$ana->id, $juan->id, $off->id]);
        $emp = $this->makeUser($acme, 'employee');

        app(AttendanceAlertNotifier::class)->outsideGeofence($emp, ['branch_id' => $branch->id, 'distance_meters' => 300], 'clock_in', false);

        Notification::assertSentTo($ana, AttendanceOutsideGeofence::class);
        Notification::assertSentTo($juan, AttendanceOutsideGeofence::class);
        Notification::assertNotSentTo($pedro, AttendanceOutsideGeofence::class);
        Notification::assertNotSentTo($off, AttendanceOutsideGeofence::class);
    }

    public function test_code_that_still_sets_a_single_supervisor_keeps_working(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        [$ana, $juan, $pedro] = [$this->makeUser($acme, 'supervisor'), $this->makeUser($acme, 'supervisor'), $this->makeUser($acme, 'supervisor')];

        // Crear con supervisor_id (API anterior) lo deja en la lista
        $branch = $this->makeBranch($acme, ['supervisor_id' => $ana->id]);
        $this->assertSame([$ana->id], $branch->supervisors()->pluck('users.id')->all());

        // Con varios, cambiar supervisor_id reemplaza solo a ese y conserva a los demás
        $branch->syncSupervisors([$ana->id, $juan->id]);
        $branch->fresh()->update(['supervisor_id' => $pedro->id]);
        $this->assertEqualsCanonicalizing([$juan->id, $pedro->id], $branch->supervisors()->pluck('users.id')->all());

        // supervisor_id en null quita solo a ese
        $branch->fresh()->update(['supervisor_id' => null]);
        $this->assertSame([$juan->id], $branch->supervisors()->pluck('users.id')->all());
    }
}
