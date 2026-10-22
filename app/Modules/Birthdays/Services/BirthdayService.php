<?php

namespace App\Modules\Birthdays\Services;

use App\Modules\Birthdays\Notifications\BirthdayGreeting;
use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Audience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Cumpleaños del personal. Se busca por users.birth_md (MMDD) para que
 * funcione igual en MySQL, PostgreSQL o SQLite y use índice.
 *
 * Privacidad: nunca se expone el año de nacimiento ni la edad, solo día y mes.
 * Quien nació un 29 de febrero lo celebra el 28 en los años no bisiestos.
 */
class BirthdayService
{
    public const MONTHS = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** Personas activas de la empresa con fecha de nacimiento; 'team' = comparten sucursal con $viewer */
    public function people(int $tenantId, ?User $viewer = null, string $scope = 'company'): Builder
    {
        return User::withoutGlobalScope(TenantScope::class)
            ->where('users.tenant_id', $tenantId)
            ->where('users.status', 'active')
            ->where('users.role', '!=', 'super_admin')
            ->whereNotNull('users.birth_md')
            ->when($scope === 'team' && $viewer, function (Builder $q) use ($viewer) {
                $branchIds = Audience::branchIds($viewer);
                $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('employee_branch')
                    ->whereColumn('employee_branch.user_id', 'users.id')
                    ->whereIn('employee_branch.branch_id', $branchIds ?: [0])
                    ->where(fn ($w) => $w->whereNull('employee_branch.assigned_until')->orWhere('employee_branch.assigned_until', '>=', now()->toDateString())));
            })
            ->with(['primaryBranch:id,name', 'department:id,name']);
    }

    /** Valores MMDD que se celebran en una fecha (incluye 29/02 el 28/02 de años no bisiestos) */
    public function mdFor(Carbon $date): array
    {
        $md = [(int) $date->format('nd')];
        if ($date->month === 2 && $date->day === 28 && ! $date->isLeapYear()) {
            $md[] = 229;
        }

        return $md;
    }

    /** Cumpleaños de los próximos $days días (hoy incluido), del más cercano al más lejano */
    public function upcoming(int $tenantId, Carbon $today, int $days = 30, ?User $viewer = null, string $scope = 'company', ?int $limit = null): Collection
    {
        $mds = [];
        for ($i = 0; $i <= $days; $i++) {
            array_push($mds, ...$this->mdFor($today->copy()->addDays($i)));
        }

        $people = $this->people($tenantId, $viewer, $scope)->whereIn('users.birth_md', array_unique($mds))->get()
            ->map(fn (User $u) => $this->present($u, $today))
            ->sortBy(fn ($p) => [$p['days_until'], $p['name']])
            ->values();

        return $limit ? $people->take($limit)->values() : $people;
    }

    /** Cumpleaños de un mes, en orden de día */
    public function month(int $tenantId, int $month, Carbon $today, ?User $viewer = null, string $scope = 'company', string $search = ''): Collection
    {
        return $this->people($tenantId, $viewer, $scope)
            ->whereBetween('users.birth_md', [$month * 100 + 1, $month * 100 + 31])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('users.first_name', 'like', "%{$search}%")
                ->orWhere('users.last_name', 'like', "%{$search}%")->orWhere('users.position', 'like', "%{$search}%")))
            ->orderBy('users.birth_md')->orderBy('users.first_name')
            ->limit(2000)
            ->get()
            ->map(fn (User $u) => $this->present($u, $today))
            ->values();
    }

    /** Quienes cumplen hoy */
    public function today(int $tenantId, Carbon $today): Collection
    {
        return $this->people($tenantId)->whereIn('users.birth_md', $this->mdFor($today))->get();
    }

    /**
     * Saludo de la empresa para quien cumple años hoy (modal de la app), o null.
     * Mismo texto que el aviso push del día (BirthdayGreeting).
     */
    public function greetingFor(User $user, Carbon $today): ?array
    {
        if (! $user->birth_md || ! in_array((int) $user->birth_md, $this->mdFor($today), true)) {
            return null;
        }

        $company = TenantSetting::forTenant($user->tenant)->tradeName();
        $note    = new BirthdayGreeting((string) $user->first_name, $company);

        return [
            'date'       => $today->toDateString(),
            'first_name' => $user->first_name,
            'company'    => $company,
            'title'      => $note->title(),
            'body'       => $note->body(),
        ];
    }

    /** Ficha pública de cumpleaños (sin año ni edad) */
    public function present(User $user, Carbon $today): array
    {
        $month = intdiv((int) $user->birth_md, 100);
        $day   = (int) $user->birth_md % 100;
        $next  = $this->nextOccurrence($month, $day, $today);

        return [
            'id'          => $user->id,
            'name'        => $user->full_name,
            'first_name'  => $user->first_name,
            'initials'    => mb_strtoupper(mb_substr((string) $user->first_name, 0, 1) . mb_substr((string) $user->last_name, 0, 1)),
            'position'    => $user->position,
            'department'  => $user->department?->name,
            'branch'      => $user->primaryBranch->first()?->name,
            'avatar_url'  => $user->avatar_path ? Storage::disk('public')->url($user->avatar_path) : null,
            'day'         => $day,
            'month'       => $month,
            'date_label'  => sprintf('%02d de %s', $day, self::MONTHS[$month] ?? ''),
            'is_today'    => $next->isSameDay($today),
            'days_until'  => (int) $today->copy()->startOfDay()->diffInDays($next->copy()->startOfDay()),
            'next_date'   => $next->toDateString(),
        ];
    }

    private function nextOccurrence(int $month, int $day, Carbon $today): Carbon
    {
        $year = $today->year;
        $make = fn (int $y) => ($month === 2 && $day === 29 && ! Carbon::create($y)->isLeapYear())
            ? Carbon::create($y, 2, 28, 0, 0, 0, $today->timezone)
            : Carbon::create($y, $month, $day, 0, 0, 0, $today->timezone);

        $date = $make($year);

        return $date->lt($today->copy()->startOfDay()) ? $make($year + 1) : $date;
    }
}
