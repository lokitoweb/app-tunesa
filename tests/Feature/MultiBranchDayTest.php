<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeScheduleDay;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Colaborador con dos agencias de su empresa (Huamachuco principal, La Arena adicional): marca en la
 * agencia del día (la programada) y el Tablero en vivo lo muestra ahí. Con una sola agencia, como antes.
 */
class MultiBranchDayTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const ARENA = ['latitude' => -12.20, 'longitude' => -77.03560];      // ~12 km de Huamachuco

    private Tenant $acme;
    private Branch $huamachuco;
    private Branch $arena;
    private User $emp;
    private Shift $arenaMorning;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
        $this->travelTo(Carbon::parse('2026-11-18 07:30:00', 'America/Lima'));

        $this->acme = $this->makeTenant('acme');
        $this->huamachuco = $this->makeBranch($this->acme, ['name' => 'AG. HUAMACHUCO']);
        $this->arena = $this->makeBranch($this->acme, ['name' => 'AG. LA ARENA'] + self::ARENA);
        $this->emp = $this->makeUser($this->acme, 'employee', ['first_name' => 'Keminin', 'last_name' => 'Polo']);
        $this->emp->branches()->attach($this->huamachuco->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->emp->branches()->attach($this->arena->id, ['is_primary' => false, 'assigned_from' => '2020-01-01']);
        $this->arenaMorning = Shift::create(['tenant_id' => $this->acme->id, 'branch_id' => $this->arena->id, 'name' => 'MAÑANA',
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 10, 'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function programToday(Branch $branch, Shift $shift): void
    {
        EmployeeScheduleDay::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'work_date' => '2026-11-18',
            'shift_id' => $shift->id, 'branch_id' => $branch->id]);
    }

    private function clockInAt(array $where)
    {
        return $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $where + [
            'accuracy_meters' => 12, 'device_id' => 'd', 'device_os' => 'android',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ]);
    }

    private function huamachucoPoint(): array
    {
        return ['latitude' => $this->huamachuco->latitude, 'longitude' => $this->huamachuco->longitude];
    }

    public function test_programmed_in_la_arena_marks_and_is_recorded_in_la_arena(): void
    {
        $this->programToday($this->arena, $this->arenaMorning);

        $this->clockInAt(self::ARENA)->assertCreated();

        $this->assertSame($this->arena->id, (int) Attendance::where('user_id', $this->emp->id)->value('branch_id'));
    }

    public function test_programmed_in_la_arena_cannot_mark_in_huamachuco(): void
    {
        $this->programToday($this->arena, $this->arenaMorning);

        $this->clockInAt($this->huamachucoPoint())->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');
        $this->assertSame(0, Attendance::count());
    }

    public function test_without_a_programmed_agency_any_assigned_agency_where_he_is_counts(): void
    {
        $this->withoutScheduleV2($this->acme);

        $this->clockInAt(self::ARENA)->assertCreated();
        $this->assertSame($this->arena->id, (int) Attendance::where('user_id', $this->emp->id)->value('branch_id'));

        // Fuera de sus dos agencias: se rechaza como siempre
        $other = $this->makeUser($this->acme, 'employee');
        $other->branches()->attach($this->huamachuco->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $other->branches()->attach($this->arena->id, ['is_primary' => false, 'assigned_from' => '2020-01-01']);
        $this->actingIn($other, $this->acme)->post('/api/attendance/clock-in', ['latitude' => -12.5, 'longitude' => -77.0356,
            'accuracy_meters' => 12, 'device_id' => 'd', 'device_os' => 'android', 'photo' => UploadedFile::fake()->image('s.jpg', 480, 640)])
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');
    }

    public function test_single_agency_employee_marks_as_before(): void
    {
        $this->withoutScheduleV2($this->acme);
        $solo = $this->makeUser($this->acme, 'employee');
        $solo->branches()->attach($this->huamachuco->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $payload = ['accuracy_meters' => 12, 'device_id' => 'd', 'device_os' => 'android', 'photo' => UploadedFile::fake()->image('s.jpg', 480, 640)];

        $this->actingIn($solo, $this->acme)->post('/api/attendance/clock-in', self::ARENA + $payload)
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');
        $this->actingIn($solo, $this->acme)->post('/api/attendance/clock-in', $this->huamachucoPoint() + $payload)->assertCreated();
        $this->assertSame($this->huamachuco->id, (int) Attendance::where('user_id', $solo->id)->value('branch_id'));
    }

    public function test_live_board_shows_and_filters_by_the_agency_of_the_day(): void
    {
        $this->programToday($this->arena, $this->arenaMorning);
        $admin = $this->makeUser($this->acme, 'company_admin');
        $row = fn (array $json) => collect($json['rows'])->firstWhere('user_id', $this->emp->id);

        // Aún sin marcar: aparece en La Arena (la programada), no en su principal
        $all = $this->actingAs($admin)->getJson('/live/data')->assertOk()->json();
        $this->assertSame('AG. LA ARENA', $row($all)['branch']);
        $this->assertNotNull($row($this->actingAs($admin)->getJson('/live/data?branch_id=' . $this->arena->id)->json()));
        $this->assertNull($row($this->actingAs($admin)->getJson('/live/data?branch_id=' . $this->huamachuco->id)->json()));

        // Ya marcó en La Arena: sigue ahí
        $this->clockInAt(self::ARENA)->assertCreated();
        $this->assertSame('AG. LA ARENA', $row($this->actingAs($admin)->getJson('/live/data')->json())['branch']);
    }
}
