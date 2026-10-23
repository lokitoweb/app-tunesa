<?php

namespace App\Modules\Finance\Support;

use App\Modules\Core\Models\User;
use App\Modules\Finance\Models\LegacyIdMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Importación sin duplicados desde el ERP de escritorio: cada fila vieja se
 * resuelve una sola vez (legacy_id_map) y las personas se vinculan con el
 * usuario que ya existe por DNI y luego por correo. Nunca toca roles ni
 * contraseñas de usuarios existentes (el Super Admin incluido).
 */
final class LegacyLinker
{
    /**
     * Devuelve el registro ya vinculado a (tabla, id viejo) o el que crea $make.
     *
     * @param  callable(): Model  $make
     */
    public static function link(int $tenantId, string $legacyTable, string|int $legacyId, callable $make): Model
    {
        return DB::transaction(function () use ($tenantId, $legacyTable, $legacyId, $make) {
            $map = LegacyIdMap::withoutGlobalScopes()->where(['tenant_id' => $tenantId, 'legacy_table' => $legacyTable, 'legacy_id' => (string) $legacyId])
                ->lockForUpdate()->first();
            if ($map && ($model = $map->model_type::withoutGlobalScopes()->find($map->model_id))) {
                return $model;
            }

            $model = $make();
            LegacyIdMap::updateOrCreate(
                ['tenant_id' => $tenantId, 'legacy_table' => $legacyTable, 'legacy_id' => (string) $legacyId],
                ['model_type' => $model::class, 'model_id' => $model->getKey()],
            );

            return $model;
        });
    }

    /**
     * Usuario existente de la empresa con ese DNI o, si no, ese correo.
     * Si DNI y correo apuntan a personas distintas devuelve false: ambiguo,
     * va a revisión manual en vez de adivinar.
     */
    public static function findUser(int $tenantId, ?string $dni, ?string $email): User|false|null
    {
        $dni = preg_replace('/\D/', '', (string) $dni) ?: null;
        $email = strtolower(trim((string) $email)) ?: null;
        $query = fn () => User::withoutGlobalScopes()->where('tenant_id', $tenantId);

        $byDni = $dni ? $query()->where('document_number', $dni)->first() : null;
        $byEmail = $email ? $query()->whereRaw('LOWER(email) = ?', [$email])->first() : null;

        if ($byDni && $byEmail && ! $byDni->is($byEmail)) {
            return false;
        }

        return $byDni ?? $byEmail;
    }
}
