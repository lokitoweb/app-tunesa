<?php

namespace Tests\Feature;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Finance\Models\Vehicle;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Notifications\ExpiryDigest;
use App\Modules\HR\Services\ExpiryAlerts;
use App\Modules\HR\Services\ExpiryWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** RR. HH. › Vencimientos: tablero y alertas por correo y Telegram */
class ExpiryAlertsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function doc($tenant, $user, string $type, ?string $expires): EmployeeDocument
    {
        return EmployeeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'type' => $type, 'path' => "x/{$type}.pdf", 'original_name' => "{$type}.pdf",
            'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'status' => 'approved', 'expires_at' => $expires,
        ]);
    }

    public function test_dashboard_gathers_documents_contracts_and_vehicles_of_the_company_only(): void
    {
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-01 09:00:00', 'America/Lima'));
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $hr = $this->makeUser($acme, 'hr_manager');
        $ana = $this->makeUser($acme, 'employee', ['first_name' => 'Ana', 'termination_date' => '2026-10-16']);
        $gone = $this->makeUser($acme, 'employee', ['status' => 'inactive']);
        $this->doc($acme, $ana, 'medical_exam', '2026-10-08');
        $this->doc($acme, $ana, 'driver_license', '2026-09-20');          // ya vencida
        $this->doc($acme, $gone, 'medical_exam', '2026-10-05');           // ya no trabaja: no se avisa
        $this->doc($other, $this->makeUser($other), 'medical_exam', '2026-10-05');
        Vehicle::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'plate' => 'ABC-123', 'type' => 'BUS', 'soat_expires_at' => '2026-10-31']);

        $items = app(ExpiryWatch::class)->items($acme, 60);
        $this->assertSame(
            [['document', -11], ['document', 7], ['contract', 15], ['vehicle', 30]],
            $items->map(fn ($i) => [$i['kind'], $i['days_left']])->all(),
        );

        $this->actingAs($hr)->get('/expirations')->assertOk()->assertInertia(fn ($p) => $p->component('HR/Pages/Expirations/Index')
            ->where('counts.expired', 1)->where('counts.week', 1)->has('items', 4)->whereNot('settings', null));
        $this->actingAs($this->makeUser($acme, 'manager'))->get('/expirations')->assertOk()->assertInertia(fn ($p) => $p->where('settings', null));
        // Un colaborador no entra (el panel lo redirige o lo rechaza, nunca le muestra el tablero)
        $this->assertContains($this->actingAs($ana)->get('/expirations')->status(), [302, 403]);
    }

    public function test_alerts_go_by_email_and_telegram_only_on_the_configured_days_and_once_a_day(): void
    {
        Notification::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-01 07:30:00', 'America/Lima'));
        $acme = $this->makeTenant('acme');
        $ana = $this->makeUser($acme);
        $this->doc($acme, $ana, 'medical_exam', '2026-10-08');   // 7 días: se avisa
        $this->doc($acme, $ana, 'driver_license', '2026-10-10'); // 9 días: hoy no
        TenantSetting::editableRowFor($acme)->fill([
            'expiry_alert_emails' => 'rrhh@acme.pe, gerencia@acme.pe', 'expiry_alert_telegram_chats' => '123456789, -1001234567890',
            'expiry_alert_days' => '30,15,7,1,0', 'telegram_bot_token' => '123456:' . str_repeat('A', 35),
        ])->save();

        $r = app(ExpiryAlerts::class)->send($acme);
        $this->assertSame(['items' => 1, 'email' => true, 'telegram' => 2], $r);
        Notification::assertSentOnDemand(ExpiryDigest::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === ['rrhh@acme.pe', 'gerencia@acme.pe']
            && $n->items->count() === 1);
        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/bot123456:') && $req['chat_id'] === '123456789' && str_contains($req['text'], 'vence en 7 días'));

        // Otra corrida el mismo día no repite
        $this->assertSame('ya se avisó hoy', app(ExpiryAlerts::class)->send($acme)['skipped']);
        Http::assertSentCount(2);
    }

    public function test_a_telegram_failure_does_not_stop_the_email_and_without_recipients_nothing_is_sent(): void
    {
        Notification::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 400)]);
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-01 07:30:00', 'America/Lima'));
        $acme = $this->makeTenant('acme');
        $this->doc($acme, $this->makeUser($acme), 'medical_exam', '2026-10-01');   // vence hoy

        $this->assertSame('sin destinatarios', app(ExpiryAlerts::class)->send($acme)['skipped']);

        TenantSetting::editableRowFor($acme)->fill(['expiry_alert_emails' => 'rrhh@acme.pe', 'expiry_alert_telegram_chats' => '123456789',
            'telegram_bot_token' => '123456:' . str_repeat('B', 35)])->save();
        Cache::flush();
        $r = app(ExpiryAlerts::class)->send($acme);
        $this->assertTrue($r['email']);
        $this->assertSame(0, $r['telegram']);
        Notification::assertSentOnDemand(ExpiryDigest::class);
    }

    public function test_settings_are_validated_per_field_and_only_managers_change_them(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');

        $this->actingAs($hr)->put('/expirations/settings', ['emails' => 'rrhh@acme.pe, no-es-correo', 'days' => '30,7'])->assertSessionHasErrors('emails');
        $this->put('/expirations/settings', ['chats' => 'hola', 'days' => '30,7'])->assertSessionHasErrors('chats');
        $this->put('/expirations/settings', ['days' => 'treinta'])->assertSessionHasErrors('days');
        $this->put('/expirations/settings', ['emails' => 'rrhh@acme.pe;gerencia@acme.pe', 'chats' => '123456789', 'days' => '15, 7, 0'])->assertSessionHasNoErrors();
        $this->assertSame(['emails' => ['rrhh@acme.pe', 'gerencia@acme.pe'], 'chats' => ['123456789'], 'days' => [15, 7, 0]],
            array_intersect_key(TenantSetting::forTenant($acme)->expiryAlerts(), array_flip(['emails', 'chats', 'days'])));

        $this->actingAs($this->makeUser($acme, 'manager'))->put('/expirations/settings', ['days' => '1'])->assertForbidden();
    }

    public function test_vehicle_soat_and_review_dates_are_optional_and_saved(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $logistics = $this->makeUser($acme, 'logistics');

        $this->actingAs($logistics)->post('/vehicles', ['plate' => 'abc-123', 'type' => 'BUS'])->assertSessionHasNoErrors();
        $v = Vehicle::withoutGlobalScopes()->sole();
        $this->assertNull($v->soat_expires_at);

        $this->put("/vehicles/{$v->id}", ['plate' => 'ABC-123', 'type' => 'BUS', 'soat_expires_at' => '2026-12-31', 'technical_review_expires_at' => '2027-03-01'])->assertSessionHasNoErrors();
        $this->assertSame(['2026-12-31', '2027-03-01'], [$v->fresh()->soat_expires_at->toDateString(), $v->fresh()->technical_review_expires_at->toDateString()]);
        $this->put("/vehicles/{$v->id}", ['plate' => 'ABC-123', 'type' => 'BUS', 'soat_expires_at' => 'mañana'])->assertSessionHasErrors('soat_expires_at');
    }

    public function test_a_medical_exam_without_expiry_date_is_saved_with_a_warning(): void
    {
        Storage::fake('private');
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $ana = $this->makeUser($acme);
        $pdf = UploadedFile::fake()->createWithContent('examen.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF");

        $this->actingAs($hr)->post("/employees/{$ana->id}/file/documents", ['type' => 'medical_exam', 'file' => $pdf])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'no se enviará alerta'));
        $this->assertSame('medical_exam', EmployeeDocument::withoutGlobalScopes()->sole()->type);
    }
}
