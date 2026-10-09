<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDateOnlyColumns;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Feriado de una empresa (nacional o propio). En estas fechas el control
 * de faltas no registra ausencias.
 */
class Holiday extends Model
{
    use BelongsToTenant, HasDateOnlyColumns;

    protected array $dateOnly = ['date'];

    protected $fillable = ['tenant_id', 'date', 'name'];

    protected $casts = ['date' => 'date'];

    /**
     * Feriados nacionales del Perú (calendario vigente desde 2024): fecha Y-m-d => nombre.
     * Jueves y Viernes Santo dependen de la Pascua de cada año.
     *
     * @return array<string, string>
     */
    public static function peruNational(int $year): array
    {
        $easter = self::easterSunday($year);

        $days = [
            "{$year}-01-01" => 'Año Nuevo',
            $easter->copy()->subDays(3)->toDateString() => 'Jueves Santo',
            $easter->copy()->subDays(2)->toDateString() => 'Viernes Santo',
            "{$year}-05-01" => 'Día del Trabajo',
            "{$year}-06-07" => 'Batalla de Arica y Día de la Bandera',
            "{$year}-06-29" => 'San Pedro y San Pablo',
            "{$year}-07-23" => 'Día de la Fuerza Aérea del Perú',
            "{$year}-07-28" => 'Fiestas Patrias',
            "{$year}-07-29" => 'Fiestas Patrias',
            "{$year}-08-06" => 'Batalla de Junín',
            "{$year}-08-30" => 'Santa Rosa de Lima',
            "{$year}-10-08" => 'Combate de Angamos',
            "{$year}-11-01" => 'Día de Todos los Santos',
            "{$year}-12-08" => 'Inmaculada Concepción',
            "{$year}-12-09" => 'Batalla de Ayacucho',
            "{$year}-12-25" => 'Navidad',
        ];
        ksort($days);

        return $days;
    }

    /** Domingo de Pascua (algoritmo gregoriano anónimo; no requiere ext-calendar) */
    public static function easterSunday(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }
}
