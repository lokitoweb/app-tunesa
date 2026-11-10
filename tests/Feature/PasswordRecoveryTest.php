<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PasswordHelpRequested;
use App\Modules\Core\Notifications\PasswordResetLinkMail;
use App\Modules\Core\Services\PasswordRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * "¿Olvidaste tu contraseña?" (diseño aprobado en la auditoría): enlace de un solo uso por correo que vence
 * en 30 minutos; la contraseña cambia recién al confirmarla; respuesta neutra; sin tomar ni bloquear cuentas.
 */
class PasswordRecoveryTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->acme = $this->makeTenant('acme');
        $this->ana  = $this->makeUser($this->acme, 'hr_manager', ['email' => 'ana@acme.pe', 'password' => 'ClaveVieja123']);
        // Servidor con correo real (SMTP); los envíos igual quedan interceptados en cada prueba
        config(['mail.default' => 'smtp']);
    }

    /** El enlace que llegó por correo (su token) */
    private function mailedToken(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, PasswordResetLinkMail::class, function (PasswordResetLinkMail $n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_the_link_changes_the_password_only_when_confirmed_and_works_once(): void
    {
        Notification::fake();
        $this->post(route('password.email'), ['email' => 'ANA@acme.pe'])
            ->assertRedirect(route('password.request'))->assertSessionHas('status', PasswordRecovery::RESPONSE);

        // Pedirlo no cambia nada: la contraseña actual sigue sirviendo y no se guarda el token en claro
        $token = $this->mailedToken($this->ana);
        $this->assertTrue(Hash::check('ClaveVieja123', $this->ana->fresh()->password));
        $this->assertFalse(DB::table('password_reset_tokens')->where('token', $token)->exists());
        $this->assertTrue(DB::table('password_reset_tokens')->where('token', hash('sha256', $token))->exists());

        $this->get(route('password.reset', $token))->assertOk()->assertSee('Crea tu nueva contraseña');
        // Misma política que el resto: sin mayúsculas y números no pasa
        $this->post(route('password.update', $token), ['password' => 'debil', 'password_confirmation' => 'debil'])->assertSessionHasErrors('password');
        $this->post(route('password.update', $token), ['password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])
            ->assertRedirect(route('login'));

        $ana = $this->ana->fresh();
        $this->assertTrue(Hash::check('NuevaClave2026', $ana->password));
        $this->assertFalse(Hash::check('ClaveVieja123', $ana->password));
        $this->assertFalse((bool) $ana->must_change_password);
        $this->assertTrue(AuditLog::where('action', 'auth.password_recovered')->exists());

        // Un solo uso: el mismo enlace ya no sirve
        $this->get(route('password.reset', $token))->assertOk()->assertSee('Este enlace ya no sirve');
        $this->post(route('password.update', $token), ['password' => 'OtraClave2026', 'password_confirmation' => 'OtraClave2026'])
            ->assertRedirect(route('password.request'));
        $this->assertTrue(Hash::check('NuevaClave2026', $this->ana->fresh()->password));
    }

    public function test_link_expires_after_thirty_minutes_and_a_new_request_invalidates_the_previous_one(): void
    {
        Notification::fake();
        $this->post(route('password.email'), ['email' => 'ana@acme.pe']);
        $old = $this->mailedToken($this->ana);

        $this->travel(31)->minutes();
        $this->assertNull(app(PasswordRecovery::class)->userForToken($old));

        // Pasado el límite por persona, un pedido nuevo da otro enlace y el anterior queda inválido
        $this->travel(16)->minutes();
        Notification::fake();
        $this->post(route('password.email'), ['email' => 'ana@acme.pe']);
        $new = $this->mailedToken($this->ana);
        $this->assertNotSame($old, $new);
        $this->assertFalse(app(PasswordRecovery::class)->reset($old, 'NuevaClave2026'));
        $this->assertTrue(app(PasswordRecovery::class)->reset($new, 'NuevaClave2026'));
    }

    public function test_same_answer_for_unknown_or_inactive_and_one_request_every_fifteen_minutes(): void
    {
        Notification::fake();
        $this->post(route('password.email'), ['email' => 'nadie@acme.pe'])->assertSessionHas('status', PasswordRecovery::RESPONSE);

        $this->makeUser($this->acme, 'employee', ['email' => 'baja@acme.pe', 'status' => 'inactive']);
        $this->post(route('password.email'), ['email' => 'baja@acme.pe'])->assertSessionHas('status', PasswordRecovery::RESPONSE);
        Notification::assertNothingSent();

        // Pedidos repetidos (masivos) no generan más correos ni tocan la cuenta
        foreach (range(1, 3) as $i) {
            $this->post(route('password.email'), ['email' => 'ana@acme.pe'])->assertSessionHas('status', PasswordRecovery::RESPONSE);
        }
        Notification::assertSentToTimes($this->ana, PasswordResetLinkMail::class, 1);
        $this->assertTrue(Hash::check('ClaveVieja123', $this->ana->fresh()->password));

        $this->travel(16)->minutes();
        $this->post(route('password.email'), ['email' => 'ana@acme.pe']);
        Notification::assertSentToTimes($this->ana, PasswordResetLinkMail::class, 2);
    }

    public function test_if_the_mail_fails_nothing_changes_and_hr_is_asked(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationSending::class, function ($e) {
            if ($e->notification instanceof PasswordResetLinkMail) {
                throw new \RuntimeException('SMTP caído');
            }
        });
        $boss = $this->makeUser($this->acme, 'hr_manager');

        $this->post(route('password.email'), ['email' => 'ana@acme.pe'])->assertSessionHas('status', PasswordRecovery::RESPONSE);

        $this->assertTrue($boss->notifications()->where('type', (new PasswordHelpRequested($this->ana))->type())->exists());
        $this->assertTrue(Hash::check('ClaveVieja123', $this->ana->fresh()->password));
        $this->assertFalse(DB::table('password_reset_tokens')->exists()); // sin enlace huérfano
    }

    /** Producción sin correo (MAIL_MAILER=log): no hay enlace (nunca llegaría) y RR. HH. recibe el pedido */
    public function test_without_a_real_mail_server_no_link_is_made_and_hr_is_asked(): void
    {
        config(['mail.default' => 'log']);
        $boss = $this->makeUser($this->acme, 'hr_manager');

        $this->post(route('password.email'), ['email' => 'ana@acme.pe'])->assertSessionHas('status', PasswordRecovery::RESPONSE);

        $this->assertTrue(Hash::check('ClaveVieja123', $this->ana->fresh()->password));
        $this->assertFalse(DB::table('password_reset_tokens')->exists());
        $this->assertTrue($boss->notifications()->where('type', (new PasswordHelpRequested($this->ana))->type())->exists());

        // Con SMTP propio de la empresa (Ajustes) sí sale, aunque el servidor esté en "log"
        \App\Modules\Core\Models\TenantSetting::editableRowFor($this->acme)->fill(['smtp_host' => 'smtp.acme.pe', 'smtp_username' => 'no-reply@acme.pe'])->save();
        $this->assertTrue(PasswordRecovery::mailDelivers($this->ana->fresh()));
    }

    public function test_the_app_endpoint_sends_it_too_and_company_domains_only_serve_their_users(): void
    {
        Notification::fake();
        $employee = $this->makeUser($this->acme, 'employee', ['email' => 'rosa@acme.pe']);
        $this->postJson('/api/v1/auth/password-help', ['email' => 'rosa@acme.pe'])->assertOk()
            ->assertJsonPath('message', PasswordRecovery::RESPONSE);
        Notification::assertSentTo($employee, PasswordResetLinkMail::class);

        // En el dominio de otra empresa no se atiende a Ana
        $other = $this->makeTenant('otra');
        app(PasswordRecovery::class)->sendResetLink('ana@acme.pe', $other->id);
        Notification::assertNotSentTo($this->ana, PasswordResetLinkMail::class);
    }
}
