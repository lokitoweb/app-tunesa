<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Feriados por empresa y su efecto en el control de faltas.
 */
class HolidayTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_hr_creates_lists_and_deletes_holidays_per_company(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $hr    = $this->makeUser($acme, 'hr_manager');
        Holiday::create(['tenant_id' => $other->id, 'date' => '2026-12-31', 'name' => 'Cierre de Other']);

        $this->actingAs($hr)->get('/holidays?year=2026')->assertOk()->assertSee('Cargar feriados nacionales de Perú 2026')
            ->assertDontSee('Cierre de Other');

        $this->post('/holidays', ['date' => '2026-11-15', 'name' => 'Aniversario Acme'])
            ->assertRedirect(route('holidays.index', ['year' => 2026]))->assertSessionHasNoErrors();
        $this->post('/holidays', ['date' => '2026-11-15', 'name' => 'Repetido'])->assertSessionHasErrors('date');
        // No se duplicó la fecha
        $this->assertSame(1, Holiday::withoutGlobalScopes()->where('date', '2026-11-15')->count());

        $holiday = Holiday::where('tenant_id', $acme->id)->sole();
        $this->assertSame($acme->id, $holiday->tenant_id);
        $this->get('/holidays?year=2026')->assertSee('Aniversario Acme')->assertSee('15/11/2026');

        // No puede borrar el de otra empresa
        $foreign = Holiday::withoutGlobalScopes()->where('tenant_id', $other->id)->sole();
        $this->delete("/holidays/{$foreign->id}")->assertNotFound();

        $this->delete("/holidays/{$holiday->id}")->assertSessionHas('success');
        $this->assertSame(0, Holiday::where('tenant_id', $acme->id)->count());

        $this->get('/dashboard')->assertSee(route('holidays.index'));
    }

    public function test_loads_peru_national_holidays_idempotently(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');

        $this->actingAs($hr)->post('/holidays', ['date' => '2026-07-28', 'name' => 'Fiestas Patrias (propio)']);
        $this->post('/holidays/load-peru', ['year' => 2026])->assertSessionHas('success');

        $dates = Holiday::orderBy('date')->get()->map(fn ($h) => $h->date->toDateString())->all();
        $this->assertCount(16, $dates);
        // Semana Santa 2026: Pascua el 5 de abril
        $this->assertContains('2026-04-02', $dates);
        $this->assertContains('2026-04-03', $dates);
        $this->assertContains('2026-12-09', $dates);

        $this->post('/holidays/load-peru', ['year' => 2026])->assertSessionHas('info');
        $this->assertSame(16, Holiday::count());
        $this->assertSame('2025-04-20', Holiday::easterSunday(2025)->toDateString());
    }

    public function test_mark_absences_skips_company_holidays(): void
    {
        $acme   = $this->makeTenant('acme');
        $other  = $this->makeTenant('other');
        $shiftA = Shift::create(['tenant_id' => $acme->id, 'name' => 'L-V', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1, 2, 3, 4, 5]]);
        $shiftO = Shift::create(['tenant_id' => $other->id, 'name' => 'L-V', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1, 2, 3, 4, 5]]);
        $empA   = $this->makeUser($acme);
        $empO   = $this->makeUser($other);
        EmployeeShift::create(['tenant_id' => $acme->id, 'user_id' => $empA->id, 'shift_id' => $shiftA->id,
            'branch_id' => $this->makeBranch($acme)->id, 'effective_from' => '2026-01-01', 'is_active' => true]);
        EmployeeShift::create(['tenant_id' => $other->id, 'user_id' => $empO->id, 'shift_id' => $shiftO->id,
            'branch_id' => $this->makeBranch($other)->id, 'effective_from' => '2026-01-01', 'is_active' => true]);

        // 2026-10-08 (jueves): feriado solo para Acme
        Holiday::create(['tenant_id' => $acme->id, 'date' => '2026-10-08', 'name' => 'Combate de Angamos']);

        $this->artisan('attendance:mark-absences', ['--date' => '2026-10-08'])->assertSuccessful();

        $this->assertSame(0, Attendance::withoutGlobalScopes()->where('user_id', $empA->id)->count());
        $this->assertSame('absent', Attendance::withoutGlobalScopes()->where('user_id', $empO->id)->sole()->status);
    }

    public function test_supervisor_cannot_manage_holidays(): void
    {
        $acme = $this->makeTenant('acme');
        $this->actingAs($this->makeUser($acme, 'supervisor'))->get('/holidays')->assertForbidden();
    }
}
