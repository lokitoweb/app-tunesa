<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Régimen laboral de la empresa con los parámetros de sus beneficios. Cada empresa los ajusta y
 * agrega los suyos; un parámetro vacío significa que ese concepto no se calcula.
 */
class LaborRegime extends Model
{
    use BelongsToTenant;

    /**
     * Regímenes con que empieza cada empresa: [nombre, vacaciones, gratificaciones/año, bonif. %,
     * CTS (sueldos/año), indemnización (días/año), tope (días)]. Valores de referencia de la ley
     * peruana para general y MYPE, editables; los demás empiezan sin parámetros.
     */
    public const DEFAULTS = [
        'general'       => ['Régimen general', null, 2, 9, 1, 45, 360],
        'small_company' => ['Pequeña empresa', 15, 1, 9, 0.5, 20, 120],
        'micro_company' => ['Microempresa', 15, 0, 0, 0, 10, 90],
        'agrarian'      => ['Agrario', null, null, null, null, null, null],
        'construction'  => ['Construcción civil', null, null, null, null, null, null],
        'part_time'     => ['Tiempo parcial', null, null, null, null, null, null],
        'other'         => ['Otro', null, null, null, null, null, null],
    ];

    public const PARAMS = ['vacation_days', 'gratifications_per_year', 'gratification_bonus_pct', 'cts_per_year', 'indemnity_days_per_year', 'indemnity_cap_days'];

    protected $fillable = ['tenant_id', 'code', 'name', ...self::PARAMS, 'is_active'];

    protected $casts = [
        'vacation_days'           => 'integer',
        'gratifications_per_year' => 'float',
        'gratification_bonus_pct' => 'float',
        'cts_per_year'            => 'float',
        'indemnity_days_per_year' => 'float',
        'indemnity_cap_days'      => 'integer',
        'is_active'               => 'boolean',
    ];

    /** Regímenes de la empresa; la primera vez se crean los de partida */
    public static function catalog(int $tenantId): Collection
    {
        if (! static::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            $now = now();
            static::withoutGlobalScopes()->insertOrIgnore(collect(self::DEFAULTS)->map(fn ($d, $code) => [
                'tenant_id' => $tenantId, 'code' => $code, 'name' => $d[0], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ] + array_combine(self::PARAMS, array_slice($d, 1)))->values()->all());
        }

        return static::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->get();
    }

    /** @return array<string, string> código => nombre (activos, más el que ya tiene la persona) */
    public static function options(int $tenantId, ?string $keep = null): array
    {
        return static::catalog($tenantId)->filter(fn ($r) => $r->is_active || $r->code === $keep)->pluck('name', 'code')->all();
    }

    public static function label(int $tenantId, ?string $code): ?string
    {
        return $code ? (static::catalog($tenantId)->firstWhere('code', $code)?->name ?? EmployeeProfile::LABOR_REGIMES[$code] ?? $code) : null;
    }
}
