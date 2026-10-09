{{-- Formulario compartido de alta y edición de colaboradores --}}
@php
    $employee ??= null;
    $editing = (bool) $employee;
    $val = fn (string $field, $default = null) => old($field, $employee?->{$field} ?? $default);
    $roleOptions = [
        'employee'      => 'Empleado (marca con GPS y selfie)',
        'supervisor'    => 'Supervisor de turno u obra',
        'hr_manager'    => 'Encargado de RRHH',
        'company_admin' => 'Administrador de la empresa',
    ];
    $assignedBranchIds = $editing ? $employee->branches->pluck('id')->all() : [];
    $primaryBranchId = $editing ? $employee->branches->firstWhere('pivot.is_primary', true)?->id : null;
    $activeShiftId = $editing ? ($currentShift?->shift_id) : null;
@endphp

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="card border-0 mb-3">
            <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Datos personales y acceso</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="first_name" class="form-label">Nombres <span class="text-danger">*</span></label>
                        <input type="text" id="first_name" name="first_name" value="{{ $val('first_name') }}" required class="form-control @error('first_name') is-invalid @enderror">
                    </div>
                    <div class="col-md-6">
                        <label for="last_name" class="form-label">Apellidos <span class="text-danger">*</span></label>
                        <input type="text" id="last_name" name="last_name" value="{{ $val('last_name') }}" required class="form-control @error('last_name') is-invalid @enderror">
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Correo electrónico <span class="text-danger">*</span></label>
                        <input type="email" id="email" name="email" value="{{ $val('email') }}" required class="form-control @error('email') is-invalid @enderror">
                    </div>
                    <div class="col-md-6">
                        <label for="password" class="form-label">{{ $editing ? 'Nueva contraseña temporal' : 'Contraseña temporal' }} @unless($editing)<span class="text-danger">*</span>@endunless</label>
                        <input type="password" id="password" name="password" @unless($editing) required @endunless autocomplete="new-password"
                               class="form-control @error('password') is-invalid @enderror" aria-describedby="password_help"
                               placeholder="{{ $editing ? 'Déjala en blanco para mantenerla' : 'Mínimo 8 caracteres' }}">
                        <div id="password_help" class="form-text">El colaborador deberá cambiarla en su primer ingreso.</div>
                    </div>
                    <div class="col-md-4">
                        <label for="document_type" class="form-label">Tipo de documento <span class="text-danger">*</span></label>
                        <select id="document_type" name="document_type" class="form-select">
                            @foreach(['DNI' => 'DNI', 'CE' => 'Carné de extranjería', 'PASSPORT' => 'Pasaporte', 'RUT' => 'RUT'] as $code => $label)
                                <option value="{{ $code }}" @selected($val('document_type') == $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="document_number" class="form-label">Número de documento <span class="text-danger">*</span></label>
                        <input type="text" id="document_number" name="document_number" value="{{ $val('document_number') }}" required class="form-control @error('document_number') is-invalid @enderror">
                    </div>
                    <div class="col-md-4">
                        <label for="phone" class="form-label">Teléfono / WhatsApp</label>
                        <input type="tel" id="phone" name="phone" value="{{ $val('phone') }}" class="form-control">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0">
            <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Puesto y rol</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="role" class="form-label">Rol <span class="text-danger">*</span></label>
                        <select id="role" name="role" class="form-select @error('role') is-invalid @enderror">
                            @foreach($roleOptions as $role => $label)
                                @if(Auth::user()->canAssignRole($role) || $employee?->role === $role)
                                    <option value="{{ $role }}" @selected($val('role', 'employee') == $role)>{{ $label }}</option>
                                @endif
                            @endforeach
                        </select>
                        <div class="form-text">Solo puedes asignar roles de menor rango que el tuyo.</div>
                    </div>
                    @if($editing)
                        <div class="col-md-3">
                            <label for="status" class="form-label">Estado <span class="text-danger">*</span></label>
                            <select id="status" name="status" class="form-select">
                                @foreach(['active' => 'Activo', 'inactive' => 'Inactivo', 'suspended' => 'Suspendido'] as $code => $label)
                                    <option value="{{ $code }}" @selected($val('status') == $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="{{ $editing ? 'col-md-3' : 'col-md-6' }}">
                        <label for="employee_code" class="form-label">Código de empleado</label>
                        <input type="text" id="employee_code" name="employee_code" value="{{ $val('employee_code') }}" placeholder="Se genera si lo dejas vacío" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label for="position" class="form-label">Cargo</label>
                        <input type="text" id="position" name="position" value="{{ $val('position') }}" placeholder="Ej.: Operador de obra" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label for="department" class="form-label">Área</label>
                        <input type="text" id="department" name="department" value="{{ $val('department') }}" placeholder="Ej.: Operaciones" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label for="base_salary" class="form-label">Sueldo base mensual</label>
                        <input type="number" step="0.01" min="0" id="base_salary" name="base_salary" value="{{ $val('base_salary') }}" placeholder="0.00" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label for="hire_date" class="form-label">Fecha de ingreso</label>
                        <input type="date" id="hire_date" name="hire_date" value="{{ old('hire_date', $employee?->hire_date?->format('Y-m-d') ?? ($editing ? '' : date('Y-m-d'))) }}" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label for="avatar" class="form-label">{{ $editing ? 'Actualizar foto' : 'Foto de perfil' }}</label>
                        <input type="file" id="avatar" name="avatar" accept="image/*" class="form-control">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card border-0 mb-3">
            <div class="card-header border-0 pt-3">
                <h2 class="h6 fw-semibold mb-0">Sucursales autorizadas <span class="text-danger">*</span></h2>
                <small class="text-body-secondary">Sedes donde puede marcar con GPS. Elige una como principal.</small>
            </div>
            <div class="list-group list-group-flush" style="max-height: 280px; overflow-y: auto;">
                @forelse($branches as $b)
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="branch_ids[]" value="{{ $b->id }}" id="branch{{ $b->id }}"
                                   @checked(in_array($b->id, old('branch_ids', $editing ? $assignedBranchIds : ($loop->first ? [$b->id] : []))))>
                            <label class="form-check-label" for="branch{{ $b->id }}">{{ $b->name }}</label>
                        </div>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="primary_branch_id" value="{{ $b->id }}" id="primary{{ $b->id }}"
                                   @checked(old('primary_branch_id', $editing ? $primaryBranchId : ($loop->first ? $b->id : null)) == $b->id)>
                            <label class="form-check-label small text-body-secondary" for="primary{{ $b->id }}">Principal</label>
                        </div>
                    </div>
                @empty
                    <div class="list-group-item small text-danger">No hay sucursales registradas. Crea una primero.</div>
                @endforelse
            </div>
        </div>

        <div class="card border-0 mb-3">
            <div class="card-header border-0 pt-3">
                <h2 class="h6 fw-semibold mb-0">{{ $editing ? 'Turno actual' : 'Turno inicial' }}</h2>
                <small class="text-body-secondary">Horario usado para calcular tardanzas y horas extra.</small>
            </div>
            <div class="card-body">
                <label for="shift_id" class="visually-hidden">Turno</label>
                <select id="shift_id" name="shift_id" class="form-select">
                    <option value="">Sin turno asignado</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}" @selected(old('shift_id', $activeShiftId) == $s->id)>
                            {{ $s->name }} ({{ substr($s->start_time, 0, 5) }} – {{ substr($s->end_time, 0, 5) }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary">{{ $editing ? 'Guardar cambios' : 'Crear colaborador' }}</button>
            <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </div>
</div>
