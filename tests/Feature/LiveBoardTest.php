<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Tablero en vivo (/live y /live/data): estados, alcance del supervisor,
 * vista global y ETag / 304.
 */
class LiveBoardTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private User $supervisor;

    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        // Viernes 09/10/2026, 10:00 en Lima
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'America/Lima'));

        $this->tenant     = $this->makeTenant('acme', ['company_name' => 'Acme SAC']);
        $this->supervisor = $this->makeUser($this->tenant, 'supervisor');
        $this->branch     = $this->makeBranch($this->tenant, ['name' => 'Sede Centro', 'supervisor_id' => $this->supervisor->id]);

        $morning   = $this->shift('Mañana', '08:00');
        $afternoon = $this->shift('Tarde', '14:00');
        $weekend   = $this->shift('Fin de semana', '08:00', [0, 6]);

        foreach (['working', 'on_break', 'left', 'not_arrived', 'on_leave', 'resting'] as $key) {
            $this->people[$key] = $this->employee($key, $key === 'resting' ? $weekend : $morning);
        }
        $this->people['upcoming'] = $this->employee('upcoming', $afternoon);

        $this->attendance('working', ['clock_in_at' => '2026-10-09 08:00:00', 'clock_in_within_geofence' => true, 'clock_in_distance_meters' => 12,
            'clock_in_latitude' => -12.0941, 'clock_in_longitude' => -77.0356]);
        $this->attendance('on_break', ['clock_in_at' => '2026-10-09 08:05:00', 'break_start_at' => '2026-10-09 09:50:00']);
        $this->attendance('left', ['clock_in_at' => '2026-10-09 06:00:00', 'clock_out_at' => '2026-10-09 09:30:00']);

        LeaveRequest::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->people['on_leave']->id, 'type' => 'permission',
            'start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'days' => 1, 'status' => 'approved']);
    }

    private function shift(string $name, string $start, array $days = [1, 2, 3, 4, 5]): Shift
    {
        return Shift::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'start_time' => $start, 'end_time' => '23:00',
            'grace_period_minutes' => 10, 'daily_hours' => 8, 'working_days' => $days]);
    }

    private function employee(string $key, Shift $shift, ?Branch $branch = null): User
    {
        $branch ??= $this->branch;
        $user = $this->makeUser($this->tenant, 'employee', ['first_name' => ucfirst($key), 'last_name' => 'Test']);
        $user->branches()->attach($branch->id, ['is_primary' => true]);
        EmployeeShift::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'shift_id' => $shift->id,
            'branch_id' => $branch->id, 'effective_from' => '2026-01-01', 'is_active' => true]);

        return $user;
    }

    private function attendance(string $key, array $attrs): Attendance
    {
        return Attendance::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->people[$key]->id, 'branch_id' => $this->branch->id,
            'work_date' => '2026-10-09', 'status' => 'present',
        ], $attrs));
    }

    private function statusesOf(array $json): array
    {
        return collect($json['rows'])->mapWithKeys(fn ($r) => [$r['name'] => $r['status']])->all();
    }

    public function test_page_requires_team_permission(): void
    {
        // El colaborador no entra al panel (SetWebTenant lo saca antes de llegar al tablero)
        $this->actingAs($this->people['working'])->get('/live')->assertRedirect();
        $this->assertNotSame(200, $this->actingAs($this->people['working'])->getJson('/live/data')->status());

        $this->actingAs($this->makeUser($this->tenant, 'hr_manager'))->get(route('live.index'))
            ->assertOk()->assertSee('Tablero en vivo')->assertSee('No ha llegado')->assertSee('\/live\/data', false)
            ->assertSee('por llegar o ausente.')->assertDontSee('@if', false)->assertDontSee('$globalView', false);
    }

    public function test_data_classifies_each_person(): void
    {
        $json = $this->actingAs($this->makeUser($this->tenant, 'company_admin'))->getJson('/live/data')
            ->assertOk()->assertHeader('ETag')->json();

        $expected = [
            'Working Test'     => 'working',
            'On_break Test'    => 'on_break',
            'Left Test'        => 'left',
            'Not_arrived Test' => 'not_arrived',
            'On_leave Test'    => 'on_leave',
            'Upcoming Test'    => 'upcoming',
        ];
        $actual = array_intersect_key($this->statusesOf($json), $expected);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertArrayNotHasKey('Resting Test', $this->statusesOf($json), 'Quien descansa hoy no aparece');

        $this->assertSame(1, $json['counters']['working']);
        $this->assertSame(1, $json['counters']['on_break']);
        $this->assertSame(1, $json['counters']['left']);
        $this->assertSame(1, $json['counters']['on_leave']);
        $this->assertSame(1, $json['counters']['upcoming']);
        $this->assertSame(0, $json['counters']['suspicious'], 'Ninguna marcación sospechosa todavía');

        $working = collect($json['rows'])->firstWhere('name', 'Working Test');
        $this->assertSame('08:00 am', $working['clock_in']);
        $this->assertSame('Sede Centro', $working['branch']);
        $this->assertTrue($working['within_geofence']);
        $this->assertEqualsWithDelta(-12.0941, $working['latitude'], 0.0001);
    }

    public function test_upcoming_turns_into_not_arrived_after_grace(): void
    {
        $admin = $this->makeUser($this->tenant, 'company_admin');
        $this->travelTo(Carbon::parse('2026-10-09 14:05:00', 'America/Lima'));
        $this->assertSame('upcoming', $this->statusesOf($this->actingAs($admin)->getJson('/live/data')->json())['Upcoming Test']);

        $this->travelTo(Carbon::parse('2026-10-09 14:11:00', 'America/Lima'));
        $this->assertSame('not_arrived', $this->statusesOf($this->actingAs($admin)->getJson('/live/data')->json())['Upcoming Test']);
    }

    public function test_night_shift_from_yesterday_still_working(): void
    {
        $admin = $this->makeUser($this->tenant, 'company_admin');
        $this->attendance('resting', ['work_date' => '2026-10-08', 'clock_in_at' => '2026-10-08 22:00:00']);

        $this->assertSame('working', $this->statusesOf($this->actingAs($admin)->getJson('/live/data')->json())['Resting Test']);
    }

    public function test_supervisor_sees_only_team(): void
    {
        $otherBranch = $this->makeBranch($this->tenant, ['name' => 'Sede Norte']);
        $this->employee('outsider', $this->shift('Otro', '08:00'), $otherBranch);

        $json = $this->actingAs($this->supervisor)->getJson('/live/data')->assertOk()->json();

        $this->assertArrayHasKey('Working Test', $this->statusesOf($json));
        $this->assertArrayNotHasKey('Outsider Test', $this->statusesOf($json));
    }

    public function test_branch_filter(): void
    {
        $otherBranch = $this->makeBranch($this->tenant, ['name' => 'Sede Norte']);
        $this->employee('outsider', $this->shift('Otro', '08:00'), $otherBranch);

        $json = $this->actingAs($this->makeUser($this->tenant, 'company_admin'))
            ->getJson('/live/data?branch_id=' . $otherBranch->id)->assertOk()->json();

        $this->assertSame(['Outsider Test' => 'not_arrived'], $this->statusesOf($json));
    }

    public function test_etag_returns_304_until_something_changes(): void
    {
        $this->actingAs($this->makeUser($this->tenant, 'company_admin'));

        $etag = $this->getJson('/live/data')->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeaders(['If-None-Match' => $etag])->get('/live/data')->assertStatus(304)->assertHeader('ETag', $etag);

        // Una marcación nueva cambia el estado → 200 con otro ETag
        app(TenantContext::class)->clear();
        $this->attendance('not_arrived', ['clock_in_at' => '2026-10-09 10:00:00']);

        $fresh = $this->withHeaders(['If-None-Match' => $etag])->get('/live/data')->assertOk();
        $this->assertNotSame($etag, $fresh->headers->get('ETag'));
    }

    public function test_global_view_includes_company_and_other_tenants(): void
    {
        $other  = $this->makeTenant('globex', ['company_name' => 'Globex SA']);
        $branch = $this->makeBranch($other);
        $shift  = Shift::create(['tenant_id' => $other->id, 'name' => 'M', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1, 2, 3, 4, 5]]);
        $emp    = $this->makeUser($other, 'employee', ['first_name' => 'Gabi', 'last_name' => 'Globex']);
        $emp->branches()->attach($branch->id);
        EmployeeShift::create(['tenant_id' => $other->id, 'user_id' => $emp->id, 'shift_id' => $shift->id,
            'branch_id' => $branch->id, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $json = $this->actingAs($this->makeUser(null, 'super_admin'))->getJson('/live/data')->assertOk()->json();

        $gabi = collect($json['rows'])->firstWhere('name', 'Gabi Globex');
        $this->assertSame('Globex SA', $gabi['company']);
        $this->assertSame('not_arrived', $gabi['status']);
        $this->assertSame('Acme SAC', collect($json['rows'])->firstWhere('name', 'Working Test')['company']);
    }

    public function test_suspicious_counter_when_column_exists(): void
    {
        Attendance::where('user_id', $this->people['working']->id)->update(['is_suspicious' => true]);

        $json = $this->actingAs($this->makeUser($this->tenant, 'company_admin'))->getJson('/live/data')->assertOk()->json();

        $this->assertSame(1, $json['counters']['suspicious']);
        $this->assertTrue(collect($json['rows'])->firstWhere('name', 'Working Test')['suspicious']);
        $this->get('/live')->assertOk()->assertSee('Sospechosas');
    }

    public function test_sidebar_link_for_supervisor(): void
    {
        $this->actingAs($this->supervisor)->get('/dashboard')->assertOk()->assertSee('Tablero en vivo');
    }
}
