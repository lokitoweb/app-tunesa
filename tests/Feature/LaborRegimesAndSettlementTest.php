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
        $this->get("/settlements/{$settlement->id}/print")->assertOk()->assertSee('Liquidación de beneficios sociales');

        // Otra empresa no ve la liquidación
        $this->actingAs($otherHr)->get("/settlements/{$settlement->id}/print")->assertNotFound();
    }
}
