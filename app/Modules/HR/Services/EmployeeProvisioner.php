<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\CredentialProvisioner;
use App\Modules\Onboarding\Services\OnboardingService;
use Carbon\Carbon;

/**
 * Alta de un colaborador: una sola regla para el formulario de RRHH y la importación de planilla
 * (sisgere FrmIplanilla). Correo [DNI]@dominio si no trae, contraseña temporal (72 h, cambio en el
 * primer ingreso), sedes con su principal, auditoría y su incorporación pendiente de aprobar.
 * Se llama dentro de una transacción.
 *
 * @return array{0: User, 1: string, 2: string}  [colaborador, correo, contraseña temporal]
 */
final class EmployeeProvisioner
{
    public function __construct(private readonly CredentialProvisioner $credentials) {}

    public function create(Tenant $tenant, array $attributes, array $branchIds, int $primaryBranchId): array
    {
        $email = ($attributes['email'] ?? null) ?: $this->credentials->emailFor($tenant, $attributes['document_number']);
        $password = $this->credentials->initialPassword($attributes['document_number']);

        $user = User::create(array_merge([
            'employee_code' => 'EMP-' . strtoupper(substr(uniqid(), -6)),
            'hire_date'     => Carbon::today(),
            'base_salary'   => 0,
            'bonus'         => 0,
        ], array_filter($attributes, fn ($v) => $v !== null), [
            'tenant_id' => $tenant->id, 'email' => $email, 'password' => $password, 'status' => 'active',
        ]));
        $user->forceFill($this->credentials->temporaryAttributes($password))->saveQuietly();
        $user->branches()->sync(collect($branchIds)->mapWithKeys(fn ($id) => [$id => ['is_primary' => (int) $id === $primaryBranchId]])->all());

        app(AuditLogger::class)->log('employee.created', $user, ['name' => $user->full_name, 'role' => $user->role]);
        app(OnboardingService::class)->startFor($user); // no marca hasta que RRHH apruebe su incorporación

        return [$user, $email, $password];
    }
}
