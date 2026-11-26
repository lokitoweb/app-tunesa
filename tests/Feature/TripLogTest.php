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
        $chofer = Position::withoutGlobalScopes()->where('name', 'Chofer')->firstOrFail();
        $aux = $this->makeUser($this->acme, 'employee', ['first_name' => 'Rosa', 'last_name' => 'Auxiliar', 'position_id' => $chofer->id]);
        $trip = ['route' => 'Trujillo – Huamachuco', 'driver_id' => $this->driver->id, 'co_driver_id' => $this->helper->id, 'helper_id' => $aux->id,
            'vehicle_plate' => 't1a-234', 'departure_time' => '06:30'];

        // IDA hoy (sin llegada todavía): el sistema le pone el N.º de salida
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), $trip + ['trip_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $log = TripLog::firstOrFail();
        $this->assertSame(1, $log->trip_number);
        $this->assertSame('T1A-234', $log->vehicle_plate);
        $this->assertNull($log->outboundMinutes());
        $this->assertFalse($log->hasReturned());

        // Se completa: llegada de la ida 11:45 (5 h 15), salida del regreso 13:00 y llegada 18:10 (5 h 10): queda firmado
        $this->actingAs($this->hr)->put(route('attendance.trips.update', $log->id), $trip + ['trip_date' => '2026-11-20',
            'arrival_date' => '2026-11-20', 'arrival_time' => '11:45', 'return_departure_date' => '2026-11-20', 'return_departure_time' => '13:00',
            'return_date' => '2026-11-20', 'return_time' => '18:10', 'return_route' => 'Huamachuco – Trujillo'])->assertSessionHasNoErrors();
        $log->refresh();
        $this->assertSame([315, 310], [$log->outboundMinutes(), $log->returnMinutes()]);
        $this->assertTrue($log->hasReturned());
        $this->assertSame($this->hr->id, $log->return_signed_by);

        // Las horas y los días los heredan los tres. Dos viajes el mismo día cuentan un día; otro día, otro más
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), ['co_driver_id' => null, 'helper_id' => null, 'trip_date' => '2026-11-20', 'departure_time' => '19:00'] + $trip)->assertSessionHasNoErrors();
        // Sale el sábado 21 y regresa el domingo 22: cuenta en la semana y el día del sábado (fecha de salida)
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), ['co_driver_id' => null, 'helper_id' => null, 'trip_date' => '2026-11-21', 'departure_time' => '22:00',
            'arrival_date' => '2026-11-22', 'arrival_time' => '04:00'] + $trip)->assertSessionHasNoErrors();
        $this->assertSame([2, 3], TripLog::orderBy('id')->skip(1)->take(2)->pluck('trip_number')->all());
        $this->assertSame([$this->driver->id => 2, $this->helper->id => 1, $aux->id => 1],
            TripLog::workedDays([$this->driver->id, $this->helper->id, $aux->id], '2026-11-01', '2026-11-30'));
        $driving = TripLog::drivingMinutes(TripLog::all());
        $this->assertSame(['outbound' => 315 + 360, 'return' => 310], $driving[$this->driver->id]);
        $this->assertSame(['outbound' => 315, 'return' => 310], $driving[$aux->id]);

        $this->actingAs($this->hr)->get(route('attendance.trips.index', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()
            ->assertSee('Meta del mes: 26 días de trabajo y 4 de descanso')
            ->assertSee('24 días por completar')
            ->assertSee('Firmó')->assertSee('N.º de salida 4')
            ->assertSee('11:15:00')->assertSee('TOTALES')
            ->assertSee('Sem 4 · 16–22/11')->assertSee('Todo el periodo');

        // Hojas de conducción en Excel: una de ida y otra de regreso, con N.º de salida, tripulación, horas y tres firmas
        $file = $this->actingAs($this->hr)->get(route('attendance.trips.export', ['from' => '2026-11-16', 'to' => '2026-11-22']))
            ->assertOk()->assertDownload('bitacora-viajes-2026-11-16_2026-11-22.xlsx')->baseResponse->getFile()->getPathname();
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        @unlink($file);
        $this->assertSame(['Conducción de ida', 'Conducción de regreso'], $book->getSheetNames());
        $ida = $book->getSheet(0);
        $this->assertSame(\App\Modules\Attendance\Services\Reports\TripSheetExporter::COLUMNS, $ida->rangeToArray('B7:M7')[0]);
        $this->assertSame(['IDA', 'FIRMAS'], [$ida->getCell('H6')->getValue(), $ida->getCell('K6')->getValue()]);
        $this->assertSame(['1', 'Trujillo – Huamachuco', 'T1A-234', 'Chofer, Pedro', 'Ayudante, Luis', 'Auxiliar, Rosa', '20/11/2026 06:30', '20/11/2026 11:45', '05:15:00'],
            $ida->rangeToArray('B8:J8')[0]);
        $this->assertSame(['3', '21/11/2026 22:00', '22/11/2026 04:00', '06:00:00'], [$ida->getCell('B10')->getValue(), $ida->getCell('H10')->getValue(), $ida->getCell('I10')->getValue(), $ida->getCell('J10')->getValue()]);
        $regreso = $book->getSheet(1);
        $this->assertSame('REGRESO', $regreso->getCell('H6')->getValue());
        $this->assertSame(['Huamachuco – Trujillo', '20/11/2026 13:00', '20/11/2026 18:10', '05:10:00'],
            [$regreso->getCell('C8')->getValue(), $regreso->getCell('H8')->getValue(), $regreso->getCell('I8')->getValue(), $regreso->getCell('J8')->getValue()]);

        // Evidencia: la hoja firmada se sube con el periodo que cubre (sin elegir ida o regreso); arriba el formulario, abajo la descarga
        $this->actingAs($this->hr)->post(route('attendance.trips.sheets.store'), ['period_from' => '2026-11-01', 'period_to' => '2026-11-30',
            'sheet' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('sheet');
        $this->actingAs($this->hr)->post(route('attendance.trips.sheets.store'), ['period_from' => '2026-11-30', 'period_to' => '2026-11-01',
            'sheet' => UploadedFile::fake()->create('hoja.pdf', 300, 'application/pdf')])->assertSessionHasErrors('period_to');
        $this->actingAs($this->hr)->post(route('attendance.trips.sheets.store'), ['period_from' => '2026-11-01', 'period_to' => '2026-11-30',
            'sheet' => UploadedFile::fake()->create('hoja-noviembre.pdf', 300, 'application/pdf'), 'notes' => 'Semana 1'])->assertSessionHasNoErrors();
        $signed = \App\Modules\Attendance\Models\TripSheet::firstOrFail();
        $this->assertNull($signed->leg);
        $this->actingAs($this->hr)->get(route('attendance.trips.index'))->assertDontSee('name="leg"', false)
            ->assertSeeInOrder(['Subir hoja firmada', 'Descargar para imprimir', 'hoja-noviembre.pdf']);
        // La lista muestra todas las hojas del periodo agrupadas por su semana, en orden de fechas
        $this->actingAs($this->hr)->post(route('attendance.trips.sheets.store'), ['period_from' => '2026-11-16', 'period_to' => '2026-11-22',
            'sheet' => UploadedFile::fake()->create('semana-4.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->get(route('attendance.trips.index'))
            ->assertSeeInOrder(['Del 01/11/2026 al 30/11/2026', '1 hoja', 'hoja-noviembre.pdf', 'Del 16/11/2026 al 22/11/2026', '1 hoja', 'semana-4.pdf']);
        \App\Modules\Attendance\Models\TripSheet::where('original_name', 'semana-4.pdf')->delete();
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

        // Solo personas con «Control por viajes»; los tres son distintos; los momentos van en orden
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), ['driver_id' => $this->clerk->id] + $trip + ['trip_date' => '2026-11-20'])
            ->assertSessionHasErrors('driver_id');
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), ['co_driver_id' => $this->driver->id] + $trip + ['trip_date' => '2026-11-20'])
            ->assertSessionHasErrors('co_driver_id');
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), ['helper_id' => $this->helper->id] + $trip + ['trip_date' => '2026-11-20'])
            ->assertSessionHasErrors('helper_id');
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), $trip + ['trip_date' => '2026-11-20', 'arrival_date' => '2026-11-20', 'arrival_time' => '05:00'])
            ->assertSessionHasErrors('arrival_time');
        $this->actingAs($this->hr)->post(route('attendance.trips.store'), $trip + ['trip_date' => '2026-11-20', 'return_date' => '2026-11-20', 'return_time' => '18:00'])
            ->assertSessionHasErrors('return_departure_time');
    }

    /** Los viajes que ya existían: el «Ayudante» (2.º chofer) pasa a Conductor 2 y se numeran en el orden en que se registraron */
    public function test_existing_trips_are_carried_over_without_losing_data(): void
    {
        $migration = require base_path('app/Modules/Attendance/Database/Migrations/2026_11_29_000001_trip_logs_crew_and_driving_hours.php');
        $migration->down();
        $rows = [['2026-11-23', '20:00', $this->helper->id], ['2026-11-23', '08:30', null]];
        foreach ($rows as $i => [$date, $time, $helper]) {
            \Illuminate\Support\Facades\DB::table('trip_logs')->insert(['tenant_id' => $this->acme->id, 'trip_date' => $date, 'route' => 'Ruta ' . $i,
                'driver_id' => $this->driver->id, 'helper_id' => $helper, 'vehicle_plate' => 'T8H-624', 'departure_time' => $time,
                'created_at' => now()->addMinutes($i), 'updated_at' => now()]);
        }
        $migration->up();

        $trips = TripLog::orderBy('id')->get();
        $this->assertSame([1, 2], $trips->pluck('trip_number')->all());
        $this->assertSame([$this->helper->id, null], $trips->pluck('co_driver_id')->all());
        $this->assertSame([null, null], $trips->pluck('helper_id')->all());
        $this->assertSame(['Ruta 0', 'Ruta 1'], $trips->pluck('route')->all());
    }
}
