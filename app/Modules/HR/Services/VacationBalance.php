<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\LaborRegime;
use App\Modules\HR\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Saldo de vacaciones (QA Ronda 2 · regla vigente 9, CP-04).
 *
 * Régimen general: por cada año de servicio cumplido desde la fecha de ingreso se ganan
 * TenantSetting::vacation_days_per_year días calendario (30 por defecto). Se descuentan, en
 * días calendario, las vacaciones aprobadas desde el ingreso hasta la fecha de corte; al
 * aprobarse una solicitud el saldo baja solo, porque se calcula siempre a partir de ellas.
 *
 * Cada régimen puede tener sus propios días (LaborRegime::vacation_days, p. ej. 15 en MYPE). Sin
 * fecha de ingreso, o con un régimen sin días definidos, no se calcula: no se inventa.
 */
class VacationBalance
{
    /**
     * @param  Collection<int, User>  $users  de una misma empresa
     * @return array<int, array{years: int, earned: int, used: int, balance: int}|null>  user_id => saldo (null = no aplica)
     */
    public function forUsers(Collection $users, CarbonInterface $asOf): array
    {
        if ($users->isEmpty()) {
            return [];
        }
        $asOf = CarbonImmutable::parse($asOf->toDateString());
        $ids = $users->pluck('id')->all();
        $regimes = EmployeeProfile::withoutGlobalScopes()->whereIn('user_id', $ids)->pluck('labor_regime', 'user_id');
        $vacations = LeaveRequest::withoutGlobalScopes()->whereIn('user_id', $ids)
            ->where('type', 'vacation')->where('status', 'approved')
            ->where('start_date', '<=', $asOf->toDateString())
            ->get(['user_id', 'start_date', 'end_date'])->groupBy('user_id');
        $perYear = [];
        $catalog = [];

        $out = [];
        foreach ($users as $user) {
            $regime = $regimes[$user->id] ?? null;
            $catalog[$user->tenant_id] ??= LaborRegime::catalog((int) $user->tenant_id)->keyBy('code');
            $days = $regime ? $catalog[$user->tenant_id]->get($regime)?->vacation_days : null;
            // Régimen general (o sin régimen) sin días propios: los de la empresa (Marca y app)
            if ($days === null && in_array($regime, [null, 'general'], true)) {
                $days = $perYear[$user->tenant_id] ??= (int) TenantSetting::forTenant($user->tenant)->vacation_days_per_year;
            }
            if (! $user->hire_date || $days === null) {
                $out[$user->id] = null;

                continue;
            }
            $hire = CarbonImmutable::parse($user->hire_date->toDateString());
            $years = $hire->gt($asOf) ? 0 : (int) $hire->diffInYears($asOf);
            $used = (int) $vacations->get($user->id, collect())->sum(fn (LeaveRequest $l) => $l->daysWithin($hire, $asOf));

            $out[$user->id] = ['years' => $years, 'earned' => $years * $days, 'used' => $used, 'balance' => $years * $days - $used];
        }

        return $out;
    }

    /** @return array{years: int, earned: int, used: int, balance: int}|null */
    public function for(User $user, CarbonInterface $asOf): ?array
    {
        return $this->forUsers(collect([$user]), $asOf)[$user->id];
    }
}
