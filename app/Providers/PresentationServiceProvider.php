<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Capa de presentación del panel (App\Modules\Core\Support\Labels):
 *  - @label('leave_status', $x->status)  → "Aprobada"
 *  - @fieldLabel('lunch_paid')           → "Almuerzo pagado"
 *  - <x-status-badge type="leave_status" :value="$x->status" />
 */
class PresentationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blade::directive('label', fn (string $expression) => "<?php echo e(\\App\\Modules\\Core\\Support\\Labels::enum({$expression})); ?>");
        Blade::directive('fieldLabel', fn (string $expression) => "<?php echo e(\\App\\Modules\\Core\\Support\\Labels::field({$expression})); ?>");
    }
}
