<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Pulido del panel: URLs limpias, desplegables, utilidades de medio paso y "Mi cuenta" */
class PanelUiPolishTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_get_filters_drop_empty_params_and_trailing_question_mark(): void
    {
        $js = file_get_contents(public_path('js/panel.js'));

        $this->assertStringContainsString('function cleanGetUrl(form, submitter)', $js);
        $this->assertStringContainsString("value.trim() !== ''", $js);
        // Sin filtros: solo la ruta, sin "?" colgando
        $this->assertStringContainsString('url.origin + url.pathname + url.hash', $js);
        $this->assertStringContainsString('data-keep-empty', $js);
    }

    public function test_half_step_spacing_utilities_exist_for_bootstrap(): void
    {
        $css = file_get_contents(public_path('css/panel.css'));

        foreach (['.p-2\.5', '.gap-2\.5', '.gap-1\.5', '.mb-0\.5', '.mt-0\.5', '.px-2\.5', '.py-1\.5'] as $class) {
            $this->assertStringContainsString($class.' {', $css, "Falta la utilidad {$class}");
        }
    }

    public function test_pretty_selects_and_account_page(): void
    {
        $this->seedRoles();
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        $css = file_get_contents(public_path('css/pretty-select.css'));
        $this->assertStringContainsString('.pretty-select > .form-select', $css);
        $this->assertStringContainsString('--bs-border-radius-lg', $css);

        $html = $this->actingAs($admin)->get('/account')->assertOk()->getContent();
        $this->assertStringContainsString('css/pretty-select.css', $html);
        // Botón azul con texto blanco puro
        $this->assertMatchesRegularExpression('/class="btn btn-primary text-white[^"]*"[^>]*>\s*<i class="bi bi-shield-lock"><\/i>\s*<span>Actualizar contraseña/', $html);

        $this->actingAs($admin)->get('/branches')->assertOk()->assertSee('pretty-select pretty-select-sm', false);
    }
}
