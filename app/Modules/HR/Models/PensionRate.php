<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Tasas de aporte de pensión de la empresa (AFP u ONP), editables: la SBS y la ONP las cambian.
 * La comisión de la AFP empieza en 0 (comisión mixta, sobre el saldo); con comisión sobre el
 * flujo se escribe la de cada AFP.
 */
class PensionRate extends Model
{
    use BelongsToTenant;

    /** [nombre, sistema, aporte %, seguro %, comisión %] */
    public const DEFAULTS = [
        'onp'       => ['ONP', 'onp', 13, 0, 0],
        'habitat'   => ['AFP Habitat', 'afp', 10, 1.37, 0],
        'integra'   => ['AFP Integra', 'afp', 10, 1.37, 0],
        'prima'     => ['AFP Prima', 'afp', 10, 1.37, 0],
        'profuturo' => ['AFP Profuturo', 'afp', 10, 1.37, 0],
    ];

    protected $fillable = ['tenant_id', 'code', 'name', 'system', 'contribution_pct', 'insurance_pct', 'commission_pct', 'is_active'];

    protected $casts = ['contribution_pct' => 'float', 'insurance_pct' => 'float', 'commission_pct' => 'float', 'is_active' => 'boolean'];

    public static function catalog(int $tenantId): Collection
    {
        if (! static::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            $now = now();
            static::withoutGlobalScopes()->insertOrIgnore(collect(self::DEFAULTS)->map(fn ($d, $code) => [
                'tenant_id' => $tenantId, 'code' => $code, 'name' => $d[0], 'system' => $d[1], 'contribution_pct' => $d[2],
                'insurance_pct' => $d[3], 'commission_pct' => $d[4], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }

        return static::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->get();
    }

    /** La tasa que corresponde a la ficha: ONP, o la AFP cuyo nombre aparece en el fondo registrado */
    public static function forProfile(int $tenantId, ?EmployeeProfile $profile): ?self
    {
        $rates = static::catalog($tenantId)->where('is_active', true);
        $fund = mb_strtolower((string) $profile?->pension_fund);

        return match ($profile?->pension_system) {
            'onp'   => $rates->firstWhere('system', 'onp'),
            'afp'   => $fund === '' ? null : $rates->where('system', 'afp')->first(fn ($r) => str_contains($fund, $r->code) || str_contains($fund, mb_strtolower($r->name))),
            default => null,
        };
    }

    public function totalPct(): float
    {
        return $this->contribution_pct + $this->insurance_pct + $this->commission_pct;
    }
}
