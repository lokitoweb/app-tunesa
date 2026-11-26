<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora de viajes (cargos con «Control por viajes»). Cada viaje tiene su N.º de salida (correlativo por
 * empresa), Conductor 1, Conductor 2 y Auxiliar de carga, y dos tramos: IDA (salida y llegada) y REGRESO
 * (salida y llegada, que el supervisor firma al registrarla). Las horas de conducción de cada tramo salen de
 * su salida a su llegada y las heredan los tres; un día con viaje (fecha de salida) es día trabajado de los tres.
 */
class TripLog extends Model
{
    use BelongsToTenant;

    /** Columnas de las tres personas del viaje: Conductor 1, Conductor 2 y Auxiliar de carga */
    public const CREW = ['driver_id', 'co_driver_id', 'helper_id'];

    protected $fillable = [
        'tenant_id', 'trip_number', 'branch_id', 'trip_date', 'route', 'driver_id', 'co_driver_id', 'helper_id', 'vehicle_plate',
        'departure_time', 'arrival_date', 'arrival_time', 'return_departure_date', 'return_departure_time',
        'return_date', 'return_time', 'return_route', 'return_signed_by', 'return_signed_at', 'notes', 'created_by',
    ];

    protected $casts = ['trip_date' => 'date', 'arrival_date' => 'date', 'return_departure_date' => 'date', 'return_date' => 'date',
        'return_signed_at' => 'datetime'];

    protected static function booted(): void
    {
        // N.º de salida: el siguiente de la empresa. El índice único (empresa, número) impide repetirlo
        static::creating(function (TripLog $trip) {
            if ($trip->trip_number === null && $trip->tenant_id) {
                $trip->trip_number = (int) static::withoutGlobalScopes()->where('tenant_id', $trip->tenant_id)->lockForUpdate()->max('trip_number') + 1;
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Conductor 1 */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /** Conductor 2 */
    public function coDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'co_driver_id');
    }

    /** Auxiliar de carga */
    public function helper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'helper_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_signed_by');
    }

    public function hasReturned(): bool
    {
        return $this->return_signed_at !== null;
    }

    /** Ids de las personas del viaje (sin vacíos) */
    public function crewIds(): array
    {
        return array_values(array_filter(array_map(fn ($c) => $this->{$c} ? (int) $this->{$c} : null, self::CREW)));
    }

    /** Minutos de conducción de la IDA (salida → llegada); null si falta la llegada */
    public function outboundMinutes(): ?int
    {
        return self::span($this->trip_date, $this->departure_time, $this->arrival_date, $this->arrival_time);
    }

    /** Minutos de conducción del REGRESO (salida → llegada); null si falta alguna de las dos */
    public function returnMinutes(): ?int
    {
        return self::span($this->return_departure_date, $this->return_departure_time, $this->return_date, $this->return_time);
    }

    /** Fecha y hora de un momento del viaje (hora de pared de la empresa); null si falta la fecha o la hora */
    public static function moment($date, $time): ?CarbonImmutable
    {
        return $date && $time ? CarbonImmutable::parse(substr((string) $date, 0, 10) . ' ' . substr((string) $time, 0, 5)) : null;
    }

    private static function span($fromDate, $fromTime, $toDate, $toTime): ?int
    {
        $from = self::moment($fromDate, $fromTime);
        $to = self::moment($toDate, $toTime);

        return $from && $to && $to->gte($from) ? (int) $from->diffInMinutes($to) : null;
    }

    /** Viajes en que alguna de estas personas fue Conductor 1, Conductor 2 o Auxiliar */
    public function scopeOfPeople(Builder $query, array $userIds): Builder
    {
        return $query->where(fn ($q) => $q->whereIn('driver_id', $userIds)->orWhereIn('co_driver_id', $userIds)->orWhereIn('helper_id', $userIds));
    }

    /**
     * Días trabajados por persona en el periodo: días distintos (fecha de salida) con al menos un viaje,
     * en cualquiera de los tres puestos.
     *
     * @param  list<int>  $userIds
     * @return array<int, int>  user_id => días
     */
    public static function workedDays(array $userIds, string $from, string $to): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = fn (string $column) => DB::table('trip_logs')
            ->whereIn($column, $userIds)->whereBetween('trip_date', [$from, $to])
            ->select([DB::raw("{$column} as user_id"), 'trip_date']);

        return DB::query()->fromSub($rows('driver_id')->union($rows('co_driver_id'))->union($rows('helper_id')), 't')
            ->selectRaw('user_id, COUNT(DISTINCT trip_date) as days')
            ->groupBy('user_id')
            ->pluck('days', 'user_id')
            ->map(fn ($days) => (int) $days)
            ->all();
    }

    /**
     * Horas de conducción por persona (minutos de ida y de regreso), heredadas por los tres del viaje.
     *
     * @param  Collection<int, TripLog>  $trips
     * @return array<int, array{outbound: int, return: int}>  user_id => minutos
     */
    public static function drivingMinutes(Collection $trips): array
    {
        $out = [];
        foreach ($trips as $trip) {
            foreach ($trip->crewIds() as $id) {
                $out[$id]['outbound'] = ($out[$id]['outbound'] ?? 0) + (int) $trip->outboundMinutes();
                $out[$id]['return'] = ($out[$id]['return'] ?? 0) + (int) $trip->returnMinutes();
            }
        }

        return $out;
    }
}
