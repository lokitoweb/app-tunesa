<?php

namespace App\Http\Resources;

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
            'employee_code'  => $this->employee_code,
            'position'       => $this->position,
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

            // Permisos derivados del rol
            'permissions'    => [
                'can_view_dashboard'    => in_array($this->role, ['company_admin', 'hr_manager', 'supervisor']),
                'can_manage_employees'  => in_array($this->role, ['company_admin', 'hr_manager']),
                'can_manage_shifts'     => in_array($this->role, ['company_admin', 'hr_manager']),
                'can_upload_payslips'   => in_array($this->role, ['company_admin', 'hr_manager']),
                'can_approve_requests'  => in_array($this->role, ['company_admin', 'hr_manager', 'supervisor']),
                'can_view_all_tenants'  => $this->role === 'super_admin',
            ],
        ];
    }
}
