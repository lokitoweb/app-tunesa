<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Notifications\SecurityAlert;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Integrations\Notifications\Channels\FcmChannel;
use App\Modules\Integrations\Services\Push\FcmSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Mi cuenta: la persona decide qué push recibe, ve y cierra sus sesiones y
 * recibe un aviso cuando entran desde un dispositivo nuevo o cambian su clave.
 */
class AccountControlTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function login(string $device)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-Tenant-Slug' => 'acme', 'Accept' => 'application/json'])
            ->postJson('/api/auth/login', ['email' => 'ana@acme.pe', 'password' => 'Secret123', 'device_name' => $device]);
    }

    private function securityAlerts(User $user): array
    {
        return $user->notifications()->get()->pluck('data.type')->filter(fn ($t) => str_starts_with($t, 'security.'))->values()->all();
    }

    public function test_login_from_a_new_device_warns_the_user_but_not_the_first_or_same_device(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.pe']);
        app(TenantContext::class)->clear();

        $this->login('Pixel 8')->assertOk();   // primer ingreso: nada que avisar
        $this->login('Pixel 8')->assertOk();   // mismo equipo: tampoco
        $this->assertSame([], $this->securityAlerts($ana));

        $this->login('iPhone de alguien')->assertOk();
        $this->assertSame(['security.new_device'], $this->securityAlerts($ana));
        $this->assertStringContainsString('iPhone de alguien', $ana->notifications()->first()->data['body']);

        // La app manda app_<plataforma>_<instalación>: el aviso lo nombra legible
        $this->login('app_android_9f2c')->assertOk();
        $this->assertSame(1, $ana->notifications()->get()->filter(fn ($n) => str_contains($n->data['body'], 'Teléfono Android'))->count());
    }

    public function test_password_change_from_the_app_follows_the_company_setting(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.pe', 'must_change_password' => true]);
        app(TenantContext::class)->clear();

        $change = fn (string $from, string $to) => $this->actingIn($ana->fresh(), $acme)->putJson('/api/auth/change-password', [
            'current_password' => $from, 'new_password' => $to, 'new_password_confirmation' => $to,
        ]);

        $change('Secret123', 'Nueva1234')->assertOk();
        $this->assertSame([], $this->securityAlerts($ana));

        // Cambio voluntario desde Mi perfil: permitido por defecto
        $change('Nueva1234', 'Otra12345')->assertOk();
        $change('Equivocada1', 'Otra99999')->assertStatus(422)->assertJsonValidationErrors('current_password');

        // Si la empresa lo apaga en el panel, solo RRHH la restablece
        \App\Modules\Core\Models\TenantSetting::query()->updateOrCreate(['tenant_id' => $acme->id], ['self_password_change' => false]);
        $change('Otra12345', 'Final12345')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_BY_ADMIN');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Otra12345', $ana->fresh()->password));
    }

    public function test_logout_others_keeps_only_the_current_device(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee');
        $ana->createToken('tablet');
        $ana->createToken('pc');

        $this->actingIn($ana, $acme)->postJson('/api/auth/logout-others')->assertOk()->assertJsonPath('data.closed', 2);

        $this->assertSame(['test'], $ana->tokens()->pluck('name')->all());
        $this->getJson('/api/auth/sessions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.current', true)->assertJsonPath('data.0.label', 'test');
    }

    public function test_notification_preferences_turn_off_push_but_keep_the_inbox(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['fcm_token' => 'device-1']);
        app(TenantContext::class)->clear();

        $prefs = $this->actingIn($ana, $acme)->getJson('/api/auth/notification-preferences')->assertOk()->json('data');
        $this->assertTrue(collect($prefs)->every(fn ($p) => $p['enabled']));
        $this->assertContains('payslips', array_column($prefs, 'key'));

        $this->putJson('/api/auth/notification-preferences', ['payslips' => false, 'hackeo' => false])
            ->assertOk()
            ->assertJsonPath('data.0.key', 'payslips')
            ->assertJsonPath('data.0.enabled', false);
        $this->assertSame(['payslips' => false], $ana->fresh()->notification_preferences); // claves desconocidas fuera

        $this->putJson('/api/auth/notification-preferences', ['trainings' => 'quizás'])->assertUnprocessable();

        // Con FCM listo: la boleta ya no va por push, pero sí queda en la bandeja; seguridad sí llega
        $this->mock(FcmSender::class)->shouldReceive('canSendTo')->andReturnTrue();
        $fresh   = $ana->fresh();
        $payslip = new class extends AppNotification {
            public function type(): string { return 'payslip.published'; }
            public function title(): string { return 'Boleta'; }
            public function body(): string { return 'Nueva boleta'; }
            public function payload(): array { return []; }
        };
        $this->assertSame(['database'], $payslip->via($fresh));
        $this->assertContains(FcmChannel::class, (new SecurityAlert('password_changed'))->via($fresh));
        $this->assertContains(FcmChannel::class, $payslip->via($this->makeUser($acme, 'employee'))); // otra persona sí lo recibe
    }
}
