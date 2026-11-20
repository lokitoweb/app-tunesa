<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * La bitácora busca, filtra, cuenta y pagina sobre TODA la historia (antes solo sobre los últimos 25
 * registros que llegaban al navegador, y el filtro por acción no encontraba nada).
 */
class AuditLogFullHistoryTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_search_filters_tabs_totals_and_pages_cover_the_whole_history(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin', ['first_name' => 'Ana', 'last_name' => 'Díaz']);
        $hr = $this->makeUser($acme, 'hr_manager', ['first_name' => 'Elder', 'last_name' => 'Ruiz']);

        // Lo antiguo: un cambio de turno muy por debajo de los últimos 25 registros
        AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $hr->id, 'action' => 'shift.updated',
            'meta' => ['changes' => ['start_time' => ['old' => '07:00', 'new' => '15:00']], 'context' => ['name' => 'MAÑANA']]])
            ->forceFill(['created_at' => now()->subDays(10)])->save();
        foreach (range(1, 40) as $i) {
            AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $admin->id, 'action' => 'auth.login'])
                ->forceFill(['created_at' => now()->subMinutes($i)])->save();
        }

        $page = fn (string $url) => $this->actingAs($admin)->get($url)->assertOk();

        // 41 registros en 2 páginas de 25 y totales de toda la bitácora
        $page('/audit')->assertInertia(fn ($p) => $p->has('logs.data', 25)->where('logs.total', 41)->where('logs.last_page', 2)
            ->where('stats.total', 41)->where('stats.users', 2)->where('stats.changes', 1)->where('toneCounts.all', 41));
        $page('/audit?page=2')->assertInertia(fn ($p) => $p->has('logs.data', 16));

        // Filtro por acción: encuentra el registro antiguo
        $page('/audit?action=shift.updated')->assertInertia(fn ($p) => $p->has('logs.data', 1)->where('logs.data.0.action_label', 'Turno actualizado'));

        // Búsqueda: por la acción en español, por nombre y apellido, y por el detalle (con tilde)
        foreach (['turno actualizado', 'Elder Ruiz', 'MAÑANA', 'mañana'] as $term) {
            $page('/audit?q=' . urlencode($term))->assertInertia(fn ($p) => $p->where('logs.total', 1));
        }

        // Pestaña por tipo (tono de la acción) con los demás filtros
        $tone = \App\Modules\Core\Support\Labels::actionBadge('shift.updated')['tone'];
        $page("/audit?tone={$tone}")->assertInertia(fn ($p) => $p->where('logs.data.0.action_label', 'Turno actualizado'));
    }
}
