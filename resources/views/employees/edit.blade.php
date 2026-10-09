@extends('layouts.app')

@section('title', 'Editar Colaborador: ' . $employee->first_name)

@section('content')
<div style="margin-bottom: 1.5rem;">
    <a href="{{ route('employees.index') }}" style="color: #818cf8; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 0.5rem;">
        ← Volver al directorio
    </a>
    <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff;">Editar Colaborador: {{ $employee->first_name }} {{ $employee->last_name }}</h1>
    <p style="color: var(--text-secondary); font-size: 0.9rem;">Actualiza datos contractuales, sucursales de marcación o credenciales.</p>
</div>

@if ($errors->any())
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 12px; padding: 1rem 1.25rem; color: #fca5a5; font-size: 0.88rem; margin-bottom: 1.5rem;">
        <div style="font-weight: 700; margin-bottom: 0.25rem;">Por favor corrige los siguientes errores:</div>
        <ul style="margin: 0; padding-left: 1.25rem;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('employees.update', $employee->id) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
        <!-- INFORMACIÓN PERSONAL Y LABORAL -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.75rem;">
            <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">
                Datos Personales & Acceso
            </h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Nombres *</label>
                    <input type="text" name="first_name" value="{{ old('first_name', $employee->first_name) }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Apellidos *</label>
                    <input type="text" name="last_name" value="{{ old('last_name', $employee->last_name) }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Correo Electrónico *</label>
                    <input type="email" name="email" value="{{ old('email', $employee->email) }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Nueva Contraseña (Opcional)</label>
                    <input type="password" name="password" class="form-control" placeholder="Dejar en blanco para mantener" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Tipo Documento *</label>
                    <select name="document_type" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                        @foreach (['dni' => 'DNI', 'ce' => 'Carné de Extranjería', 'passport' => 'Pasaporte'] as $docValue => $docLabel)
                            <option value="{{ $docValue }}" @selected(old('document_type', $employee->document_type) === $docValue)>{{ $docLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Número de Documento *</label>
                    <input type="text" name="document_number" value="{{ old('document_number', $employee->document_number) }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Teléfono / WhatsApp</label>
                    <input type="text" name="phone" value="{{ old('phone', $employee->phone) }}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; margin: 1.5rem 0 1.25rem 0; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">
                Información del Puesto y Rol
            </h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Rol en Sistema *</label>
                    <select name="role" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                        <option value="employee" {{ old('role', $employee->role) == 'employee' ? 'selected' : '' }}>Empleado Móvil</option>
                        <option value="supervisor" {{ old('role', $employee->role) == 'supervisor' ? 'selected' : '' }}>Supervisor</option>
                        <option value="hr_manager" {{ old('role', $employee->role) == 'hr_manager' ? 'selected' : '' }}>RRHH Manager</option>
                        <option value="company_admin" {{ old('role', $employee->role) == 'company_admin' ? 'selected' : '' }}>Administrador General</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Estado *</label>
                    <select name="status" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                        <option value="active" {{ old('status', $employee->status) == 'active' ? 'selected' : '' }}>Activo</option>
                        <option value="inactive" {{ old('status', $employee->status) == 'inactive' ? 'selected' : '' }}>Inactivo</option>
                        <option value="suspended" {{ old('status', $employee->status) == 'suspended' ? 'selected' : '' }}>Suspendido</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Código Empleado</label>
                    <input type="text" name="employee_code" value="{{ old('employee_code', $employee->employee_code) }}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Cargo</label>
                    <input type="text" name="position" value="{{ old('position', $employee->position) }}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Departamento</label>
                    <input type="text" name="department" value="{{ old('department', $employee->department) }}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Sueldo Base</label>
                    <input type="number" step="0.01" name="base_salary" value="{{ old('base_salary', $employee->base_salary) }}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Fecha Contratación</label>
                    <input type="date" name="hire_date" value="{{ old('hire_date', $employee->hire_date ? $employee->hire_date->format('Y-m-d') : '') }}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Actualizar Foto</label>
                    <input type="file" name="avatar" accept="image/*" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.45rem; border-radius: 8px; font-size: 0.8rem;">
                </div>
            </div>
        </div>

        <!-- SUCURSALES Y HORARIO -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <!-- ASIGNACIÓN DE SUCURSALES -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem;">
                <h3 style="color: #fff; font-size: 1.05rem; font-weight: 700; margin-bottom: 0.5rem;">Sucursales Autorizadas *</h3>
                <p style="font-size: 0.78rem; color: #94a3b8; margin-bottom: 1rem;">Sedes donde el colaborador puede fichar con GPS.</p>

                @php
                    $assignedBranchIds = $employee->branches->pluck('id')->toArray();
                    $primaryBranch = $employee->branches->where('pivot.is_primary', true)->first();
                    $primaryBranchId = $primaryBranch ? $primaryBranch->id : null;
                @endphp

                <div style="display: flex; flex-direction: column; gap: 10px; max-height: 240px; overflow-y: auto;">
                    @foreach($branches as $b)
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #1a1c26; border-radius: 8px; border: 1px solid var(--border);">
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #fff; cursor: pointer;">
                                <input type="checkbox" name="branch_ids[]" value="{{ $b->id }}" {{ in_array($b->id, old('branch_ids', $assignedBranchIds)) ? 'checked' : '' }} style="accent-color: #6366f1;">
                                {{ $b->name }}
                            </label>
                            <label style="font-size: 0.75rem; color: #818cf8; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                                <input type="radio" name="primary_branch_id" value="{{ $b->id }}" {{ old('primary_branch_id', $primaryBranchId) == $b->id ? 'checked' : '' }} style="accent-color: #6366f1;">
                                Principal
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- TURNO ACTIVO -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem;">
                <h3 style="color: #fff; font-size: 1.05rem; font-weight: 700; margin-bottom: 0.5rem;">Turno Activo</h3>
                <p style="font-size: 0.78rem; color: #94a3b8; margin-bottom: 1rem;">Horario pactado actual.</p>

                @php
                    $activeShiftId = $currentShift ? $currentShift->shift_id : null;
                @endphp

                <select name="shift_id" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                    <option value="">-- Sin turno asignado --</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}" {{ old('shift_id', $activeShiftId) == $s->id ? 'selected' : '' }}>
                            {{ $s->name }} ({{ substr($s->start_time, 0, 5) }} - {{ substr($s->end_time, 0, 5) }})
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- BOTONES DE ACCIÓN -->
            <div style="display: flex; gap: 10px;">
                <button type="submit" style="flex: 1; padding: 0.85rem; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border: none; border-radius: 10px; color: #fff; font-weight: 700; cursor: pointer;">
                    Actualizar Cambios
                </button>
                <a href="{{ route('employees.index') }}" style="padding: 0.85rem 1.25rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: #cbd5e1; text-decoration: none; font-weight: 600; text-align: center;">
                    Cancelar
                </a>
            </div>
        </div>
    </div>
</form>
@endsection
