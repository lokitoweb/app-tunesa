<?php

namespace App\Modules;

use App\Modules\Core\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Catálogo de módulos del ERP (cada carpeta de app/Modules) y su activación
 * por empresa. Los módulos apagados se guardan en tenants.settings.disabled_modules;
 * Core no se puede apagar.
 */
final class ModuleRegistry
{
    public const ALWAYS_ON = ['Core'];

    /** Nombre comercial de cada módulo (formulario de empresas) */
    public const LABELS = [
        'Attendance'   => 'Asistencia',
        'HR'           => 'Recursos humanos',
        'Integrations' => 'Integraciones',
        'Onboarding'   => 'Incorporación',
        'Payroll'      => 'Nómina y boletas',
        'Training'     => 'Capacitaciones',
    ];

    /** @return list<string> Core primero: los demás dependen de él */
    public static function names(): array
    {
        static $names = null;

        if ($names === null) {
            $names = array_map('basename', glob(__DIR__ . '/*', GLOB_ONLYDIR) ?: []);
            usort($names, fn ($a, $b) => [$a !== 'Core', $a] <=> [$b !== 'Core', $b]);
        }

        return $names;
    }

    /** Módulos que una empresa puede apagar */
    public static function optional(): array
    {
        return array_values(array_diff(self::names(), self::ALWAYS_ON));
    }

    public static function enabledFor(?Tenant $tenant, string $module): bool
    {
        return $tenant === null
            || in_array($module, self::ALWAYS_ON, true)
            || ! in_array($module, (array) $tenant->setting('disabled_modules', []), true);
    }

    /** ¿La ruta de esta request pertenece a un módulo apagado para la empresa? */
    public static function blocks(Request $request, ?Tenant $tenant): bool
    {
        $module = $request->route()?->getAction('module');

        return is_string($module) && ! self::enabledFor($tenant, $module);
    }
}
