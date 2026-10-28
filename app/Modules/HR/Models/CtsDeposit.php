<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Depósito semestral de CTS de un colaborador, congelado como se calculó (la constancia se reimprime igual) */
class CtsDeposit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'period_start', 'period_end', 'due_date', 'data', 'amount', 'created_by'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date', 'data' => 'array', 'amount' => 'float'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Semestre de CTS por su mes de depósito: mayo = 1 nov – 30 abr (deposita hasta el 15 de mayo);
     * noviembre = 1 may – 31 oct (hasta el 15 de noviembre).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable} inicio, fin, vencimiento
     */
    public static function semester(int $year, int $depositMonth): array
    {
        return $depositMonth === 5
            ? [CarbonImmutable::create($year - 1, 11, 1), CarbonImmutable::create($year, 4, 30), CarbonImmutable::create($year, 5, 15)]
            : [CarbonImmutable::create($year, 5, 1), CarbonImmutable::create($year, 10, 31), CarbonImmutable::create($year, 11, 15)];
    }

    /** El depósito que toca ahora: el próximo vencimiento, o el último si aún se está pagando */
    public static function currentSemester(CarbonImmutable $today): array
    {
        return match (true) {
            $today->month <= 5  => [$today->year, 5],
            $today->month <= 11 => [$today->year, 11],
            default             => [$today->year + 1, 5],
        };
    }

    /** Código del periodo de pago en Tesorería (mes del depósito) */
    public function paymentPeriod(): string
    {
        return $this->due_date->format('Y-m');
    }
}
