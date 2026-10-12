<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Ethics\Models\EthicsCase;
use App\Modules\Ethics\Models\EthicsCaseAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Canal ético: la denuncia no queda asociada a nadie (ni aunque la app tenga
 * sesión), se sigue con código + token y quien investiga conversa desde el panel.
 */
class EthicsChannelTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');

        $this->acme     = $this->makeTenant('acme');
        $this->admin    = $this->makeUser($this->acme, 'company_admin');
        $this->employee = $this->makeUser($this->acme, 'employee');
    }

    private function report(array $overrides = [], array $headers = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-Tenant-Slug' => 'acme'] + $headers)->post('/api/ethics/cases', $overrides + [
            'category' => 'harassment',
            'subject'  => 'Trato humillante en el turno noche',
            'body'     => 'El encargado del turno noche grita e insulta al personal de limpieza casi todos los días.',
        ], ['Accept' => 'application/json']);
    }

    private function lookup(string $code, string $token)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['X-Tenant-Slug' => 'acme', 'X-Ethics-Token' => $token])->getJson("/api/ethics/cases/{$code}");
    }

    private function web(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->actingAs($user);
    }

    public function test_report_is_anonymous_even_with_an_app_session(): void
    {
        $token = $this->employee->createToken('app')->plainTextToken;

        $response = $this->report([], [
            'Authorization' => "Bearer {$token}", 'User-Agent' => 'TuneSA/2.0 (Pixel 7)', 'X-Forwarded-For' => '190.12.34.56',
        ])->assertCreated()->assertJsonPath('data.status', 'received');

        $code   = $response->json('data.code');
        $secret = $response->json('data.token');
        $this->assertMatchesRegularExpression('/^ET-[2-9A-Z]{6}$/', $code);
        $this->assertMatchesRegularExpression('/^([2-9A-Z]{4}-){5}[2-9A-Z]{4}$/', $secret);

        // En la base: sin usuario, sin IP, texto cifrado, solo el hash del token y fecha sin hora
        $row = DB::table('ethics_cases')->where('public_code', $code)->first();
        $this->assertSame($this->acme->id, $row->tenant_id);
        $this->assertSame(hash('sha256', str_replace('-', '', $secret)), $row->token_hash);
        $this->assertStringNotContainsString('humillante', $row->subject);
        $this->assertStringNotContainsString('encargado', $row->body);
        $this->assertStringStartsWith(now()->toDateString(), (string) $row->reported_on);
        $this->assertFalse(collect((array) $row)->contains(fn ($v) => is_string($v) && (str_contains($v, '190.12') || str_contains($v, 'Pixel'))));
        foreach (['user_id', 'ip', 'user_agent', 'created_at'] as $column) {
            $this->assertObjectNotHasProperty($column, $row);
        }
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());

        // Quien investiga recibe el aviso; el colaborador no
        $this->assertSame(1, $this->admin->notifications()->where('data->type', 'ethics.case_received')->count());
        $this->assertSame(0, $this->employee->notifications()->count());
    }

    public function test_follow_up_needs_code_and_token_and_conversation_flows_both_ways(): void
    {
        $data = $this->report()->assertCreated()->json('data');

        $this->lookup($data['code'], 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF')->assertNotFound()->assertJsonPath('code', 'ETHICS_CASE_NOT_FOUND');
        $this->lookup('ET-ZZZZZZ', $data['token'])->assertNotFound();
        // El token se acepta en minúsculas y sin guiones
        $this->lookup($data['code'], strtolower(str_replace('-', ' ', $data['token'])))->assertOk()
            ->assertJsonPath('data.subject', 'Trato humillante en el turno noche')
            ->assertJsonPath('data.status_label', 'Recibida');

        // Otra empresa no ve la denuncia aunque tenga el código y el token
        $this->makeTenant('other');
        $this->flushHeaders();
        $this->withHeaders(['X-Tenant-Slug' => 'other', 'X-Ethics-Token' => $data['token']])
            ->getJson("/api/ethics/cases/{$data['code']}")->assertNotFound();

        // Panel: quien investiga abre la denuncia (queda en bitácora) y pide más información
        app(TenantContext::class)->clear();
        $case = EthicsCase::withoutGlobalScopes()->where('public_code', $data['code'])->sole();
        $this->web($this->admin)->get('/ethics')->assertOk()->assertSee($data['code']);
        $this->get("/ethics/{$case->id}")->assertOk()->assertSee('el turno noche');
        $this->post("/ethics/{$case->id}/reply", ['body' => '¿En qué fechas ocurrió? ¿Hubo testigos?', 'ask_for_info' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('needs_info', $case->fresh()->status);
        $this->assertTrue($case->fresh()->reporter_unread);
        $this->assertSame($this->admin->id, $case->fresh()->assigned_to);
        $this->assertSame(
            ['ethics.viewed', 'ethics.replied'],
            AuditLog::withoutGlobalScopes()->orderBy('id')->pluck('action')->all(),
        );

        // El denunciante ve la respuesta (sin nombre del investigador) y contesta con una foto
        $seen = $this->lookup($data['code'], $data['token'])->assertOk()->json('data');
        $this->assertSame('investigator', $seen['messages'][0]['from']);
        $this->assertArrayNotHasKey('investigator', $seen['messages'][0]);
        $this->assertFalse($case->fresh()->reporter_unread);

        $this->flushHeaders();
        $this->withHeaders(['X-Tenant-Slug' => 'acme', 'X-Ethics-Token' => $data['token']])->post("/api/ethics/cases/{$data['code']}/messages", [
            'body'   => 'Fue el 3 y el 5 de octubre, a las 2 a. m.',
            'photos' => [UploadedFile::fake()->image('foto.jpg', 800, 600)],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.messages.1.photos', 1);
        $this->assertSame('in_review', $case->fresh()->status);
        $this->assertTrue($case->fresh()->investigator_unread);

        // La foto se guarda re-codificada como JPEG y solo la ve quien investiga
        $photo = EthicsCaseAttachment::withoutGlobalScopes()->sole();
        $this->assertStringStartsWith("\xFF\xD8", Storage::disk('private')->get($photo->path));
        $this->web($this->admin)->get("/ethics/{$case->id}/photos/{$photo->id}")->assertOk();
        $supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->web($supervisor)->get("/ethics/{$case->id}/photos/{$photo->id}")->assertForbidden();
        $this->get('/ethics')->assertForbidden();

        // Cerrar exige la conclusión, que luego ve el denunciante; ya no admite mensajes
        $this->web($this->admin)->put("/ethics/{$case->id}/status", ['status' => 'closed'])->assertSessionHasErrors('resolution');
        $this->put("/ethics/{$case->id}/status", ['status' => 'closed', 'resolution' => 'Se comprobó el hecho y se sancionó al encargado.'])->assertSessionHasNoErrors();
        $this->lookup($data['code'], $data['token'])->assertJsonPath('data.resolution', 'Se comprobó el hecho y se sancionó al encargado.');

        $this->flushHeaders();
        $this->withHeaders(['X-Tenant-Slug' => 'acme', 'X-Ethics-Token' => $data['token']])
            ->postJson("/api/ethics/cases/{$data['code']}/messages", ['body' => 'Gracias'])->assertUnprocessable();
    }

    public function test_reports_are_rate_limited_and_validated(): void
    {
        $this->report(['category' => 'chisme'])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->report(['body' => 'corto'])->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->report()->assertCreated();
        $this->report()->assertCreated();
        $this->report()->assertCreated();
        $this->report()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');

        $this->flushHeaders();
        $this->withHeaders(['X-Tenant-Slug' => 'acme'])->getJson('/api/ethics/categories')
            ->assertOk()->assertJsonFragment(['key' => 'harassment', 'label' => 'Acoso laboral o sexual']);
    }
}
