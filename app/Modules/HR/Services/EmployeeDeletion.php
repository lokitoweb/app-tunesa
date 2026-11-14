<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Core\Services\UserPurger;
use Illuminate\Support\Facades\DB;

/**
 * «Eliminar colaborador» (permiso employees.delete: el Super Admin, y a quien él se lo dé): para un
 * registro hecho por error. Borra a la persona con todo lo suyo (misma regla que la limpieza: UserPurger)
 * y deja en la bitácora de su empresa quién, cuándo y qué tenía, aunque el registro ya no exista.
 * Nunca un Super Admin ni uno mismo; quien no es Super Admin solo elimina a quien puede administrar.
 */
final class EmployeeDeletion
{
    public function __construct(
        private readonly UserPurger $purger,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** Lo que se eliminaría (para mostrarlo antes de confirmar) */
    public function footprint(User $employee): array
    {
        return $this->purger->footprint([$employee->id]);
    }

    /** ¿Ve la opción de eliminar? (ficha, listado y diálogo de edición usan esta misma regla) */
    public function canDelete(User $employee, User $actor): bool
    {
        return $actor->can('employees.delete') && ! $employee->isSuperAdmin() && $employee->tenant_id
            && (int) $employee->id !== (int) $actor->id && ($actor->isSuperAdmin() || $actor->canManage($employee));
    }

    /** Quién puede eliminar a quién (antes de pedir nada más) */
    public function authorize(User $employee, User $actor): void
    {
        abort_if($employee->isSuperAdmin() || ! $employee->tenant_id, 403, 'Un Super Admin no se elimina desde aquí.');
        abort_if((int) $employee->id === (int) $actor->id, 403, 'No puedes eliminarte a ti mismo.');
        abort_unless($actor->isSuperAdmin() || $actor->canManage($employee), 403, 'No puedes eliminar a alguien de un rol igual o superior al tuyo.');
    }

    public function delete(User $employee, User $actor): void
    {
        $this->authorize($employee, $actor);

        $ids = [$employee->id];
        $files = $this->purger->filesFor($ids);
        $tenant = Tenant::find($employee->tenant_id);
        $record = [
            'name'     => $employee->full_name,
            'document' => trim(strtoupper((string) $employee->document_type) . ' ' . $employee->document_number),
            'email'    => $employee->email,
            'role'     => $employee->role,
            'company'  => $tenant?->company_name,
            'had'      => $this->footprint($employee),
        ];

        $previous = $this->context->getOrNull();
        try {
            DB::transaction(function () use ($ids, $tenant, $record) {
                // En la bitácora de su empresa; si el borrado falla, tampoco queda este registro
                if ($tenant) {
                    $this->context->set($tenant);
                }
                $this->audit->log('employee.deleted_permanently', null, $record);
                $this->purger->deleteRows($ids);
            });
        } finally {
            $previous ? $this->context->set($previous) : $this->context->clear();
        }

        $this->purger->deleteFiles($files);
    }
}
