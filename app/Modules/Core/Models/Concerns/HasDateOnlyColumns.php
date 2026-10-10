<?php

namespace App\Modules\Core\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * HasDateOnlyColumns
 *
 * El cast 'date' guarda "Y-m-d 00:00:00". En MySQL/PostgreSQL (columnas DATE)
 * da igual, pero en SQLite la columna es texto y comparaciones como
 * where('work_date', '2026-10-09') o whereBetween(...) dejan de coincidir.
 * Este trait guarda las columnas listadas en $dateOnly como "Y-m-d".
 */
trait HasDateOnlyColumns
{
    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->dateOnly ?? [], true) && ! is_null($value) && $value !== '') {
            $value = Carbon::parse($value)->toDateString();
            $this->attributes[$key] = $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }
}
