<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Finance\Models\Vehicle;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\ModuleRegistry;
use App\Modules\Training\Models\TrainingEnrollment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Todo lo que vence en una empresa, de una sola fuente: documentos del legajo con fecha (examen
 * médico, licencia, DNI, contrato…), fin de contratos a plazo fijo (fecha de cese), capacitaciones
 * pendientes con plazo y SOAT / revisión técnica de los vehículos. Lo usan el tablero de
 * Vencimientos y las alertas diarias (correo y Telegram).
 */
class ExpiryWatch
{
    public const KINDS = [
        'document' => 'Documento del legajo',
        'contract' => 'Fin de contrato',
        'training' => 'Capacitación',
        'vehicle'  => 'Vehículo',
    ];

    /**
     * Vencidos (hasta $pastDays atrás) y por vencer en los próximos $days días, del más urgente al último.
     *
     * @return Collection<int, array{kind: string, kind_label: string, title: string, who: ?string, date: string, days_left: int, url: ?string}>
     */
    public function items(Tenant $tenant, int $days = 90, int $pastDays = 60): Collection
    {
        $today = Carbon::now($tenant->timezone ?: config('app.timezone'))->startOfDay();
        $from = $today->copy()->subDays($pastDays)->toDateString();
        $to = $today->copy()->addDays($days)->toDateString();
        $left = fn ($date) => (int) $today->diffInDays(Carbon::parse($date)->startOfDay(), false);
        $active = fn ($q) => $q->where('status', 'active');

        $items = collect();

        EmployeeDocument::withoutGlobalScopes()->where('tenant_id', $tenant->id)->current()
            ->whereNotNull('expires_at')->whereBetween('expires_at', [$from, $to])
            ->whereHas('user', $active)->with('user:id,first_name,last_name')->get()
            ->each(fn (EmployeeDocument $d) => $items->push([
                'kind' => 'document', 'title' => $d->title ?: (EmployeeDocument::TYPES[$d->type] ?? $d->type),
                'who' => $d->user?->full_name, 'date' => $d->expires_at->toDateString(), 'days_left' => $left($d->expires_at),
                'url' => "/employees/{$d->user_id}/file",
            ]));

        User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'active')
            ->whereNotNull('termination_date')->whereBetween('termination_date', [$from, $to])->get(['id', 'first_name', 'last_name', 'termination_date'])
            ->each(fn (User $u) => $items->push([
                'kind' => 'contract', 'title' => 'Fin de contrato', 'who' => $u->full_name,
                'date' => $u->termination_date->toDateString(), 'days_left' => $left($u->termination_date), 'url' => "/employees/{$u->id}/edit",
            ]));

        if (ModuleRegistry::enabledFor($tenant, 'Training')) {
            TrainingEnrollment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('completed_at')
                ->whereNotNull('due_date')->whereBetween('due_date', [$from, $to])
                ->whereHas('user', $active)->with(['user:id,first_name,last_name', 'course:id,title'])->get()
                ->each(fn (TrainingEnrollment $e) => $items->push([
                    'kind' => 'training', 'title' => $e->course?->title ?? 'Capacitación', 'who' => $e->user?->full_name,
                    'date' => $e->due_date->toDateString(), 'days_left' => $left($e->due_date), 'url' => '/training',
                ]));
        }

        if (ModuleRegistry::enabledFor($tenant, 'Finance')) {
            Vehicle::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', true)
                ->where(fn ($q) => $q->whereBetween('soat_expires_at', [$from, $to])->orWhereBetween('technical_review_expires_at', [$from, $to]))
                ->get(['id', 'plate', 'soat_expires_at', 'technical_review_expires_at'])
                ->each(function (Vehicle $v) use ($items, $left, $from, $to) {
                    foreach (['soat_expires_at' => 'SOAT', 'technical_review_expires_at' => 'Revisión técnica'] as $column => $label) {
                        $date = $v->{$column}?->toDateString();
                        if ($date && $date >= $from && $date <= $to) {
                            $items->push(['kind' => 'vehicle', 'title' => $label, 'who' => $v->plate, 'date' => $date, 'days_left' => $left($date), 'url' => '/vehicles']);
                        }
                    }
                });
        }

        return $items->map(fn ($i) => $i + ['kind_label' => self::KINDS[$i['kind']]])
            ->sortBy([['days_left', 'asc'], ['who', 'asc']])->values();
    }
}
