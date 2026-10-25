<?php

namespace Tests\Feature;

use App\Modules\Birthdays\Models\BirthdayGift;
use App\Modules\Birthdays\Notifications\BirthdayGiftReceived;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Regalos animados de cumpleaños: solo el día (hora de Perú), no a uno mismo,
 * un mismo regalo una vez por remitente, y después historial sin animación.
 * También: Inicio muestra los próximos 7 días igual que la pantalla completa.
 */
class BirthdayGiftTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'America/Lima'));
        app(TenantContext::class)->clear();
    }

    public function test_gifts_only_on_the_birthday_in_peru_time_then_static_history(): void
    {
        Notification::fake();
        $this->seedRoles();
        $acme   = $this->makeTenant('acme', ['timezone' => 'America/Lima']);
        $ana    = $this->makeUser($acme, 'employee', ['birth_date' => '1995-06-15', 'gender' => 'female', 'first_name' => 'Ana']);
        $luis   = $this->makeUser($acme, 'employee', ['birth_date' => '1990-01-10', 'first_name' => 'Luis']);
        $other  = $this->makeTenant('other');
        $stranger = $this->makeUser($other, 'employee', ['birth_date' => '1990-01-10']);

        // 14/06 23:30 en Lima (ya 15/06 en UTC): todavía no es su cumpleaños
        $this->at('2026-06-14 23:30');
        $this->actingIn($luis, $acme)->postJson("/api/birthdays/{$ana->id}/gifts", ['type' => 'lion'])
            ->assertStatus(422)->assertJsonPath('code', 'GIFT_EXPIRED');

        // 15/06 en Lima: se puede enviar
        $this->at('2026-06-15 09:00');
        $this->actingIn($luis, $acme)->getJson("/api/birthdays/{$ana->id}/gifts")->assertOk()
            ->assertJsonPath('data.can_send', true)
            ->assertJsonCount(6, 'data.catalog');
        $this->actingIn($luis, $acme)->postJson("/api/birthdays/{$ana->id}/gifts", ['type' => 'lion'])
            ->assertCreated()->assertJsonPath('data.sender.first_name', 'Luis')->assertJsonPath('data.playable', true);
        Notification::assertSentTo($ana, BirthdayGiftReceived::class);

        // Mismo regalo otra vez → 409; otro regalo sí; tipo inexistente → 422
        $this->actingIn($luis, $acme)->postJson("/api/birthdays/{$ana->id}/gifts", ['type' => 'lion'])->assertStatus(409);
        $this->actingIn($luis, $acme)->postJson("/api/birthdays/{$ana->id}/gifts", ['type' => 'crown'])->assertCreated();
        $this->actingIn($luis, $acme)->postJson("/api/birthdays/{$ana->id}/gifts", ['type' => 'dragon'])->assertStatus(422);

        // No a uno mismo, ni de otra empresa
        $this->actingIn($ana, $acme)->postJson("/api/birthdays/{$ana->id}/gifts", ['type' => 'cake'])->assertStatus(422);
        $this->actingIn($stranger, $other)->postJson("/api/birthdays/{$ana->id}/gifts", ['type' => 'cake'])->assertNotFound();

        // La cumpleañera ve sus regalos (reproducibles) y su saludo trae el género
        $this->actingIn($ana, $acme)->getJson("/api/birthdays/{$ana->id}/gifts")->assertOk()
            ->assertJsonPath('data.can_send', false)
            ->assertJsonCount(2, 'data.gifts')
            ->assertJsonPath('data.gifts.0.playable', true);
        $this->actingIn($ana, $acme)->getJson('/api/birthdays/upcoming')->assertOk()
            ->assertJsonPath('meta.greeting.gender', 'female');

        // 16/06 00:01 en Lima: caducó, queda como historial sin animación
        $this->at('2026-06-16 00:01');
        $this->actingIn($luis, $acme)->postJson("/api/birthdays/{$ana->id}/gifts", ['type' => 'cake'])->assertStatus(422);
        $this->actingIn($ana, $acme)->getJson("/api/birthdays/{$ana->id}/gifts")->assertOk()
            ->assertJsonPath('data.is_today', false)
            ->assertJsonPath('data.can_send', false)
            ->assertJsonPath('data.date', '2026-06-15')
            ->assertJsonCount(2, 'data.gifts')
            ->assertJsonPath('data.gifts.0.playable', false);

        $this->assertSame(2, BirthdayGift::withoutGlobalScopes()->count());
    }

    public function test_home_shows_next_seven_days_like_the_full_screen_across_branches(): void
    {
        $this->seedRoles();
        $acme   = $this->makeTenant('acme', ['timezone' => 'America/Lima']);
        $viewer = $this->makeUser($acme, 'employee', ['birth_date' => '1990-03-01']);
        $north  = $this->makeBranch($acme, ['name' => 'Norte']);
        $viewer->branches()->attach($north->id, ['is_primary' => true]);
        // Otra sede y el cumpleaños cae en el mes siguiente
        $rosa = $this->makeUser($acme, 'employee', ['birth_date' => '1993-11-01', 'first_name' => 'Rosa']);
        $this->makeUser($acme, 'employee', ['birth_date' => '1993-11-20']); // fuera de la semana

        $this->at('2026-10-31 10:00');
        $this->actingIn($viewer, $acme)->getJson('/api/birthdays/upcoming?days=7&scope=company&limit=5')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $rosa->id)
            ->assertJsonPath('data.0.days_until', 1);
        // La pantalla completa (mes siguiente) también la lista
        $this->actingIn($viewer, $acme)->getJson('/api/birthdays?month=11')->assertOk()
            ->assertJsonPath('data.upcoming.0.id', $rosa->id);
    }

    public function test_panel_lists_and_exports_gifts(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme', ['timezone' => 'America/Lima']);
        $hr   = $this->makeUser($acme, 'hr_manager');
        $ana  = $this->makeUser($acme, 'employee', ['birth_date' => '1995-06-15', 'first_name' => 'Ana']);
        $luis = $this->makeUser($acme, 'employee', ['first_name' => 'Luis']);
        $this->at('2026-06-15 09:00');
        BirthdayGift::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'sender_id' => $luis->id, 'gift_type' => 'space_whale', 'date' => '2026-06-15']);

        $this->actingAs($hr)->getJson('/birthdays')->assertOk()
            ->assertJsonPath('giftsToday', 1)
            ->assertJsonPath('gifts.0.label', 'Ballena espacial');
        $csv = $this->actingAs($hr)->get('/birthdays/gifts/export?month=6')->assertOk()->streamedContent();
        $this->assertStringContainsString('Ballena espacial', $csv);
    }
}
