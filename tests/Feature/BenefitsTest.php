<?php

namespace Tests\Feature;

use App\Modules\Benefits\Models\Benefit;
use App\Modules\Benefits\Models\BenefitCategory;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Beneficios: panel (catálogo, categorías, público), app (vigentes y solo los suyos) y avisos */
class BenefitsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $admin;

    private Branch $lima;

    private Branch $cusco;

    private User $emp;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedRoles();
        $this->acme  = $this->makeTenant('acme');
        $this->admin = $this->makeUser($this->acme, 'company_admin');
        $this->lima  = $this->makeBranch($this->acme, ['name' => 'Lima']);
        $this->cusco = $this->makeBranch($this->acme, ['name' => 'Cusco']);
        $this->emp   = $this->makeUser($this->acme, 'employee', ['first_name' => 'Ana', 'position' => 'Flebotomista']);
        $this->other = $this->makeUser($this->acme, 'employee', ['first_name' => 'Luis', 'position' => 'Counter']);
        $this->emp->branches()->attach($this->lima->id, ['is_primary' => true]);
        $this->other->branches()->attach($this->cusco->id, ['is_primary' => true]);
        app(TenantContext::class)->clear();
    }

    private function create(array $overrides = []): Benefit
    {
        $this->actingAs($this->admin)->get('/benefits')->assertOk();
        $category = BenefitCategory::where('tenant_id', $this->acme->id)->first();

        $this->actingAs($this->admin)->post('/benefits', array_merge([
            'title' => 'Gimnasio 30% dscto', 'partner' => 'Smart Fit', 'highlight' => '30% dscto', 'category_id' => $category->id,
            'promo_code' => 'TUNESA30', 'how_to_use' => 'Muestra tu fotocheck', 'status' => 'published', 'is_featured' => '1',
            'image' => UploadedFile::fake()->image('gym.jpg', 800, 400),
            'targets' => [['type' => 'branch', 'id' => $this->lima->id]],
        ], $overrides))->assertSessionHasNoErrors()->assertRedirect('/benefits');
        app(TenantContext::class)->clear();

        return Benefit::latest('id')->firstOrFail();
    }

    public function test_default_categories_and_panel_pages(): void
    {
        $this->actingAs($this->admin)->get('/benefits')->assertOk()->assertInertia(fn ($p) => $p->component('Benefits/Pages/Benefits/Index'));
        $this->assertSame(count(BenefitCategory::DEFAULTS), BenefitCategory::where('tenant_id', $this->acme->id)->count());
        $this->get('/benefits/create')->assertOk()->assertInertia(fn ($p) => $p->component('Benefits/Pages/Benefits/Form')->has('audience.branches', 2));
        $this->get('/benefits/categories')->assertOk();

        $this->post('/benefits/categories', ['name' => 'Movilidad', 'icon' => 'car-front', 'color' => '#112233'])->assertSessionHasNoErrors();
        $this->post('/benefits/categories', ['name' => 'Movilidad', 'icon' => 'car-front', 'color' => '#112233'])->assertSessionHasErrors('name');
    }

    public function test_publishing_shows_only_to_its_audience_and_notifies(): void
    {
        $benefit = $this->create();

        Storage::disk('public')->assertExists($benefit->image_path);
        $this->assertSame(1, $this->emp->notifications()->where('type', 'benefit.published')->count());
        $this->assertSame(0, $this->other->notifications()->count());

        $list = $this->actingIn($this->emp, $this->acme)->getJson('/api/benefits')->assertOk();
        $list->assertJsonPath('data.0.title', 'Gimnasio 30% dscto')->assertJsonPath('data.0.highlight', '30% dscto')
            ->assertJsonCount(1, 'meta.categories');
        $this->getJson("/api/benefits/{$benefit->id}")->assertOk()->assertJsonPath('data.promo_code', 'TUNESA30');

        $this->actingIn($this->other, $this->acme)->getJson('/api/benefits')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/benefits/{$benefit->id}")->assertNotFound();
    }

    public function test_drafts_and_expired_benefits_are_hidden(): void
    {
        $draft = $this->create(['status' => 'draft', 'targets' => [['type' => 'all']]]);
        $this->assertSame(0, $this->emp->notifications()->count());
        $this->create(['title' => 'Vencido', 'valid_from' => now()->subMonth()->toDateString(), 'valid_until' => now()->subDay()->toDateString(), 'targets' => [['type' => 'all']]]);
        $this->create(['title' => 'Futuro', 'valid_from' => now()->addWeek()->toDateString(), 'targets' => [['type' => 'all']]]);

        $this->actingIn($this->emp, $this->acme)->getJson('/api/benefits')->assertOk()->assertJsonCount(0, 'data');

        // Publicar el borrador desde el listado lo muestra y avisa una sola vez
        $this->actingAs($this->admin)->post("/benefits/{$draft->id}/status", ['status' => 'published'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/benefits/{$draft->id}/status", ['status' => 'archived']);
        $this->actingAs($this->admin)->post("/benefits/{$draft->id}/status", ['status' => 'published']);
        $this->assertSame(1, $this->emp->notifications()->where('data->data->benefit_id', $draft->id)->count());
    }

    public function test_position_rule_ignores_case_and_validation(): void
    {
        $this->create(['targets' => [['type' => 'position', 'value' => ' counter']]]);
        $this->actingIn($this->other, $this->acme)->getJson('/api/benefits')->assertOk()->assertJsonCount(1, 'data');

        $this->flushHeaders()->actingAs($this->admin)->post('/benefits', ['title' => 'X', 'status' => 'published', 'targets' => []])->assertSessionHasErrors('targets');
        $this->actingAs($this->admin)->post('/benefits', ['title' => 'X', 'status' => 'draft', 'url' => 'http://inseguro.test', 'targets' => [['type' => 'all']]])->assertSessionHasErrors('url');
    }

    public function test_other_tenants_cannot_see_or_target_foreign_branches(): void
    {
        $benefit = $this->create(['targets' => [['type' => 'all']]]);
        $globex = $this->makeTenant('globex');
        $alien  = $this->makeUser($globex, 'employee');
        $alienBranch = $this->makeBranch($globex);
        app(TenantContext::class)->clear();

        $this->actingIn($alien, $globex)->getJson('/api/benefits')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/benefits/{$benefit->id}")->assertNotFound();

        $this->flushHeaders()->actingAs($this->admin)->post('/benefits', ['title' => 'X', 'status' => 'draft', 'targets' => [['type' => 'branch', 'id' => $alienBranch->id]]])
            ->assertSessionHasErrors('targets.0.id');
    }

    public function test_permissions_and_disabled_module(): void
    {
        // Un supervisor entra al panel pero no administra beneficios; sí los ve en la app
        $supervisor = $this->makeUser($this->acme, 'supervisor');
        app(TenantContext::class)->clear();
        $this->actingAs($supervisor)->get('/benefits')->assertForbidden();
        $this->actingIn($supervisor, $this->acme)->getJson('/api/benefits')->assertOk();

        $this->acme->update(['settings' => ['disabled_modules' => ['Benefits']]]);
        cache()->flush();
        $this->actingIn($this->emp, $this->acme)->getJson('/api/benefits')->assertForbidden()->assertJsonPath('code', 'MODULE_DISABLED');
        $this->flushHeaders()->actingAs($this->admin)->get('/benefits')->assertForbidden();
    }
}
