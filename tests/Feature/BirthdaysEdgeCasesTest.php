<?php

namespace Tests\Feature;

use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Cumpleaños con datos reales difíciles: 29 de febrero, fin de año, sin sede ni área */
class BirthdaysEdgeCasesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inertia_visit_gets_a_page_not_plain_json(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');

        // Visita desde el menú: Inertia manda X-Inertia y X-Requested-With (axios)
        $version = (string) app(\App\Modules\Core\Middleware\HandleInertiaRequests::class)->version(request());
        $this->actingAs($hr)->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => $version])
            ->get('/birthdays')->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'Birthdays/Pages/Birthdays/Index');
        // El filtro AJAX propio de la pantalla sigue recibiendo JSON
        $this->flushHeaders()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get('/birthdays?month=6')->assertOk()->assertJsonStructure(['people', 'months']);
    }

    public function test_birthdays_screens_survive_edge_dates_in_panel_and_app(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme', ['timezone' => 'America/Lima']);
        $hr = $this->makeUser($acme, 'hr_manager');
        $emp = $this->makeUser($acme, 'employee', ['birth_date' => '1990-06-15']);
        $leap = $this->makeUser($acme, 'employee', ['birth_date' => '1992-02-29']);  // bisiesto
        $this->makeUser($acme, 'employee', ['birth_date' => '1985-12-31']);           // fin de año
        $this->makeUser($acme, 'supervisor', ['birth_date' => '2000-01-01']);         // sin sede ni área

        foreach (['2027-02-28 09:00', '2026-12-31 23:30', '2026-01-01 00:10', '2026-06-15 08:00'] as $now) {
            Carbon::setTestNow(Carbon::parse($now, 'America/Lima'));
            app(TenantContext::class)->clear();

            foreach ([null, 1, 2, 6, 12] as $month) {
                $this->actingAs($hr)->get('/birthdays' . ($month ? "?month={$month}" : ''))->assertOk();
                $this->actingIn($emp, $acme)->getJson('/api/birthdays' . ($month ? "?month={$month}" : ''))->assertOk();
            }
            $this->actingIn($emp, $acme)->getJson('/api/birthdays?scope=team')->assertOk();
            $this->actingIn($emp, $acme)->getJson('/api/birthdays/upcoming')->assertOk();
            $this->actingIn($emp, $acme)->getJson("/api/birthdays/{$leap->id}")->assertOk();
        }

        // El 28/02 de un año no bisiesto, quien nació el 29/02 cumple "hoy"
        Carbon::setTestNow(Carbon::parse('2027-02-28 09:00', 'America/Lima'));
        $this->actingIn($emp, $acme)->getJson('/api/birthdays?month=2')->assertOk()
            ->assertJsonPath('data.today.0.id', $leap->id);
    }
}
