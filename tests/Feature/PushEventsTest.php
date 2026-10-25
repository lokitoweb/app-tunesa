<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\Shift;
use App\Modules\Integrations\Notifications\Channels\FcmChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Avisos push nuevos: versión de la app, turno asignado y recordatorio de entrada */
class PushEventsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->acme = $this->makeTenant('acme', ['timezone' => 'America/Lima']);
        $this->emp  = $this->makeUser($this->acme, 'employee', ['fcm_token' => 'device-token-123']);
        app(TenantContext::class)->clear();

        [$pem] = $this->makeRsaKeyPair();
        // Solo la credencial: fcm_enabled queda en NULL y vale el valor por defecto (encendido)
        TenantSetting::editableRowFor(null)->fill(['fcm_service_account_json' => json_encode([
            'type' => 'service_account', 'project_id' => 'tunesa-app', 'private_key' => $pem,
            'client_email' => 'firebase@tunesa-app.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token',
        ])])->save();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3599]),
            'fcm.googleapis.com/*'        => Http::response(['name' => 'projects/tunesa-app/messages/1']),
        ]);
    }

    private function pushes(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com'))
            ->map(fn ($pair) => $pair[0]['message'])
            ->values()->all();
    }

    private function assign(string $start = '08:00', int $grace = 10): Shift
    {
        $branch = $this->makeBranch($this->acme);
        $shift  = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Mañana', 'start_time' => $start, 'end_time' => '17:00',
            'grace_period_minutes' => $grace, 'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $shift->id,
            'branch_id' => $branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
        app(TenantContext::class)->clear();

        return $shift;
    }

    public function test_push_is_on_by_default_once_firebase_credentials_are_loaded(): void
    {
        $this->assertTrue(TenantSetting::forTenant($this->acme)->fcmEnabled());

        TenantSetting::editableRowFor($this->acme)->fill(['fcm_enabled' => false])->save();
        $this->assertFalse(TenantSetting::forTenant($this->acme)->fcmEnabled());
    }

    public function test_publishing_a_higher_version_pushes_to_employees_with_a_device(): void
    {
        $other = $this->makeTenant('otra');
        $this->makeUser($other, 'employee', ['fcm_token' => 'other-device']);
        TenantSetting::editableRowFor($other)->fill(['latest_version' => '1.0.0'])->save(); // tiene versión propia
        $this->makeUser($this->acme, 'employee'); // sin teléfono registrado
        app(TenantContext::class)->clear();
        $before = count($this->pushes()); // el aviso de la 1.0.0 de "otra"
        $this->assertSame(1, $before);

        TenantSetting::editableRowFor(null)->fill(['latest_version' => '1.2.0'])->save();

        $pushes = array_slice($this->pushes(), $before);
        $this->assertCount(1, $pushes);
        $this->assertSame('device-token-123', $pushes[0]['token']);
        $this->assertSame('Nueva versión de la app', $pushes[0]['notification']['title']);
        $this->assertSame('app.update_available', $pushes[0]['data']['type']);
        $this->assertSame('app_update', $pushes[0]['data']['screen']);
        $this->assertSame('1.2.0', $pushes[0]['data']['version']);
        $this->assertSame(0, $this->emp->notifications()->count()); // solo push

        // Misma versión o una menor: no se vuelve a avisar
        TenantSetting::editableRowFor(null)->fill(['latest_version' => '1.1.9'])->save();
        TenantSetting::editableRowFor(null)->fill(['trade_name' => 'Tunesa'])->save();
        $this->assertCount($before + 1, $this->pushes());
    }

    public function test_required_update_says_so(): void
    {
        TenantSetting::editableRowFor($this->acme)->fill(['min_supported_version' => '2.0.0', 'force_update' => true, 'latest_version' => '2.0.0'])->save();

        $pushes = $this->pushes();
        $this->assertCount(1, $pushes);
        $this->assertSame('Actualiza la app', $pushes[0]['notification']['title']);
        $this->assertSame('true', $pushes[0]['data']['required']);
    }

    public function test_new_shift_assignment_notifies_the_employee(): void
    {
        $this->assign('07:30');

        $note = $this->emp->notifications()->sole();
        $this->assertSame('shift.assigned', $note->type);
        $this->assertStringContainsString('Mañana (07:30 a 17:00)', $note->data['body']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com') && $r['message']['data']['type'] === 'shift.assigned');

        // Cambiar notas no avisa; cambiar el turno sí
        $assignment = EmployeeShift::sole();
        $assignment->update(['notes' => 'x']);
        $this->assertSame(1, $this->emp->notifications()->count());
        $assignment->update(['effective_until' => now()->subDay()->toDateString(), 'shift_id' => $assignment->shift_id]);
        $this->assertSame(1, $this->emp->notifications()->count());
    }

    public function test_entry_reminder_five_minutes_before_start_when_not_marked(): void
    {
        $this->assign('08:00', grace: 10);
        $this->fakeFcm();
        $countReminders = fn () => collect($this->pushes())->where('data.type', 'attendance.entry_reminder')->count();

        // Más de 5 minutos antes: nada
        Carbon::setTestNow(Carbon::parse('2026-10-12 07:54', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(0, $countReminders());

        // 5 minutos antes: un aviso, y no se repite
        Carbon::setTestNow(Carbon::parse('2026-10-12 07:55', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:15', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(1, $countReminders());
        $push = collect($this->pushes())->firstWhere('data.type', 'attendance.entry_reminder');
        $this->assertSame('Tu turno empieza en 5 minutos', $push['notification']['title']);
        $this->assertStringContainsString('08:00', $push['notification']['body']);
        $this->assertSame(1, $this->emp->notifications()->count()); // solo el del turno: el recordatorio no va a la bandeja

        // Otro día, ya marcó: nada
        Carbon::setTestNow(Carbon::parse('2026-10-13 07:56', 'America/Lima'));
        Attendance::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'branch_id' => EmployeeShift::sole()->branch_id,
            'work_date' => '2026-10-13', 'status' => 'present']);
        app(TenantContext::class)->clear();
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(1, $countReminders());

        // Feriado: nada
        Carbon::setTestNow(Carbon::parse('2026-10-14 07:56', 'America/Lima'));
        Holiday::create(['tenant_id' => $this->acme->id, 'date' => '2026-10-14', 'name' => 'Feriado']);
        app(TenantContext::class)->clear();
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(1, $countReminders());

        // El programador se atrasó: se avisa igual hasta una hora pasada la tolerancia, con el texto de "ya empezó"
        Carbon::setTestNow(Carbon::parse('2026-10-15 08:20', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(2, $countReminders());
        $this->assertSame('¿Ya marcaste tu entrada?', collect($this->pushes())->where('data.type', 'attendance.entry_reminder')->last()['notification']['title']);

        // Más de una hora tarde: ya no se recuerda
        Carbon::setTestNow(Carbon::parse('2026-10-16 09:30', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(2, $countReminders());
        Carbon::setTestNow();
    }

    public function test_exit_reminder_five_minutes_after_shift_end_when_not_clocked_out(): void
    {
        $shift = $this->assign('08:00');
        $this->fakeFcm();
        $countReminders = fn () => collect($this->pushes())->where('data.type', 'attendance.exit_reminder')->count();
        $open = fn (string $day, ?Shift $s, string $in) => Attendance::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id,
            'branch_id' => EmployeeShift::sole()->branch_id, 'shift_id' => $s?->id, 'work_date' => $day, 'status' => 'present', 'clock_in_at' => "{$day} {$in}"]);

        $att = $open('2026-10-12', $shift, '07:58:00');
        app(TenantContext::class)->clear();

        // Sale a las 17:00: a las 17:04 nada, a las 17:05 un aviso, una sola vez
        Carbon::setTestNow(Carbon::parse('2026-10-12 17:04', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(0, $countReminders());
        Carbon::setTestNow(Carbon::parse('2026-10-12 17:05', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(1, $countReminders());
        $this->assertStringContainsString('05:00 pm', collect($this->pushes())->firstWhere('data.type', 'attendance.exit_reminder')['notification']['body']);

        // Si ya marcó salida: nada
        $att->update(['clock_out_at' => '2026-10-12 17:30:00']);
        $att2 = $open('2026-10-13', $shift, '08:00:00');
        $att2->update(['clock_out_at' => '2026-10-13 16:50:00']);
        app(TenantContext::class)->clear();
        Carbon::setTestNow(Carbon::parse('2026-10-13 17:06', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(1, $countReminders());

        // Turno nocturno 22:00 a 06:00: el aviso llega a las 06:05 del día siguiente
        $night = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Noche', 'start_time' => '22:00', 'end_time' => '06:00',
            'crosses_midnight' => true, 'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
        $open('2026-10-14', $night, '21:57:00');
        app(TenantContext::class)->clear();
        Carbon::setTestNow(Carbon::parse('2026-10-14 22:30', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-10-15 06:05', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(2, $countReminders());

        // Sin turno: entrada + 8 h + 45 min de descanso
        $open('2026-10-16', null, '09:00:00');
        app(TenantContext::class)->clear();
        Carbon::setTestNow(Carbon::parse('2026-10-16 17:49', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(2, $countReminders());
        Carbon::setTestNow(Carbon::parse('2026-10-16 17:50', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(3, $countReminders());

        // Quien apagó los recordatorios no recibe el push
        $this->emp->update(['notification_preferences' => ['attendance_reminders' => false]]);
        $open('2026-10-17', $shift, '08:00:00');
        app(TenantContext::class)->clear();
        Carbon::setTestNow(Carbon::parse('2026-10-17 17:05', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        $this->assertSame(3, $countReminders());
        Carbon::setTestNow();
    }

    public function test_payslip_push_goes_out_once_when_published(): void
    {
        $this->fakeFcm();
        $hr = $this->makeUser($this->acme, 'hr_manager');
        $payslipPushes = fn () => collect($this->pushes())->where('data.type', 'payslip.published')->values();

        // Borrador: nada; al publicar: un push al colaborador; volver a guardar no lo repite
        $payslip = \App\Modules\Payroll\Models\Payslip::create([
            'tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'uploaded_by' => $hr->id, 'title' => 'Boleta',
            'period_month' => 10, 'period_year' => 2026, 'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'status' => 'draft',
        ]);
        $this->assertCount(0, $payslipPushes());
        $payslip->update(['status' => 'published', 'published_at' => now()]);
        $payslip->update(['notes' => 'Corregida']);
        $this->assertCount(1, $payslipPushes());
        $this->assertSame('device-token-123', $payslipPushes()[0]['token']);
        $this->assertStringContainsString('10/2026', $payslipPushes()[0]['notification']['body']);

        // Carga masiva con "publicar ya": push inmediato al crearla
        \App\Modules\Payroll\Models\Payslip::create([
            'tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'uploaded_by' => $hr->id, 'title' => 'Boleta',
            'period_month' => 11, 'period_year' => 2026, 'file_path' => 'y.pdf', 'file_name' => 'y.pdf', 'status' => 'published', 'published_at' => now(),
        ]);
        $this->assertCount(2, $payslipPushes());
    }

    public function test_birthday_push_in_the_morning_to_celebrant_and_colleagues(): void
    {
        $this->fakeFcm();
        $mate = $this->makeUser($this->acme, 'employee', ['fcm_token' => 'mate-token']);
        $this->emp->update(['birth_date' => '1994-10-12']);
        \App\Modules\Birthdays\Services\BirthdaySettings::save($this->acme, ['notify_colleagues' => 'company', 'greet_celebrant' => true, 'hour' => 8]);
        app(TenantContext::class)->clear();
        $types = fn () => collect($this->pushes())->pluck('data.type')->filter(fn ($t) => str_starts_with((string) $t, 'birthday.'))->values()->all();

        // Antes de la hora configurada (hora de Lima): nada
        Carbon::setTestNow(Carbon::parse('2026-10-12 07:05', 'America/Lima'));
        $this->artisan('birthdays:notify')->assertSuccessful();
        $this->assertSame([], $types());

        // 08:05: saludo al cumpleañero y aviso al compañero, una sola vez
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:05', 'America/Lima'));
        $this->artisan('birthdays:notify')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:05', 'America/Lima'));
        $this->artisan('birthdays:notify')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['birthday.greeting', 'birthday.colleague'], $types());
        $colleague = collect($this->pushes())->firstWhere('data.type', 'birthday.colleague');
        $this->assertSame('mate-token', $colleague['token']);
        $this->assertStringContainsString('regalo', $colleague['notification']['body']);
        Carbon::setTestNow();
    }

    public function test_scheduled_tasks_run_on_lima_time(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->assertNotEmpty($events);
        $this->assertSame(['America/Lima'], $events->map(fn ($e) => (string) $e->timezone)->unique()->values()->all());

        $byCommand = fn (string $name) => $events->first(fn ($e) => str_contains((string) $e->command, $name));
        $this->assertSame('* * * * *', $byCommand('attendance:remind-entry')->expression); // ±5 min al minuto
        $this->assertSame('5 * * * *', $byCommand('birthdays:notify')->expression);
    }

    private function fakeFcm(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3599]),
            'fcm.googleapis.com/*'        => Http::response(['name' => 'ok']),
        ]);
    }

    public function test_entry_reminder_and_update_are_push_only(): void
    {
        $shift = $this->assign();
        $this->assertSame([FcmChannel::class], (new \App\Modules\Attendance\Notifications\EntryReminder($shift, '2026-10-12'))->via($this->emp));
        $this->assertSame([FcmChannel::class], (new \App\Modules\Core\Notifications\AppUpdateAvailable('1.0.0'))->via($this->emp));
        $this->assertSame([], (new \App\Modules\Core\Notifications\AppUpdateAvailable('1.0.0'))->via($this->makeUser($this->acme)));
    }

    public function test_panel_saves_the_download_link(): void
    {
        $admin = $this->makeUser($this->acme, 'company_admin');
        $this->actingAs($admin)->put('/settings/branding', ['update_url' => 'http://inseguro.test/app.apk'])
            ->assertSessionHasErrors('update_url');
        $this->actingAs($admin)->put('/settings/branding', ['update_url' => 'https://tunesa.app/descargar'])
            ->assertSessionHasNoErrors();

        $this->getJson('/api/v1/app/config?tenant_slug=acme')->assertOk()
            ->assertJsonPath('data.mobile.update_url', 'https://tunesa.app/descargar');
    }
}
