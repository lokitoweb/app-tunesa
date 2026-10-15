<?php

namespace App\Modules\Core\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserResource — Formatea la respuesta del usuario autenticado.
 * Oculta campos sensibles y normaliza los datos para la app móvil.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'full_name'      => $this->full_name,
            'first_name'     => $this->first_name,
            'last_name'      => $this->last_name,
            'email'          => $this->email,
            'role'           => $this->role,
            'status'         => $this->status,
            'must_change_password' => (bool) $this->must_change_password,
            'onboarding_status'    => $this->onboarding_status ?? 'approved',
            'employee_code'  => $this->employee_code,
            'position'       => $this->position,
            'position_id'    => $this->position_id,
            'department'     => $this->department,
            'phone'          => $this->phone,
            'avatar_url'     => $this->avatar_path
                ? asset('storage/' . $this->avatar_path)
                : null,
            'hire_date'      => $this->hire_date?->toDateString(),
            'contract_type'  => $this->contract_type,

            // Datos del tenant (empresa)
            'company'        => $this->when($this->tenant, [
                'id'           => $this->tenant?->id,
                'name'         => $this->tenant?->company_name,
                'slug'         => $this->tenant?->slug,
                'timezone'     => $this->tenant?->timezone,
                'currency'     => $this->tenant?->currency,
            ]),

            // Permisos de su rol (matriz de RolesAndPermissionsSeeder)
            'permissions'    => [
                'can_view_dashboard'    => $this->resource->hasPermission('dashboard.view'),
                'can_manage_employees'  => $this->resource->hasPermission('users.manage'),
                'can_manage_shifts'     => $this->resource->hasPermission('shifts.manage'),
                'can_upload_payslips'   => $this->resource->hasPermission('payslips.manage'),
                'can_approve_requests'  => $this->resource->hasPermission('leave.approve_team') || $this->resource->hasPermission('leave.approve_all'),
                'can_view_all_tenants'  => $this->role === 'super_admin',
                'attendance.view_team'  => $this->resource->hasPermission('attendance.view_team'),
            ],
        ];
    }
}
