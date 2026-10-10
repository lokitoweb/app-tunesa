<?php

namespace Tests\Feature;

use App\Jobs\SendTenantWebhook;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Integraciones de "Marca y app": webhook firmado de marcaciones y permisos,
 * y endpoint de notificaciones por cada aviso in-app.
 */
class IntegrationWebhooksTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const HOOK   = 'https://erp.example.test/hooks/asistencia';
    private const NOTIFY = 'https://avisos.example.test/push';
    private const SECRET = 'whsec_prueba_123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
    }

    private function configure(?Tenant $tenant, array $values): void
    {
        TenantSetting::editableRowFor($tenant)->fill($values)->save();
    }

    private function employeeWithBranch(Tenant $tenant): User
    {
        $emp = $this->makeUser($tenant, 'employee', ['employee_code' => 'EMP-001']);
        $emp->branches()->attach($this->makeBranch($tenant)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        return $emp;
    }

    private function payload(): array
    {
        return [
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12, 'device_id' => 'd1',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ];
    }

    private function gps(): array
    {
        return ['latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12];
    }

    /** @return array<int, array> cuerpos decodificados de los POST al webhook */
    private function sentEvents(string $url = self::HOOK): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => $pair[0]->url() === $url)
            ->map(fn ($pair) => json_decode($pair[0]->body(), true))
            ->values()->all();
    }

    public function test_attendance_events_are_posted_signed_to_the_company_webhook(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);
        $acme = $this->makeTenant('acme');
        $emp  = $this->employeeWithBranch($acme);
        $this->configure($acme, ['webhook_url' => self::HOOK, 'webhook_secret' => self::SECRET]);

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();
        $this->actingIn($emp, $acme)->postJson('/api/attendance/break-start', $this->gps())->assertOk();
        $this->actingIn($emp, $acme)->postJson('/api/attendance/break-end', $this->gps())->assertOk();
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-out', $this->payload())->assertOk();

        $this->assertSame(
            ['attendance.clock_in', 'attendance.break_start', 'attendance.break_end', 'attendance.clock_out'],
            array_column($this->sentEvents(), 'event'),
        );

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::HOOK) {
                return false;
            }
            $body = $request->body();
            $json = json_decode($body, true);

            return $request->hasHeader('X-Tunesa-Signature', 'sha256=' . hash_hmac('sha256', $body, self::SECRET))
                && $request->hasHeader('X-Tunesa-Event', $json['event'])
                && $request->hasHeader('Content-Type', 'application/json')
                && $json['tenant'] === ['slug' => 'acme']
                && isset($json['occurred_at'], $json['data']['id'])
                && $json['data']['employee_code'] === 'EMP-001';
        });

        $clockOut = $this->sentEvents()[3];
        $this->assertNotNull($clockOut['data']['clock_out_at']);
        $this->assertNotNull($clockOut['data']['worked_hours']);
    }

    public function test_a_failing_webhook_never_breaks_the_clock_in_and_is_logged(): void
    {
        Http::fake([self::HOOK => Http::response('caído', 500)]);
        Log::spy();
        $acme = $this->makeTenant('acme');
        $emp  = $this->employeeWithBranch($acme);
        $this->configure($acme, ['webhook_url' => self::HOOK, 'webhook_secret' => self::SECRET]);

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();
        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'integración'))->atLeast()->once();
    }

    public function test_job_is_queued_with_retries_and_without_the_secret_in_the_payload(): void
    {
        Queue::fake();
        $acme = $this->makeTenant('acme');
        $emp  = $this->employeeWithBranch($acme);
        $this->configure($acme, ['webhook_url' => self::HOOK, 'webhook_secret' => self::SECRET]);

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();

        Queue::assertPushed(SendTenantWebhook::class, function (SendTenantWebhook $job) {
            return $job->event === 'attendance.clock_in'
                && $job->tries === 5
                && $job->backoff() === [10, 60, 300, 900]
                && ! str_contains(serialize($job), self::SECRET);
        });
    }

    public function test_nothing_is_sent_without_a_company_webhook_even_if_the_platform_has_one(): void
    {
        Http::fake();
        $acme = $this->makeTenant('acme');
        $emp  = $this->employeeWithBranch($acme);
        $this->configure(null, ['webhook_url' => self::HOOK]); // no se hereda

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();
        // (La única petición saliente es la geocodificación inversa de la marcación)
        Http::assertNotSent(fn ($request) => $request->url() === self::HOOK);
    }

    public function test_leave_status_changes_and_notifications_go_to_their_endpoints(): void
    {
        Http::fake();
        $acme = $this->makeTenant('acme');
        $emp  = $this->makeUser($acme);
        $hr   = $this->makeUser($acme, 'hr_manager');
        $this->configure($acme, ['webhook_url' => self::HOOK, 'webhook_secret' => self::SECRET, 'notification_endpoint' => self::NOTIFY]);

        $leave = LeaveRequest::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'type' => 'vacation',
            'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'days' => 2, 'reason' => 'Viaje', 'status' => 'pending']);

        // La solicitud crea un aviso para RRHH → endpoint de notificaciones
        $this->assertSame(['notification.created'], array_column($this->sentEvents(self::NOTIFY), 'event'));
        $this->assertSame($hr->id, $this->sentEvents(self::NOTIFY)[0]['data']['user_id']);
        $this->assertSame('leave_request.submitted', $this->sentEvents(self::NOTIFY)[0]['data']['type']);

        $this->actingIn($hr, $acme)->postJson("/api/approvals/leave-requests/{$leave->id}/approve")->assertOk();

        $events = $this->sentEvents();
        $this->assertSame(['leave_request.status_changed'], array_column($events, 'event'));
        $this->assertSame('approved', $events[0]['data']['status']);
        $this->assertSame('pending', $events[0]['data']['previous_status']);

        // El aviso al colaborador también sale, firmado
        $this->assertSame($emp->id, $this->sentEvents(self::NOTIFY)[1]['data']['user_id']);
        Http::assertSent(fn (Request $r) => $r->url() === self::NOTIFY
            && $r->hasHeader('X-Tunesa-Signature', 'sha256=' . hash_hmac('sha256', $r->body(), self::SECRET))
            && $r->hasHeader('X-Tunesa-Event', 'notification.created'));
    }
}
