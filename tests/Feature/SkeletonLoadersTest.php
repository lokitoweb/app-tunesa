<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Skeleton loaders del panel (22/10/2026): las siluetas son solo una clase
 * CSS sobre el DOM existente, los filtros que se envían solos ya no usan JS
 * en línea y los datos del colaborador que se pintan con JS van escapados.
 */
class SkeletonLoadersTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_panel_assets_define_the_skeleton_for_light_dark_and_reduced_motion(): void
    {
        $css = file_get_contents(public_path('css/panel.css'));
        $this->assertStringContainsString('.sk-loading tbody td::after', $css);
        $this->assertStringContainsString('[data-bs-theme="dark"] {'."\n".'    --sk-base', $css);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\)\s*\{\s*\.sk-bar/', $css);

        $js = file_get_contents(public_path('js/panel.js'));
        // Panel expone skeleton (y filters) aunque se agreguen más utilidades al objeto
        $this->assertMatchesRegularExpression('/window\.Panel = \{[^}]*\bskeleton\b[^}]*\};/', $js);
        $this->assertMatchesRegularExpression('/window\.Panel = \{[^}]*\bfilters\b[^}]*\};/', $js);
        $this->assertStringContainsString('[data-autosubmit]', $js);
        $this->assertStringContainsString('data-fallback-initials', $js);
        // La silueta no arma HTML: solo agrega y quita la clase
        $skeletonBlock = substr($js, strpos($js, 'const skeleton = {'), 900);
        $this->assertStringNotContainsString('innerHTML', $skeletonBlock);
    }

    public function test_dashboard_uses_autosubmit_and_no_inline_handlers_with_user_data(): void
    {
        $acme = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $ana = $this->makeUser($acme, 'employee', ['first_name' => "O'Brien<b>"]);
        $branch = $this->makeBranch($acme);
        Attendance::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'branch_id' => $branch->id, 'work_date' => today()->toDateString(),
            'clock_in_at' => now(), 'status' => 'present', 'clock_in_photo' => 'attendance/x.jpg']);

        $html = $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('onchange="this.form.submit()"', $html);
        $this->assertStringNotContainsString("this.parentElement.innerHTML='<span", $html);
        $this->assertStringContainsString('data-fallback-initials="O"', $html);
        $this->assertStringNotContainsString("O'Brien<b>", $html);
        $this->assertStringContainsString("Panel.skeleton.on('#main-content')", $html);
    }

    public function test_tenant_switch_shows_skeleton_and_views_with_ajax_filters_use_it(): void
    {
        $views = [
            'layouts/app.blade.php' => 'data-skeleton',
            'payslips/index.blade.php' => "Panel.skeleton.on('#main-content')",
            'payroll/index.blade.php' => "Panel.skeleton.on('#main-content')",
            'employees/index.blade.php' => 'Panel.skeleton.on(tableContainer)',
            'attendance/calendar.blade.php' => "Panel.skeleton.on('#calendar-app-container')",
            'attendance/calendar-day.blade.php' => "Panel.skeleton.on('#cal-day-dynamic-container')",
        ];
        foreach ($views as $view => $needle) {
            $this->assertStringContainsString($needle, file_get_contents(resource_path("views/$view")), $view);
        }
        foreach (glob(resource_path('views/**/*.blade.php')) as $file) {
            $this->assertStringNotContainsString('onchange="this.form.submit()"', file_get_contents($file), $file);
        }
    }

    public function test_js_templates_escape_employee_data(): void
    {
        $calendar = file_get_contents(resource_path('views/attendance/calendar.blade.php'));
        $this->assertStringNotContainsString('${r.last_name}, ${r.first_name}', $calendar);
        $this->assertStringContainsString('${esc(r.last_name)}, ${esc(r.first_name)}', $calendar);
        $this->assertStringNotContainsString('<span>${c.label}</span>', $calendar);

        $employees = file_get_contents(resource_path('views/employees/index.blade.php'));
        $this->assertStringNotContainsString('>${b.name}</label>', $employees);
        $this->assertStringNotContainsString('>${d.name}</option>', $employees);

        $companies = file_get_contents(resource_path('views/companies/index.blade.php'));
        $this->assertStringNotContainsString('>${a.name}</div>', $companies);

        $ethics = file_get_contents(resource_path('views/ethics/index.blade.php'));
        $this->assertStringNotContainsString("\${id.name || '—'}", $ethics);
    }
}
