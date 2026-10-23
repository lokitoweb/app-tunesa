<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Último número emitido por empresa, tipo (RQ/OC/OS), serie y año */
class DocumentSequence extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'type', 'series', 'year', 'last_number'];

    /**
     * Siguiente código formal, p. ej. RQ-TN-SED-01-000001-2026. Llamar dentro de la
     * transacción que guarda el documento: la fila queda bloqueada hasta el commit.
     *
     * @return array{0:int,1:string} [número, código]
     */
    public static function next(Tenant $tenant, string $type, string $series, int $year): array
    {
        $key = ['tenant_id' => $tenant->id, 'type' => $type, 'series' => $series, 'year' => $year];

        return DB::transaction(function () use ($key, $tenant, $type, $series, $year) {
            static::query()->insertOrIgnore($key + ['last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = static::query()->where($key)->lockForUpdate()->firstOrFail();
            $row->increment('last_number');

            return [$row->last_number, sprintf('%s-%s-%s-%06d-%d', $type, $tenant->code, $series, $row->last_number, $year)];
        });
    }

    /** Serie = código de la sede (solo letras, dígitos y guion); sin sede, 001 */
    public static function seriesFrom(?string $branchCode): string
    {
        $clean = substr(preg_replace('/[^A-Z0-9-]/', '', strtoupper((string) $branchCode)), 0, 20);

        return $clean !== '' ? $clean : '001';
    }
}
