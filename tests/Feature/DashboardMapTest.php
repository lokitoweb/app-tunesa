<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Mapa de geocercas del Dashboard: presentes por agencia y entradas del período con ubicación
 * (verde dentro / rojo fuera), en la carga inicial y en el refresco de cada 20 s, con el mismo alcance.
 */
class DashboardMapTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_map_shows_presence_per_agency_and_each_entry_inside_or_outside(): void
    {
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00', 'America/Lima'));
        $acme  = $this->makeTenant('acme');
        $arena = $this->makeBranch($acme, ['name' => 'AG. LA ARENA']);
        $hua   = $this->makeBranch($acme, ['name' => 'AG. HUAMACHUCO', 'latitude' => -7.81, 'longitude' => -78.05]);
        $mark = function (string $branch, array $attrs) use ($acme, $arena, $hua) {
            $emp = $this->makeUser($acme);
            Attendance::create(array_merge(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => ($branch === 'arena' ? $arena : $hua)->id,
                'work_date' => '2026-11-19', 'clock_in_at' => now(), 'status' => 'present'], $attrs));

            return $emp;
        };
        $mark('arena', ['clock_in_latitude' => -12.0942, 'clock_in_longitude' => -77.0356, 'clock_in_within_geofence' => true, 'clock_in_distance_meters' => 10]);
        $mark('arena', ['clock_in_latitude' => -12.0990, 'clock_in_longitude' => -77.0356, 'clock_in_within_geofence' => false, 'clock_in_distance_meters' => 530]);
        $mark('hua', []); // contingencia: presente pero sin ubicación, no se dibuja punto
        $admin = $this->makeUser($acme, 'company_admin');

        // Carga inicial: el mismo bloque llega a la vista
        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertViewHas('mapData', fn ($m) => $m['presence'][$arena->id] === 2 && $m['presence'][$hua->id] === 1 && count($m['points']) === 2);

        // Refresco: presentes por agencia y puntos con su geocerca
        $map = $this->getJson('/dashboard/live-stream')->assertOk()->json('map');
        $this->assertSame(2, $map['presence'][$arena->id]);
        $this->assertSame(1, $map['presence'][$hua->id]);
        $points = collect($map['points']);
        $this->assertCount(2, $points);
        $this->assertSame([false, true], $points->pluck('within')->sort()->values()->all());
        $this->assertSame('AG. LA ARENA', $points->first()['branch']);

        // Filtro por agencia: solo la suya
        $map = $this->getJson('/dashboard/live-stream?branch_id=' . $hua->id)->assertOk()->json('map');
        $this->assertSame([$hua->id => 1], array_map('intval', $map['presence']));
        $this->assertSame([], $map['points']);
    }
}
