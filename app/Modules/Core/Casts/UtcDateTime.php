<?php

namespace App\Modules\Core\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fecha y hora guardada SIEMPRE en UTC (Y-m-d H:i:s sin zona) y leída como
 * Carbon UTC, sin importar APP_TIMEZONE ni la zona de la empresa.
 *
 * Las columnas *_at de `attendances` guardan la hora de pared de la empresa;
 * las horas del teléfono y del servidor de la bitácora de marcaciones usan
 * este cast para poder comparar relojes sin ambigüedad.
 */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i:s', substr((string) $value, 0, 19), 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof CarbonInterface || $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);

        return $date->utc()->format('Y-m-d H:i:s');
    }
}
