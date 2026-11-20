<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora de viajes de choferes (cargos con «Control por viajes»): el supervisor registra la
 * IDA (fecha, ruta, chofer, ayudante, placa, hora de salida) y la VUELTA (fecha, hora, ruta) con
 * su firma (quién la registró y cuándo). Un día con viaje, como chofer o ayudante, es día trabajado.
 */
class TripLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'branch_id', 'trip_date', 'route', 'driver_id', 'helper_id', 'vehicle_plate', 'departure_time',
        'return_date', 'return_time', 'return_route', 'return_signed_by', 'return_signed_at', 'notes', 'created_by',
    ];

    protected $casts = ['trip_date' => 'date', 'return_date' => 'date', 'return_signed_at' => 'datetime'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

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

    /** Viajes en que alguna de estas personas fue chofer o ayudante */
    public function scopeOfPeople(Builder $query, array $userIds): Builder
    {
        return $query->where(fn ($q) => $q->whereIn('driver_id', $userIds)->orWhereIn('helper_id', $userIds));
    }

    /**
     * Días trabajados por persona en el periodo: días distintos con al menos un viaje (chofer o ayudante).
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

        return DB::query()->fromSub($rows('driver_id')->union($rows('helper_id')), 't')
            ->selectRaw('user_id, COUNT(DISTINCT trip_date) as days')
            ->groupBy('user_id')
            ->pluck('days', 'user_id')
            ->map(fn ($days) => (int) $days)
            ->all();
    }
}
