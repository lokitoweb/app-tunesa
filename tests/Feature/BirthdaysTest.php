<?php

namespace Tests\Feature;

use App\Modules\Birthdays\Models\BirthdayGreeting;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Cumpleaños: por mes, próximos, privacidad (sin año), avisos únicos y ajustes */
class BirthdaysTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private Branch $lima;

    private Branch $cusco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->acme  = $this->makeTenant('acme');
        $this->lima  = $this->makeBranch($this->acme, ['name' => 'Lima']);
        $this->cusco = $this->makeBranch($this->acme, ['name' => 'Cusco']);
        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $name, ?string $birth, Branch $branch, string $role = 'employee'): User
    {
        $user = $this->makeUser($this->acme, $role, ['first_name' => $name, 'last_name' => 'Pérez', 'birth_date' => $birth, 'position' => 'Analista']);
        $user->branches()->attach($branch->id, ['is_primary' => true]);
        app(TenantContext::class)->clear();

        return $user;
    }

    public function test_birth_md_is_kept_in_sync(): void
    {
        $ana = $this->person('Ana', '1990-02-14', $this->lima);
        $this->assertSame(214, $ana->fresh()->birth_md);
        $ana->update(['birth_date' => '1985-12-03']);
        $this->assertSame(1203, $ana->fresh()->birth_md);
        $ana->update(['birth_date' => null]);
        $this->assertNull($ana->fresh()->birth_md);
    }

    public function test_month_view_splits_today_upcoming_and_past_without_year(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 2, 10, 9, 0, 0, 'America/Lima'));
        $me    = $this->person('Yo', '1991-07-01', $this->lima);
        $this->person('Pasado', '1990-02-05', $this->lima);
        $this->person('Hoy', '1992-02-10', $this->cusco);
        $this->person('Luego', '1993-02-20', $this->lima);
        $this->person('Marzo', '1994-03-01', $this->lima);

        $res = $this->actingIn($me, $this->acme)->getJson('/api/birthdays?month=2')->assertOk();
        $res->assertJsonPath('data.month_name', 'Febrero')
            ->assertJsonPath('data.today.0.first_name', 'Hoy')
            ->assertJsonPath('data.today.0.is_today', true)
            ->assertJsonPath('data.upcoming.0.first_name', 'Luego')
            ->assertJsonPath('data.upcoming.0.date_label', '20 de febrero')
            ->assertJsonPath('data.past.0.first_name', 'Pasado');
        $this->assertStringNotContainsString('1993', $res->getContent(), 'Nunca se expone el año de nacimiento');

        // Mi equipo: solo quienes comparten sucursal (Lima), sin mí
        $team = collect($res->json('data.team'))->pluck('first_name')->all();
        $this->assertSame(['Luego', 'Marzo', 'Pasado'], $team);

        $this->getJson('/api/birthdays/upcoming?days=30&limit=2')->assertOk()
            ->assertJsonPath('data.0.first_name', 'Hoy')->assertJsonPath('data.1.first_name', 'Luego')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.today', 1);
    }

    public function test_leap_day_birthdays_are_celebrated_on_feb_28(): void
    {
        Carbon::setTestNow(Carbon::create(2027, 2, 28, 9, 0, 0, 'America/Lima'));
        $leap = $this->person('Bisiesto', '2000-02-29', $this->lima);
        $me   = $this->person('Yo', '1990-05-05', $this->lima);

        $this->actingIn($me, $this->acme)->getJson('/api/birthdays/upcoming?days=1')->assertOk()
            ->assertJsonPath('data.0.first_name', 'Bisiesto')->assertJsonPath('data.0.is_today', true);
    }

    public function test_daily_notification_is_sent_once_to_celebrant_and_branch_colleagues(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0, 'America/Lima'));
        $ana   = $this->person('Ana', '1990-06-15', $this->lima);
        $mate  = $this->person('Compañero', '1990-01-01', $this->lima);
        $far   = $this->person('Lejano', '1990-01-02', $this->cusco);

        $this->artisan('birthdays:notify')->expectsOutputToContain('Ana Pérez')->assertSuccessful();
        $this->artisan('birthdays:notify')->assertSuccessful();

        $greet = $ana->notifications()->where('type', 'birthday.greeting')->sole();
        $this->assertSame('🎉 ¡Feliz cumpleaños, Ana!', $greet->data['title']);
        $this->assertSame(1, $mate->notifications()->where('type', 'birthday.colleague')->count());
        $this->assertStringContainsString('Ana Pérez', $mate->notifications()->first()->data['title']);
        $this->assertSame(0, $far->notifications()->count());
        $this->assertSame(1, BirthdayGreeting::where('user_id', $ana->id)->where('year', 2026)->value('colleagues_notified'));
    }

    public function test_notification_waits_for_configured_hour_and_respects_scope_and_module(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 6, 0, 0, 'America/Lima'));
        $ana  = $this->person('Ana', '1990-06-15', $this->lima);
        $far  = $this->person('Lejano', '1990-01-02', $this->cusco);

        $this->artisan('birthdays:notify');
        $this->assertSame(0, $ana->notifications()->count(), 'Antes de las 8:00 no se avisa');

        $this->acme->update(['settings' => ['birthdays' => ['notify_colleagues' => 'company', 'greet_celebrant' => false, 'hour' => 6]]]);
        $this->artisan('birthdays:notify');
        $this->assertSame(0, $ana->notifications()->count());
        $this->assertSame(1, $far->notifications()->count());

        // Módulo apagado: nada
        $other = $this->makeTenant('globex', ['settings' => ['disabled_modules' => ['Birthdays']]]);
        $bob = $this->makeUser($other, 'employee', ['birth_date' => '1990-06-15']);
        $this->artisan('birthdays:notify');
        $this->assertSame(0, $bob->notifications()->count());
    }

    public function test_panel_month_view_and_settings(): void
    {
        $admin = $this->makeUser($this->acme, 'company_admin');
        $this->person('Ana', '1990-03-14', $this->lima);
        $this->person('SinFecha', null, $this->lima);

        $this->actingAs($admin)->get('/birthdays?month=3')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Birthdays/Pages/Birthdays/Index')->has('people', 1)->where('missing', 1)->where('months.2.count', 1));
        $this->put('/birthdays/settings', ['notify_colleagues' => 'none', 'greet_celebrant' => true, 'hour' => 9])->assertSessionHasNoErrors();
        $this->assertSame('none', $this->acme->fresh()->setting('birthdays.notify_colleagues'));
        $this->get('/birthdays/export?month=3')->assertOk();

        $supervisor = $this->makeUser($this->acme, 'supervisor');
        app(TenantContext::class)->clear();
        $this->actingAs($supervisor)->get('/birthdays')->assertForbidden();
    }

    public function test_employee_form_saves_birth_date(): void
    {
        $admin = $this->makeUser($this->acme, 'company_admin');
        $emp = $this->person('Ana', null, $this->lima);

        $this->actingAs($admin)->get("/employees/{$emp->id}/edit")->assertOk()->assertSee('birth_date', false);
    }
}
