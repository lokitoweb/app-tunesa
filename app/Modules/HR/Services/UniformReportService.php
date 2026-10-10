<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\UniformSize;
use App\Modules\Core\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consolidado de tallas para logística (compra y entrega de uniformes).
 */
class UniformReportService
{
    /** @return Collection<int, array<string, mixed>> */
    public function rows(?int $branchId = null): Collection
    {
        return User::query()
            ->whereIn('role', ['employee', 'supervisor'])
            ->where('status', 'active')
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->with(['tenant:id,company_name', 'uniformSize', 'branches:id,name', 'uniformDeliveries' => fn ($q) => $q->latest('delivered_at')])
            ->orderBy('last_name')
            ->get()
            ->map(fn (User $u) => [
                'id'             => $u->id,
                'tenant_id'      => $u->tenant_id,
                'company'        => $u->tenant?->company_name,
                'employee_code'  => $u->employee_code,
                'full_name'      => $u->full_name,
                'position'       => $u->position,
                'branch'         => $u->branches->pluck('name')->implode(', '),
                'shirt_size'     => $u->uniformSize?->shirt_size,
                'pants_size'     => $u->uniformSize?->pants_size,
                'shoe_size'      => $u->uniformSize?->shoe_size,
                'shoe_type'      => UniformSize::SHOE_TYPES[$u->uniformSize?->shoe_type] ?? null,
                'notes'          => $u->uniformSize?->notes,
                'last_delivery'  => $u->uniformDeliveries->first()?->delivered_at?->toDateString(),
                'complete'       => (bool) $u->uniformSize?->isComplete(),
            ]);
    }

    /** Cantidad de personas por talla, para la orden de compra */
    public function summary(?int $branchId = null): array
    {
        $rows = $this->rows($branchId);

        $count = fn (string $field, array $order) => collect($order)
            ->mapWithKeys(fn ($size) => [$size => $rows->where($field, $size)->count()])
            ->filter()
            ->all();

        return [
            'shirt'   => $count('shirt_size', UniformSize::SHIRT_SIZES),
            'pants'   => $count('pants_size', array_merge(UniformSize::PANTS_SIZES, UniformSize::SHIRT_SIZES)),
            'shoes'   => $count('shoe_size', UniformSize::SHOE_SIZES),
            'missing' => $rows->where('complete', false)->count(),
            'total'   => $rows->count(),
        ];
    }

    public function download(?int $branchId, string $fileName): StreamedResponse
    {
        $rows = $this->rows($branchId);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Empresa', 'Código', 'Colaborador', 'Cargo', 'Sucursal', 'Polo/Camisa', 'Pantalón', 'Calzado', 'Tipo de calzado', 'Observaciones', 'Última entrega']);
            foreach ($rows as $r) {
                fputcsv($out, array_map(
                    fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                    [$r['company'], $r['employee_code'], $r['full_name'], $r['position'], $r['branch'], $r['shirt_size'], $r['pants_size'], $r['shoe_size'], $r['shoe_type'], $r['notes'], $r['last_delivery']]
                ));
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
