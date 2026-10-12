<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Filtros del calendario con semáforo: mes (Y-m), sede, zona, área, cargo y búsqueda.
 * tenantId null = vista global del Super Admin. viewer: quien mira (alcance).
 */
final class CalendarFilters
{
    public function __construct(
        public readonly ?int $tenantId,
        public readonly string $month,
        public readonly ?int $branchId = null,
        public readonly ?int $zoneId = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $positionId = null,
        public readonly ?string $search = null,
        public readonly ?User $viewer = null,
    ) {}

    public static function fromRequest(Request $request, ?int $tenantId): self
    {
        $data = $request->validate([
            'month'         => ['nullable', 'date_format:Y-m'],
            'branch_id'     => ['nullable', 'integer'],
            'zone_id'       => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'position_id'   => ['nullable', 'integer'],
            'q'             => ['nullable', 'string', 'max:80'],
        ]);

        $int = fn (string $key) => isset($data[$key]) ? (int) $data[$key] : null;

        return new self(
            $tenantId,
            $data['month'] ?? CarbonImmutable::now(tenant_timezone())->format('Y-m'),
            $int('branch_id'),
            $int('zone_id'),
            $int('department_id'),
            $int('position_id'),
            $data['q'] ?? null,
            $request->user(),
        );
    }

    /** @return array{0: string, 1: string} primer y último día del mes (Y-m-d) */
    public function range(): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $this->month);

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    public function previousMonth(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month)->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month)->addMonthNoOverflow()->format('Y-m');
    }

    /** Parámetros para reconstruir la URL (paginación, exportación, meses) */
    public function toQuery(array $override = []): array
    {
        return array_filter(array_merge([
            'month'         => $this->month,
            'branch_id'     => $this->branchId,
            'zone_id'       => $this->zoneId,
            'department_id' => $this->departmentId,
            'position_id'   => $this->positionId,
            'q'             => $this->search,
        ], $override), fn ($v) => $v !== null && $v !== '');
    }
}
