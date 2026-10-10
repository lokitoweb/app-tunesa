<?php

namespace App\Modules\Attendance\Services\Reports;

use App\Modules\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Filtros del "Reporte de horas y faltas".
 *
 * tenantId null = vista global del Super Admin (todas las empresas).
 * viewer: quien mira el reporte; un supervisor solo ve a su equipo.
 */
final class ReportFilters
{
    /** Rango máximo permitido (días) para no lanzar cálculos gigantes desde el panel */
    public const MAX_DAYS = 366;

    public function __construct(
        public readonly ?int $tenantId,
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $branchId = null,
        public readonly ?int $userId = null,
        public readonly ?User $viewer = null,
        public readonly ?string $search = null,
    ) {}

    /** Lee y valida los filtros de la request (por defecto, el mes en curso) */
    public static function fromRequest(Request $request, ?int $tenantId): self
    {
        $data = $request->validate([
            'from'        => ['nullable', 'date_format:Y-m-d'],
            'to'          => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id'   => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'q'           => ['nullable', 'string', 'max:80'],
        ]);

        $today = Carbon::now(tenant_timezone());
        $from  = $data['from'] ?? $today->copy()->startOfMonth()->toDateString();
        $to    = $data['to'] ?? $today->toDateString();

        // Rango acotado: si piden más de un año se recorta desde el inicio
        $max = Carbon::parse($from)->addDays(self::MAX_DAYS - 1)->toDateString();
        if ($to > $max) {
            $to = $max;
        }

        return new self(
            $tenantId,
            $from,
            $to,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            isset($data['employee_id']) ? (int) $data['employee_id'] : null,
            $request->user(),
            $data['q'] ?? null,
        );
    }

    /** Parámetros para reconstruir la URL (exportaciones, paginación) */
    public function toQuery(): array
    {
        return array_filter([
            'from'        => $this->from,
            'to'          => $this->to,
            'branch_id'   => $this->branchId,
            'employee_id' => $this->userId,
            'q'           => $this->search,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
