<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * "Hoy" del dashboard: zona horaria de la empresa; en la vista global, la de la app.
 */
class DashboardTimezoneTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        // 20:00 en Lima del 9 de octubre = 10:00 del 10 de octubre en Tokio
        $this->travelTo(Carbon::parse('2026-10-09 20:00:00', 'America/Lima'));
    }

    private function tokyoCompanyWithAttendance(): array
    {
        $tokyo  = $this->makeTenant('tokyo', ['timezone' => 'Asia/Tokyo']);
        $emp    = $this->makeUser($tokyo);
        Attendance::create(['tenant_id' => $tokyo->id, 'user_id' => $emp->id, 'branch_id' => $this->makeBranch($tokyo)->id,
            'work_date' => '2026-10-10', 'clock_in_at' => now(), 'status' => 'present']);

        return [$tokyo, $emp];
    }

    public function test_company_dashboard_uses_company_timezone_for_today(): void
    {
        [$tokyo] = $this->tokyoCompanyWithAttendance();

        $this->actingAs($this->makeUser($tokyo, 'company_admin'))->get('/dashboard')->assertOk()
            ->assertViewHas('today', '2026-10-10')
            ->assertViewHas('kpis', fn ($k) => $k['present_count'] === 1);

        $this->getJson('/dashboard/live-stream')->assertOk()->assertJsonCount(1, 'attendances');
    }

    public function test_global_view_uses_app_timezone(): void
    {
        $this->tokyoCompanyWithAttendance();
        config(['app.timezone' => 'America/Lima']);

        $this->actingAs($this->makeUser(null, 'super_admin'))->get('/dashboard')->assertOk()
            ->assertViewHas('today', '2026-10-09')
            ->assertViewHas('kpis', fn ($k) => $k['present_count'] === 0);
    }
}
