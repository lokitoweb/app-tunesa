<?php

namespace Tests\Feature;

use App\Modules\Announcements\Models\Announcement;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Core\Support\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Comunicados: envío inmediato o programado, público, lecturas y permisos */
class AnnouncementsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $admin;

    private Branch $lima;

    private User $emp;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->acme  = $this->makeTenant('acme');
        $this->admin = $this->makeUser($this->acme, 'company_admin');
        $this->lima  = $this->makeBranch($this->acme, ['name' => 'Lima']);
        $cusco       = $this->makeBranch($this->acme, ['name' => 'Cusco']);
        $this->emp   = $this->makeUser($this->acme, 'employee', ['first_name' => 'Ana']);
        $this->other = $this->makeUser($this->acme, 'employee', ['first_name' => 'Luis']);
        $this->emp->branches()->attach($this->lima->id, ['is_primary' => true]);
        $this->other->branches()->attach($cusco->id, ['is_primary' => true]);
        app(TenantContext::class)->clear();
    }

    private function send(array $overrides = []): Announcement
    {
        $this->flushHeaders()->actingAs($this->admin)->post('/announcements', array_merge([
            'title' => 'Reunión general', 'body' => 'Nos vemos en la sala 2.', 'category' => 'meeting',
            'event_at' => now()->addDay()->format('Y-m-d\TH:i'), 'mode' => 'send',
            'targets' => [['type' => 'branch', 'id' => $this->lima->id]],
        ], $overrides))->assertSessionHasNoErrors()->assertRedirect('/announcements');
        app(TenantContext::class)->clear();

        return Announcement::latest('id')->firstOrFail();
    }

    public function test_panel_pages(): void
    {
        $this->actingAs($this->admin)->get('/announcements')->assertOk()->assertInertia(fn ($p) => $p->component('Announcements/Pages/Announcements/Index'));
        $this->get('/announcements/create')->assertOk()->assertInertia(fn ($p) => $p->component('Announcements/Pages/Announcements/Form')->has('audience.branches', 2));
    }

    public function test_send_now_reaches_only_its_audience_and_tracks_reads(): void
    {
        $a = $this->send();
        $this->assertSame('sent', $a->status);
        $this->assertSame(1, $a->recipients);
        $this->assertSame(1, $this->emp->notifications()->where('type', 'announcement.published')->count());
        $this->assertSame(0, $this->other->notifications()->count());

        $this->actingIn($this->emp, $this->acme)->getJson('/api/announcements')->assertOk()
            ->assertJsonPath('data.0.title', 'Reunión general')->assertJsonPath('data.0.read', false)->assertJsonPath('meta.unread', 1);
        $this->getJson("/api/announcements/{$a->id}")->assertOk()->assertJsonPath('data.body', 'Nos vemos en la sala 2.');
        $this->getJson("/api/announcements/{$a->id}")->assertOk();
        $this->getJson('/api/announcements')->assertJsonPath('meta.unread', 0);
        $this->assertSame(1, $a->reads()->count());

        $this->actingIn($this->other, $this->acme)->getJson('/api/announcements')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/announcements/{$a->id}")->assertNotFound();

        // Enviado: se ve el detalle con lecturas y ya no se edita
        $this->flushHeaders()->actingAs($this->admin)->get("/announcements/{$a->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Announcements/Pages/Announcements/Show')->where('announcement.reads', 1)->has('readers', 1));
        $this->get("/announcements/{$a->id}/edit")->assertRedirect("/announcements/{$a->id}");
        $this->put("/announcements/{$a->id}", ['title' => 'X', 'body' => 'Y', 'category' => 'general', 'mode' => 'send', 'targets' => [['type' => 'all']]]);
        $this->assertSame('Reunión general', $a->fresh()->title);
    }

    public function test_drafts_are_not_sent_and_scheduled_ones_go_out_on_time(): void
    {
        $draft = $this->send(['mode' => 'draft', 'targets' => []]);
        $this->assertSame('draft', $draft->status);
        $this->assertSame(0, $this->emp->notifications()->count());
        $this->actingIn($this->emp, $this->acme)->getJson('/api/announcements')->assertJsonCount(0, 'data');

        $scheduled = $this->send(['mode' => 'schedule', 'scheduled_at' => now()->addHour()->format('Y-m-d\TH:i'), 'targets' => [['type' => 'all']]]);
        $this->assertSame('scheduled', $scheduled->status);
        $this->artisan('announcements:dispatch')->assertSuccessful();
        $this->assertSame('scheduled', $scheduled->fresh()->status);

        $this->travel(1)->day(); // la hora del formulario es la de la empresa
        $this->artisan('announcements:dispatch')->assertSuccessful();
        $this->assertSame('sent', $scheduled->fresh()->status);
        $this->assertSame(2, $scheduled->fresh()->recipients);
        $this->assertSame(1, $this->other->notifications()->where('type', 'announcement.published')->count());
    }

    public function test_validation_and_urgent_push_cannot_be_muted(): void
    {
        $this->flushHeaders()->actingAs($this->admin)->post('/announcements', ['title' => 'X', 'body' => 'Y', 'category' => 'general', 'mode' => 'send', 'targets' => []])->assertSessionHasErrors('targets');
        $this->post('/announcements', ['title' => 'X', 'body' => 'Y', 'category' => 'general', 'mode' => 'schedule', 'scheduled_at' => now()->subHour()->format('Y-m-d\TH:i'), 'targets' => [['type' => 'all']]])->assertSessionHasErrors('scheduled_at');

        $urgent = $this->send(['category' => 'urgent', 'targets' => [['type' => 'user', 'id' => $this->emp->id]]]);
        $this->assertSame(1, $this->emp->notifications()->where('type', 'announcement.urgent')->count());
        $this->assertSame('sent', $urgent->status);

        $this->emp->update(['notification_preferences' => ['announcements' => false]]);
        $this->assertFalse(NotificationPreferences::allowsPush($this->emp->fresh(), 'announcement.published'));
        $this->assertTrue(NotificationPreferences::allowsPush($this->emp->fresh(), 'announcement.urgent'));
    }

    public function test_permissions_and_disabled_module(): void
    {
        $supervisor = $this->makeUser($this->acme, 'supervisor');
        app(TenantContext::class)->clear();
        $this->actingAs($supervisor)->get('/announcements')->assertForbidden();
        $this->actingIn($supervisor, $this->acme)->getJson('/api/announcements')->assertOk();

        $this->acme->update(['settings' => ['disabled_modules' => ['Announcements']]]);
        cache()->flush();
        $this->actingIn($this->emp, $this->acme)->getJson('/api/announcements')->assertForbidden()->assertJsonPath('code', 'MODULE_DISABLED');
        $this->flushHeaders()->actingAs($this->admin)->get('/announcements')->assertForbidden();
    }
}
