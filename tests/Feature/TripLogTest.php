<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\TripLog;
use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use App\Modules\Core\Models\Position;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Requerimiento Tunesa: los choferes (cargo con «Control por viajes») no marcan, no tienen faltas
 * ni tardanzas y sus días trabajados salen de la Bitácora de viajes que llena el supervisor.
 */
class TripLogTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $hr;

    private User $driver;

    private User $helper;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'America/Lima'));

        $this->acme = $this->makeTenant('acme');
        $branch = $this->makeBranch($this->acme);
        $chofer = Position::create(['tenant_id' => $this->acme->id, 'name' => 'Chofer', 'is_roaming' => true, 'trip_control' => true, 'is_active' => true]);
        $caja   = Position::create(['tenant_id' => $this->acme->id, 'name' => 'Cajero', 'is_active' => true]);

        $this->hr     = $this->makeUser($this->acme, 'hr_manager');
        $this->driver = $this->makeUser($this->acme, 'employee', ['first_name' => 'Pedro', 'last_name' => 'Chofer', 'position_id' => $chofer->id]);
        $this->helper = $this->makeUser($this->acme, 'employee', ['first_name' => 'Luis', 'last_name' => 'Ayudante', 'position_id' => $chofer->id]);
        $this->clerk  = $this->makeUser($this->acme, 'employee', ['first_name' => 'Ana', 'last_name' => 'Caja', 'position_id' => $caja->id]);
        foreach ([$this->driver, $this->helper, $this->clerk] as $u) {
            $u->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_drivers_do_not_mark_and_are_left_out_of_absences_and_tardiness(): void
    {
        $this->actingIn($this->driver, $this->acme)->post('/api/attendance/clock-in', [
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12, 'device_id' => 'x', 'device_os' => 'android',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ])->assertStatus(422)->assertJsonPath('code', 'TRIP_CONTROLLED');

        // La app (también la versión instalada) muestra el aviso en lugar de los botones
        $this->actingIn($this->driver, $this->acme)->getJson('/api/attendance/today')
            ->assertJsonPath('schedule.can_clock_in', false)
            ->assertJsonPath('next_action.code', 'TRIP_CONTROLLED')
            ->assertJsonPath('next_action.actions', []);

        // Calendario de asistencia (faltas, tardanzas): sin choferes
        $ids = collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-11', viewer: $this->hr))->items())->pluck('user_id');
        $this->assertTrue($ids->contains($this->clerk->id));
        $this->assertFalse($ids->contains($this->driver->id));
    }

    public function test_the_supervisor_logs_trips_and_the_days_worked_count_against_26(): void
    {
        $trip = ['route' => 'Trujillo – Huamachuco', 'driver_id' => $this->driver->id, 'helper_id' => $this->helper->id,
            'vehicle_plate' => 't1a-234', 'departure_time' => '06:30'];

        // IDA hoy; la VUELTA se firma después
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), $trip + ['trip_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $log = TripLog::firstOrFail();
        $this->assertSame('T1A-234', $log->vehicle_plate);
        $this->assertFalse($log->hasReturned());

        $this->actingAs($this->hr)->put(route('attendance.trips.update', $log->id), $trip + ['trip_date' => '2026-11-20',
            'return_date' => '2026-11-20', 'return_time' => '18:10', 'return_route' => 'Huamachuco – Trujillo'])->assertSessionHasNoErrors();
        $log->refresh();
        $this->assertTrue($log->hasReturned());
        $this->assertSame($this->hr->id, $log->return_signed_by);

        // Dos viajes el mismo día cuentan un día; otro día, otro más (chofer). El ayudante solo fue el 20
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), ['helper_id' => null] + $trip + ['trip_date' => '2026-11-20', 'departure_time' => '19:00']);
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), ['helper_id' => null] + $trip + ['trip_date' => '2026-11-18']);
        $this->assertSame([$this->driver->id => 2, $this->helper->id => 1],
            TripLog::workedDays([$this->driver->id, $this->helper->id], '2026-11-01', '2026-11-30'));

        $this->actingAs($this->hr)->get(route('attendance.trips.index'))->assertOk()
            ->assertSee('Meta del mes: 26 días de trabajo y 4 de descanso')
            ->assertSee('24 días por completar')
            ->assertSee('Firmó');

        // Hoja para firmar en Excel con el formato del requerimiento: IDA | VUELTA | FIRMA
        $file = $this->actingAs($this->hr)->get(route('attendance.trips.export', ['from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()->assertDownload('bitacora-viajes-2026-11-01_2026-11-30.xlsx')->baseResponse->getFile()->getPathname();
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet();
        @unlink($file);
        $this->assertSame(['N°', 'IDA', 'VUELTA', 'FIRMA DEL CHOFER'], [$sheet->getCell('A6')->getValue(), $sheet->getCell('B6')->getValue(),
            $sheet->getCell('G6')->getValue(), $sheet->getCell('J6')->getValue()]);
        $this->assertSame([...\App\Modules\Attendance\Services\Reports\TripSheetExporter::IDA, ...\App\Modules\Attendance\Services\Reports\TripSheetExporter::VUELTA],
            $sheet->rangeToArray('B7:I7')[0]);
        // Por fecha y hora de salida: 18/11, luego el 20 de las 06:30 con su vuelta y el de las 19:00
        $this->assertSame('18/11/2026', $sheet->getCell('B8')->getValue());
        $this->assertSame([2, '20/11/2026', 'Trujillo – Huamachuco', "Chofer, Pedro\nAyud.: Ayudante, Luis", 'T1A-234'],
            $sheet->rangeToArray('A9:E9', null, true, false)[0]);
        $this->assertSame(['20/11/2026', '18:10', 'Huamachuco – Trujillo', null], $sheet->rangeToArray('G9:J9')[0]);
        $this->assertSame('', (string) $sheet->getCell('G10')->getValue());
        $this->assertSame('SUPERVISOR', $sheet->getCell('C25')->getValue()); // 15 filas para firmar y las firmas al pie

        // Evidencia: la hoja firmada se sube (PDF o foto), se descarga y se quita sin borrar el archivo
        $this->actingAs($this->hr)->post(route('attendance.trips.sheets.store'), ['period_from' => '2026-11-01', 'period_to' => '2026-11-30',
            'sheet' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('sheet');
        $this->actingAs($this->hr)->post(route('attendance.trips.sheets.store'), ['period_from' => '2026-11-01', 'period_to' => '2026-11-30',
            'sheet' => UploadedFile::fake()->create('hoja-noviembre.pdf', 300, 'application/pdf'), 'notes' => 'Semana 1'])->assertSessionHasNoErrors();
        $signed = \App\Modules\Attendance\Models\TripSheet::firstOrFail();
        Storage::disk('private')->assertExists($signed->file_path);
        $this->assertTrue(\App\Modules\Core\Models\AuditLog::where('action', 'attendance.trip_sheet_uploaded')->exists());
        $this->actingAs($this->hr)->get(route('attendance.trips.index'))->assertSee('hoja-noviembre.pdf')->assertSee('Semana 1');
        $this->actingAs($this->hr)->get(route('attendance.trips.sheets.download', $signed->id))->assertOk()->assertDownload('hoja-noviembre.pdf');
        // Otra empresa no la ve
        app(\App\Modules\Core\Services\TenantContext::class)->clear();
        $other = $this->makeUser($this->makeTenant('otra'), 'hr_manager');
        $this->actingAs($other)->get(route('attendance.trips.sheets.download', $signed->id))->assertNotFound();
        $this->actingAs($this->hr)->delete(route('attendance.trips.sheets.destroy', $signed->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($signed);
        Storage::disk('private')->assertExists($signed->file_path);

        // Solo choferes con «Control por viajes»; el ayudante no puede ser el mismo chofer
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), ['driver_id' => $this->clerk->id] + $trip + ['trip_date' => '2026-11-20'])
            ->assertSessionHasErrors('driver_id');
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), ['helper_id' => $this->driver->id] + $trip + ['trip_date' => '2026-11-20'])
            ->assertSessionHasErrors('helper_id');
    }
}
