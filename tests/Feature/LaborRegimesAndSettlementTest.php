<?php

namespace Tests\Feature;

use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\EmployeeSettlement;
use App\Modules\HR\Models\LaborRegime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Regímenes laborales por empresa y cese con liquidación calculada con sus parámetros */
class LaborRegimesAndSettlementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_each_company_edits_and_adds_its_own_regimes(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $hr = $this->makeUser($acme, 'hr_manager');

        $this->actingAs($hr)->get('/labor-regimes')->assertOk()
            ->assertInertia(fn ($p) => $p->component('HR/Pages/LaborRegimes/Index')->where('regimes', fn ($r) => collect($r)->pluck('code')->contains('small_company')));

        $small = LaborRegime::catalog($acme->id)->firstWhere('code', 'small_company');
        $this->put("/labor-regimes/{$small->id}", ['name' => 'MYPE Pequeña', 'vacation_days' => 15, 'gratifications_per_year' => 1,
            'gratification_bonus_pct' => 9, 'cts_per_year' => 0.5, 'indemnity_days_per_year' => 20, 'indemnity_cap_days' => 120, 'is_active' => true])
            ->assertSessionHasNoErrors();
        $this->post('/labor-regimes', ['name' => 'Régimen especial minero', 'vacation_days' => 30])->assertSessionHasNoErrors();

        $this->assertSame('MYPE Pequeña', $small->fresh()->name);
        $this->assertArrayHasKey('regimen_especial_minero', LaborRegime::options($acme->id));
        $this->assertSame('Pequeña empresa', LaborRegime::label($other->id, 'small_company'), 'la otra empresa no cambia');
        $this->assertArrayNotHasKey('regimen_especial_minero', LaborRegime::options($other->id));

        // El nuevo régimen se puede asignar en la ficha; uno inexistente no
        $ana = $this->makeUser($acme);
        $this->put("/employees/{$ana->id}/file/profile", ['labor_regime' => 'regimen_especial_minero'])->assertSessionHasNoErrors();
        $this->assertSame('regimen_especial_minero', EmployeeProfile::where('user_id', $ana->id)->value('labor_regime'));
        $this->put("/employees/{$ana->id}/file/profile", ['labor_regime' => 'inventado'])->assertSessionHasErrors('labor_regime');

        // Un colaborador no administra regímenes
        $this->assertContains($this->actingAs($ana)->get('/labor-regimes')->status(), [302, 403]);
    }

    public function test_settlement_uses_the_regime_parameters_and_registers_the_termination(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $otherHr = $this->makeUser($this->makeTenant('other'), 'hr_manager');
        $ana = $this->makeUser($acme);
        $ana->forceFill(['hire_date' => '2024-03-01', 'base_salary' => 3000])->save();
        EmployeeProfile::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'labor_regime' => 'general']);

        // Cese el 31/08/2026: 2 años y 6 meses; semestre jul-ago (2 meses); CTS desde el 01/05 (4 meses)
        $page = fn (array $q) => $this->actingAs($hr)->get("/employees/{$ana->id}/settlement?" . http_build_query($q + [
            'termination_date' => '2026-08-31', 'reason' => 'resignation', 'salary' => 3000, 'pending_days' => 0,
        ]));
        $lines = null;
        $page([])->assertOk()->assertInertia(function ($p) use (&$lines) {
            $p->component('HR/Pages/Settlements/Create');
            $lines = collect($p->toArray()['props']['result']['lines'])->pluck('amount', 'key');
        });
        $this->assertEqualsWithDelta(1500.00, $lines['vacation_truncated'], 0.01, '30 días × 6/12');
        $this->assertEqualsWithDelta(1000.00, $lines['gratification'], 0.01, '3000 / 6 × 2 meses');
        $this->assertEqualsWithDelta(90.00, $lines['gratification_bonus'], 0.01, '9 %');
        $this->assertEqualsWithDelta(1166.67, $lines['cts'], 0.01, '(3000 + 500) × 4/12');
        $this->assertArrayNotHasKey('indemnity', $lines->all(), 'solo si se marca');

        // Microempresa: sin gratificación ni CTS; indemnización 10 días por año con tope 90
        EmployeeProfile::where('user_id', $ana->id)->update(['labor_regime' => 'micro_company']);
        $page(['indemnity' => 1])->assertInertia(function ($p) use (&$lines) {
            $lines = collect($p->toArray()['props']['result']['lines'])->pluck('amount', 'key');
        });
        $this->assertEqualsWithDelta(0.0, $lines['gratification'], 0.01);
        $this->assertEqualsWithDelta(0.0, $lines['cts'], 0.01);
        $this->assertEqualsWithDelta(2500.00, $lines['indemnity'], 0.01, '10 × 2.5 años × 100 por día');

        // Régimen sin parámetros: no se inventa
        EmployeeProfile::where('user_id', $ana->id)->update(['labor_regime' => 'agrarian']);
        $page([])->assertInertia(fn ($p) => $p->where('result.lines', fn ($l) => collect($l)->firstWhere('key', 'cts')['amount'] === null));

        // Registrar el cese: guarda la liquidación, fija la fecha y desactiva
        $this->post("/employees/{$ana->id}/settlement", ['termination_date' => '2026-08-31', 'reason' => 'resignation', 'salary' => 3000, 'pending_days' => 5])
            ->assertSessionHasNoErrors();
        $settlement = EmployeeSettlement::where('user_id', $ana->id)->firstOrFail();
        $this->assertSame('2026-08-31', $ana->fresh()->termination_date->toDateString());
        $this->assertSame('inactive', $ana->fresh()->status);
        $this->assertEqualsWithDelta(500.0, collect($settlement->data['lines'])->firstWhere('key', 'vacation_pending')['amount'], 0.01);
        $this->get("/settlements/{$settlement->id}/print")->assertOk()->assertSee('LIQUIDACIÓN DE BENEFICIOS SOCIALES');

        // Otra empresa no ve la liquidación
        $this->actingAs($otherHr)->get("/settlements/{$settlement->id}/print")->assertNotFound();
    }

    public function test_each_benefit_has_its_own_base_and_pension_is_only_deducted_from_vacations(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $ana = $this->makeUser($acme);
        $ana->forceFill(['hire_date' => '2024-03-01', 'base_salary' => 3000])->save();
        EmployeeProfile::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'labor_regime' => 'general', 'pension_system' => 'afp', 'pension_fund' => 'AFP Prima',
            'bank_name' => 'BCP', 'bank_account_number' => '19112345678', 'payment_info_status' => 'verified']);

        // Horas extra solo en vacaciones; AFP Prima de la ficha (10 % + 1.37 %); un adelanto a descontar
        $query = ['termination_date' => '2026-08-31', 'reason' => 'resignation', 'salary' => 3000, 'pending_days' => 0, 'family' => 0,
            'overtime' => 1, 'ot_cts' => 0, 'ot_vacation' => 600, 'ot_gratification' => 0, 'other_discounts' => [['label' => 'Adelanto', 'amount' => 100]]];
        $props = null;
        $this->actingAs($hr)->get("/employees/{$ana->id}/settlement?" . http_build_query($query))->assertOk()
            ->assertInertia(function ($p) use (&$props) { $props = $p->toArray()['props']; });
        $r = $props['result'];
        $amounts = collect($r['lines'])->pluck('amount', 'key');
        $this->assertSame('AFP Prima', $r['pension']['name']);
        $this->assertEqualsWithDelta(3600.00, collect($r['bases'])->firstWhere('key', 'vacation')['total'], 0.01);
        $this->assertEqualsWithDelta(1800.00, $amounts['vacation_truncated'], 0.01, '3600 × 6/12');
        $this->assertEqualsWithDelta(1166.67, $amounts['cts'], 0.01, 'base CTS sin las horas extra de vacaciones');
        $this->assertEqualsWithDelta(4056.67, $r['gross'], 0.01);
        $this->assertEquals([180.0, 24.66, 100.0], collect($r['deductions'])->pluck('amount')->all(), 'AFP solo sobre las vacaciones');
        $this->assertEqualsWithDelta(3752.01, $r['total'], 0.01);

        // Asignación familiar: % de la remuneración mínima, editable por la empresa
        $this->put('/labor-regimes/parameters', ['minimum_wage' => 1200, 'family_allowance_pct' => 10])->assertSessionHasNoErrors();
        $this->get("/employees/{$ana->id}/settlement?" . http_build_query(['family' => 1] + $query))
            ->assertInertia(fn ($p) => $p->where('input.family_allowance', 120)->where('result.bases.1.total', 3720));

        // Registrar y enviar el neto a Tesorería (una sola vez, vence a las 48 h del cese)
        $this->post("/employees/{$ana->id}/settlement", $query)->assertSessionHasNoErrors();
        $settlement = EmployeeSettlement::where('user_id', $ana->id)->firstOrFail();
        $this->assertEqualsWithDelta(3752.01, $settlement->total, 0.01);
        $this->post("/settlements/{$settlement->id}/treasury")->assertSessionHas('success');
        $payment = \App\Modules\Finance\Models\Requisition::where('employee_id', $ana->id)->where('payroll_concept', 'LIQUIDACION')->firstOrFail();
        $this->assertEqualsWithDelta(3752.01, (float) $payment->amount, 0.01);
        $this->assertSame('2026-08', $payment->payroll_period);
        $this->post("/settlements/{$settlement->id}/treasury")->assertSessionHas('error');
        $this->assertSame(1, \App\Modules\Finance\Models\Requisition::where('employee_id', $ana->id)->count());
        $this->get("/settlements/{$settlement->id}/print")->assertOk()->assertSee('V. Descuentos de ley')->assertSee('3,752.01');
    }

    public function test_overtime_average_comes_from_approved_overtime_and_only_if_regular(): void
    {
        $acme = $this->makeTenant('acme');
        $ana = $this->makeUser($acme);
        $branch = $this->makeBranch($acme);
        $day = fn (string $date, float $hours, string $status = 'approved') => \App\Modules\Attendance\Models\Attendance::create([
            'tenant_id' => $acme->id, 'user_id' => $ana->id, 'branch_id' => $branch->id, 'work_date' => $date, 'status' => 'present',
            'clock_in_at' => "{$date} 13:00:00", 'clock_out_at' => "{$date} 23:00:00", 'overtime_hours' => $hours,
            'overtime_status' => $status, 'overtime_approved_hours' => $hours,
        ]);
        $averages = fn () => app(\App\Modules\HR\Services\OvertimeAverages::class)->for($ana, \Carbon\CarbonImmutable::parse('2026-08-31'), 2400);

        // Valor hora 2400/240 = 10: 3 h = 2 × 12.50 + 1 × 13.50 = 38.50 por día
        $day('2026-07-10', 3);
        $day('2026-08-10', 3);
        $day('2026-08-11', 1, 'rejected');
        $this->assertSame(0.0, $averages()['cts'], 'en 2 meses no es regular');

        $day('2026-03-05', 3);
        $a = $averages();
        $this->assertEqualsWithDelta(38.50 * 3 / 6, $a['cts'], 0.01, '3 meses en el semestre de CTS: suma / 6');
        $this->assertSame(0.0, $a['gratification'], 'en el semestre jul-ago solo hay 2 meses');
    }
}
