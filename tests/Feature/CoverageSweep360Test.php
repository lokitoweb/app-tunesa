<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Barrido 360° (2026-10-22): recorre TODAS las rutas registradas, así una
 * ruta nueva entra sola en la prueba.
 *
 *  - Ninguna pantalla ni endpoint GET (panel y API) muestra datos de otra
 *    empresa ni responde 500 al administrador de la empresa.
 *  - El colaborador no alcanza ninguna ruta /api/admin/* (403 antes del controlador).
 *  - El administrador de empresa no alcanza la gestión global (Super Admin).
 *  - Los ids de otra empresa no se aceptan al asignar sucursal, turno o supervisor.
 */
class CoverageSweep360Test extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Textos que solo existen en la empresa ajena */
    private const FOREIGN_MARKERS = ['Fugacorp Bravo', 'Bruneldo', 'Fugazzi', 'fuga.bravo@example.test', 'Sede Fugazzi Norte'];

    /** Rutas que no terminan (SSE) o que cierran la sesión */
    private const SKIP_URIS = ['dashboard/live-stream', 'logout', 'install'];

    private Tenant $acme;
    private Tenant $foreign;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->acme  = $this->makeTenant('acme');
        $this->admin = $this->makeUser($this->acme, 'company_admin', ['first_name' => 'Ana']);
        $branch      = $this->makeBranch($this->acme, ['name' => 'Sede Central']);
        $this->makeUser($this->acme, 'employee', ['first_name' => 'Carlos'])->branches()->attach($branch->id, ['is_primary' => true]);

        // Empresa ajena con datos reconocibles
        $this->foreign = $this->makeTenant('fugacorp', ['company_name' => 'Fugacorp Bravo']);
        $foreignAdmin  = $this->makeUser($this->foreign, 'company_admin', ['first_name' => 'Bruneldo', 'last_name' => 'Fugazzi', 'email' => 'fuga.bravo@example.test']);
        $foreignBranch = $this->makeBranch($this->foreign, ['name' => 'Sede Fugazzi Norte']);
        $foreignAdmin->branches()->attach($foreignBranch->id, ['is_primary' => true]);
        AuditLog::create(['tenant_id' => $this->foreign->id, 'user_id' => $foreignAdmin->id, 'action' => 'employee.updated', 'meta' => ['name' => 'Bruneldo Fugazzi']]);
    }

    /** @return iterable<Route> */
    private function routes(callable $filter): iterable
    {
        foreach (app('router')->getRoutes() as $route) {
            if (in_array($route->uri(), self::SKIP_URIS, true) || ! $filter($route)) {
                continue;
            }
            yield $route;
        }
    }

    private function hasMiddleware(Route $route, string $prefix): bool
    {
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function assertNoForeignData(string $body, string $where): void
    {
        foreach (self::FOREIGN_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $body, "{$where} muestra datos de otra empresa ({$marker})");
        }
    }

    public function test_every_panel_screen_stays_within_the_company_and_never_fails(): void
    {
        $checked = 0;
        foreach ($this->routes(fn (Route $r) => in_array('GET', $r->methods(), true)
            && ! str_contains($r->uri(), '{') && ! str_starts_with($r->uri(), 'api/')
            && $this->hasMiddleware($r, 'auth') && ! $this->hasMiddleware($r, 'auth:sanctum') && ! $this->hasMiddleware($r, 'api.tenant')) as $route) {
            $response = $this->actingAs($this->admin)->get('/' . ltrim($route->uri(), '/'));
            $status   = $response->getStatusCode();
            $this->assertLessThan(500, $status, "GET /{$route->uri()} respondió {$status}");
            $this->assertNoForeignData((string) $response->getContent(), "GET /{$route->uri()}");
            $checked++;
        }

        $this->assertGreaterThan(40, $checked, 'El barrido del panel recorrió muy pocas pantallas');
    }

    public function test_every_tenant_api_listing_stays_within_the_company_and_never_fails(): void
    {
        $checked = 0;
        foreach ($this->routes(fn (Route $r) => in_array('GET', $r->methods(), true)
            && str_starts_with($r->uri(), 'api/') && ! str_contains($r->uri(), '{')
            && ($this->hasMiddleware($r, 'api.tenant') || $this->hasMiddleware($r, 'auth:sanctum'))
            && ! str_starts_with($r->uri(), 'api/superadmin')) as $route) {
            $response = $this->actingIn($this->admin, $this->acme)->get('/' . $route->uri());
            $status   = $response->getStatusCode();
            $this->assertLessThan(500, $status, "GET /{$route->uri()} respondió {$status}");
            $this->assertNoForeignData((string) $response->getContent(), "GET /{$route->uri()}");
            $checked++;
        }

        $this->assertGreaterThan(30, $checked, 'El barrido de la API recorrió muy pocas rutas');
    }

    public function test_employee_cannot_reach_any_admin_api_route(): void
    {
        $employee = $this->makeUser($this->acme, 'employee');
        $checked  = 0;

        foreach ($this->routes(fn (Route $r) => str_starts_with($r->uri(), 'api/admin/')) as $route) {
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $status = $this->actingIn($employee, $this->acme)->json($method, '/' . $uri)->getStatusCode();
                $this->assertSame(403, $status, "Colaborador: {$method} /{$route->uri()} respondió {$status}");
                $checked++;
            }
        }

        $this->assertGreaterThan(30, $checked);
    }

    public function test_company_admin_cannot_reach_global_tenant_management(): void
    {
        foreach ($this->routes(fn (Route $r) => str_starts_with($r->uri(), 'api/superadmin/') && $this->hasMiddleware($r, 'auth:sanctum')) as $route) {
            $uri = preg_replace('/\{[^}]+\}/', (string) $this->foreign->id, $route->uri());
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $status = $this->actingIn($this->admin, $this->acme)->json($method, '/' . $uri)->getStatusCode();
                $this->assertSame(403, $status, "Admin de empresa: {$method} /{$route->uri()} respondió {$status}");
            }
        }

        foreach ($this->routes(fn (Route $r) => str_starts_with((string) $r->getName(), 'companies.')) as $route) {
            $uri = preg_replace('/\{[^}]+\}/', (string) $this->foreign->id, $route->uri());
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $status = $this->actingAs($this->admin)->call($method, '/' . $uri)->getStatusCode();
                $this->assertSame(403, $status, "Admin de empresa: {$method} /{$route->uri()} respondió {$status}");
            }
        }

        $this->assertSame('active', $this->foreign->fresh()->status);
    }

    public function test_foreign_branch_shift_or_supervisor_ids_are_rejected(): void
    {
        $employee      = $this->makeUser($this->acme, 'employee');
        $foreignBranch = Branch::withoutGlobalScopes()->where('tenant_id', $this->foreign->id)->firstOrFail();
        $foreignShift  = Shift::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->foreign->id, 'name' => 'Turno ajeno', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'working_days' => [1, 2, 3, 4, 5],
        ]);
        $foreignUser = User::withoutGlobalScopes()->where('email', 'fuga.bravo@example.test')->first();
        $myBranch    = $this->makeBranch($this->acme);

        $this->actingIn($this->admin, $this->acme)
            ->postJson("/api/admin/employees/{$employee->id}/assign-branch", ['branch_id' => $foreignBranch->id])
            ->assertStatus(422)->assertJsonValidationErrors('branch_id');
        $this->assertFalse($employee->branches()->whereKey($foreignBranch->id)->exists());

        $this->actingIn($this->admin, $this->acme)
            ->postJson("/api/admin/employees/{$employee->id}/assign-shift", [
                'shift_id' => $foreignShift->id, 'branch_id' => $myBranch->id, 'effective_from' => now()->toDateString(),
            ])->assertStatus(422)->assertJsonValidationErrors('shift_id');

        $this->actingIn($this->admin, $this->acme)
            ->putJson("/api/admin/branches/{$myBranch->id}", ['supervisor_id' => $foreignUser->id])
            ->assertStatus(422)->assertJsonValidationErrors('supervisor_id');
        $this->assertNull($myBranch->fresh()->supervisor_id);
    }

    public function test_public_files_are_served_even_without_the_storage_symlink(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1/logo.png', 'png-bytes');

        $this->get('/storage/logos/1/logo.png')->assertOk();
        $this->get('/storage/logos/1/no-existe.png')->assertNotFound();
        // Nunca se sale del disco público
        $this->get('/storage/..%2F..%2F.env')->assertNotFound();
        // Los archivos privados (boletas, selfies) no tienen URL pública
        Storage::fake('private');
        Storage::disk('private')->put('payslips/1/boleta.pdf', 'pdf');
        $this->get('/storage/payslips/1/boleta.pdf')->assertNotFound();
    }
}
