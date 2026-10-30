<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PasswordHelpRequested;
use App\Modules\Core\Notifications\TemporaryPasswordMail;
use App\Modules\Core\Services\PasswordRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** "¿Olvidaste tu contraseña?": contraseña temporal por correo (web y app), segura y sin revelar si el correo existe */
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
    }

    /** La contraseña que llegó por correo (para probar que sirve) */
    private function mailedPassword(User $user): string
    {
        $password = null;
        Notification::assertSentTo($user, TemporaryPasswordMail::class, function (TemporaryPasswordMail $n) use ($user, &$password) {
            $line = collect($n->toMail($user)->introLines)->first(fn ($l) => str_starts_with($l, '**'));
            $password = trim((string) $line, '*');

            return true;
        });

        return $password;
    }

    public function test_web_sends_a_temporary_password_that_works_and_must_be_changed(): void
    {
        Notification::fake();
        $this->post(route('password.email'), ['email' => 'ANA@acme.pe'])
            ->assertRedirect(route('password.request'))->assertSessionHas('status', PasswordRecovery::RESPONSE);

        $password = $this->mailedPassword($this->ana);
        $this->assertSame(12, strlen($password));
        $ana = $this->ana->fresh();
        $this->assertTrue(Hash::check($password, $ana->password));
        $this->assertFalse(Hash::check('ClaveVieja123', $ana->password));
        $this->assertTrue($ana->must_change_password);
        $this->assertNotNull($ana->temp_password_expires_at);
        $log = AuditLog::where('action', 'auth.password_recovered')->sole();
        $this->assertStringNotContainsString($password, json_encode($log->meta));

        // Entra con la temporal y el panel la lleva a cambiarla
        $this->post(route('login.post'), ['email' => 'ana@acme.pe', 'password' => $password])->assertRedirect();
        $this->get(route('dashboard'))->assertRedirect(route('account.edit'));
        $this->get(route('account.edit'))->assertOk();

        $this->put(route('account.password'), ['current_password' => $password, 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])->assertSessionHasNoErrors();
        $this->assertFalse($this->ana->fresh()->must_change_password);
        $this->assertNull($this->ana->fresh()->temp_password_expires_at);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_same_answer_for_unknown_or_inactive_and_one_request_every_fifteen_minutes(): void
    {
        Notification::fake();
        $this->post(route('password.email'), ['email' => 'nadie@acme.pe'])->assertSessionHas('status', PasswordRecovery::RESPONSE);

        $gone = $this->makeUser($this->acme, 'employee', ['email' => 'baja@acme.pe', 'status' => 'inactive']);
        $this->post(route('password.email'), ['email' => 'baja@acme.pe'])->assertSessionHas('status', PasswordRecovery::RESPONSE);
        Notification::assertNothingSent();

        $this->post(route('password.email'), ['email' => 'ana@acme.pe']);
        $hash = $this->ana->fresh()->password;
        $this->post(route('password.email'), ['email' => 'ana@acme.pe'])->assertSessionHas('status', PasswordRecovery::RESPONSE);
        Notification::assertSentToTimes($this->ana, TemporaryPasswordMail::class, 1);
        $this->assertSame($hash, $this->ana->fresh()->password); // el segundo pedido no la cambia otra vez

        $this->travel(16)->minutes();
        $this->post(route('password.email'), ['email' => 'ana@acme.pe']);
        Notification::assertSentToTimes($this->ana, TemporaryPasswordMail::class, 2);
    }

    public function test_if_the_mail_fails_the_old_password_still_works_and_hr_is_asked(): void
    {
        // Sin fake: el correo de la temporal intenta salir y el servidor de correo falla justo al enviarlo
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationSending::class, function ($e) {
            if ($e->notification instanceof TemporaryPasswordMail) {
                throw new \RuntimeException('SMTP caído');
            }
        });
        $boss = $this->makeUser($this->acme, 'hr_manager');

        $this->post(route('password.email'), ['email' => 'ana@acme.pe'])->assertSessionHas('status', PasswordRecovery::RESPONSE);

        // RR. HH. recibe el pedido en su bandeja, como antes
        $this->assertTrue($boss->notifications()->where('type', (new PasswordHelpRequested($this->ana))->type())->exists());
        $ana = $this->ana->fresh();
        $this->assertTrue(Hash::check('ClaveVieja123', $ana->password));
        $this->assertFalse((bool) $ana->must_change_password);
        $this->assertFalse(AuditLog::where('action', 'auth.password_recovered')->exists());
    }

    public function test_the_app_endpoint_sends_it_too_and_company_domains_only_serve_their_users(): void
    {
        Notification::fake();
        $employee = $this->makeUser($this->acme, 'employee', ['email' => 'rosa@acme.pe']);
        $this->postJson('/api/v1/auth/password-help', ['email' => 'rosa@acme.pe'])->assertOk()
            ->assertJsonPath('message', PasswordRecovery::RESPONSE);
        Notification::assertSentTo($employee, TemporaryPasswordMail::class);

        // En el dominio de otra empresa no se atiende a Ana
        $other = $this->makeTenant('otra');
        app(PasswordRecovery::class)->sendTemporaryPassword('ana@acme.pe', $other->id);
        Notification::assertNotSentTo($this->ana, TemporaryPasswordMail::class);
    }
}
