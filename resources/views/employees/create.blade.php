@extends('layouts.app')

@section('title', 'Nuevo Colaborador')

@section('content')
<div style="margin-bottom: 1.5rem;">
    <a href="{{ route('employees.index') }}" style="color: #818cf8; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 0.5rem;">
        ← Volver al directorio
    </a>
    <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff;">Alta de Nuevo Colaborador</h1>
    <p style="color: var(--text-secondary); font-size: 0.9rem;">Registra un empleado o administrador y asígnale sucursales con geocercas autorizadas.</p>
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

<form method="POST" action="{{ route('employees.store') }}" enctype="multipart/form-data">
    @csrf

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
        <!-- INFORMACIÓN PERSONAL Y LABORAL -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.75rem;">
            <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">
                Datos Personales & Acceso
            </h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Nombres *</label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Apellidos *</label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Correo Electrónico *</label>
                    <input type="email" name="email" value="{{ old('email') }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Contraseña de Acceso *</label>
                    <input type="password" name="password" required class="form-control" placeholder="Mínimo 8 caracteres" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Tipo Documento *</label>
                    <select name="document_type" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                        @foreach (['dni' => 'DNI', 'ce' => 'Carné de Extranjería', 'passport' => 'Pasaporte'] as $docValue => $docLabel)
                            <option value="{{ $docValue }}" @selected(old('document_type') === $docValue)>{{ $docLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Número de Documento *</label>
                    <input type="text" name="document_number" value="{{ old('document_number') }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Teléfono / WhatsApp</label>
                    <input type="text" name="phone" value="{{ old('phone') }}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; margin: 1.5rem 0 1.25rem 0; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">
                Información del Puesto y Rol
            </h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Rol en Sistema *</label>
                    <select name="role" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                        <option value="employee" {{ old('role') == 'employee' ? 'selected' : '' }}>Empleado Móvil (Marcación con GPS y Selfie)</option>
                        <option value="supervisor" {{ old('role') == 'supervisor' ? 'selected' : '' }}>Supervisor de Turno / Obra</option>
                        <option value="hr_manager" {{ old('role') == 'hr_manager' ? 'selected' : '' }}>Encargado de RRHH</option>
                        <option value="company_admin" {{ old('role') == 'company_admin' ? 'selected' : '' }}>Administrador General Empresa</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Código de Empleado</label>
                    <input type="text" name="employee_code" value="{{ old('employee_code') }}" placeholder="Auto-generado si se deja en blanco" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Cargo / Posición</label>
                    <input type="text" name="position" value="{{ old('position') }}" placeholder="Ej: Operador de Obra" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Departamento</label>
                    <input type="text" name="department" value="{{ old('department') }}" placeholder="Ej: Operaciones" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Sueldo Base Mensual</label>
                    <input type="number" step="0.01" name="base_salary" value="{{ old('base_salary') }}" placeholder="0.00" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Fecha de Contratación</label>
                    <input type="date" name="hire_date" value="{{ old('hire_date', date('Y-m-d')) }}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Fotografía de Perfil</label>
                    <input type="file" name="avatar" accept="image/*" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.45rem; border-radius: 8px; font-size: 0.8rem;">
                </div>
            </div>
        </div>

        <!-- SUCURSALES Y HORARIO -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <!-- ASIGNACIÓN DE SUCURSALES -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem;">
                <h3 style="color: #fff; font-size: 1.05rem; font-weight: 700; margin-bottom: 0.5rem;">Sucursales Autorizadas *</h3>
                <p style="font-size: 0.78rem; color: #94a3b8; margin-bottom: 1rem;">Marca las sedes donde el empleado puede registrar asistencia con GPS.</p>

                <div style="display: flex; flex-direction: column; gap: 10px; max-height: 240px; overflow-y: auto;">
                    @forelse($branches as $b)
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #1a1c26; border-radius: 8px; border: 1px solid var(--border);">
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #fff; cursor: pointer;">
                                <input type="checkbox" name="branch_ids[]" value="{{ $b->id }}" {{ in_array($b->id, old('branch_ids', [])) || $loop->first ? 'checked' : '' }} style="accent-color: #6366f1;">
                                {{ $b->name }}
                            </label>
                            <label style="font-size: 0.75rem; color: #818cf8; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                                <input type="radio" name="primary_branch_id" value="{{ $b->id }}" {{ old('primary_branch_id') == $b->id || $loop->first ? 'checked' : '' }} style="accent-color: #6366f1;">
                                Principal
                            </label>
                        </div>
                    @empty
                        <div style="font-size: 0.8rem; color: #ef4444;">No hay sucursales registradas aún. Crea una primero.</div>
                    @endforelse
                </div>
            </div>

            <!-- ASIGNACIÓN DE TURNO -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem;">
                <h3 style="color: #fff; font-size: 1.05rem; font-weight: 700; margin-bottom: 0.5rem;">Plantilla de Turno</h3>
                <p style="font-size: 0.78rem; color: #94a3b8; margin-bottom: 1rem;">Horario pactado para el cómputo de tardanzas y horas extras.</p>

                <select name="shift_id" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                    <option value="">-- Sin turno inicial asignado --</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}" {{ old('shift_id') == $s->id ? 'selected' : '' }}>
                            {{ $s->name }} ({{ substr($s->start_time, 0, 5) }} - {{ substr($s->end_time, 0, 5) }})
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- BOTONES DE ACCIÓN -->
            <div style="display: flex; gap: 10px;">
                <button type="submit" style="flex: 1; padding: 0.85rem; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border: none; border-radius: 10px; color: #fff; font-weight: 700; cursor: pointer; box-shadow: 0 4px 15px rgba(99,102,241,0.35);">
                    Guardar Colaborador
                </button>
                <a href="{{ route('employees.index') }}" style="padding: 0.85rem 1.25rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: #cbd5e1; text-decoration: none; font-weight: 600; text-align: center;">
                    Cancelar
                </a>
            </div>
        </div>
    </div>
</form>
@endsection
