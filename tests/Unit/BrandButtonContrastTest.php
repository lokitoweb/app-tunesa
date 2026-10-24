<?php

namespace Tests\Unit;

use App\Modules\Core\Models\TenantSetting;
use PHPUnit\Framework\TestCase;

/** Botón primario de marca: texto blanco puro con contraste WCAG AA para cualquier color de empresa */
class BrandButtonContrastTest extends TestCase
{
    public function test_any_brand_color_gets_a_button_where_white_text_passes_wcag_aa(): void
    {
        // Azul de plataforma (antes elegía texto oscuro: "negro sobre azul"), azul medio, amarillo, ya oscuros
        foreach (['#0284C7', '#4A90E2', '#F59E0B', '#FFFF00', '#3C729E', '#1E40AF', '#FFFFFF'] as $brand) {
            $button = TenantSetting::buttonColor($brand);
            $hover = TenantSetting::buttonColor($brand, 0.12);
            $this->assertGreaterThanOrEqual(4.5, TenantSetting::contrastRatio($button, '#FFFFFF'), "{$brand} → {$button}");
            $this->assertGreaterThanOrEqual(4.5, TenantSetting::contrastRatio($hover, '#FFFFFF'), "hover de {$brand}");
        }
    }

    public function test_dark_enough_brand_colors_are_kept_as_they_are(): void
    {
        $this->assertSame('#3C729E', TenantSetting::buttonColor('#3C729E'));
        $this->assertSame('#1E40AF', TenantSetting::buttonColor('#1E40AF'));
        $this->assertEqualsWithDelta(21.0, TenantSetting::contrastRatio('#000000', '#FFFFFF'), 0.01);
    }
}
