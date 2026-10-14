<?php

namespace App\Modules\Birthdays\Services;

use App\Modules\Core\Models\Tenant;

/**
 * Ajustes de cumpleaños de la empresa (tenants.settings.birthdays):
 *   notify_colleagues: branch (compañeros de su sucursal) | company (toda la empresa) | none
 *   greet_celebrant:   push de saludo a quien cumple años
 *   hour:              hora local del aviso (0–23)
 */
final class BirthdaySettings
{
    public const SCOPES = [
        'branch'  => 'Compañeros de su misma sucursal',
        'company' => 'Toda la empresa',
        'none'    => 'Nadie (solo se ve en la app)',
    ];

    public const DEFAULTS = ['notify_colleagues' => 'branch', 'greet_celebrant' => true, 'hour' => 8];

    public static function for(?Tenant $tenant): array
    {
        return array_merge(self::DEFAULTS, (array) ($tenant?->setting('birthdays', []) ?? []));
    }

    public static function save(Tenant $tenant, array $values): void
    {
        $settings = (array) $tenant->settings;
        $settings['birthdays'] = array_merge(self::DEFAULTS, array_intersect_key($values, self::DEFAULTS));
        $tenant->forceFill(['settings' => $settings])->save();
    }
}
