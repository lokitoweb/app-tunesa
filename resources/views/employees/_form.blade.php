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
    $loginDomain = \App\Modules\Core\Models\TenantSetting::forTenant(app(\App\Modules\Core\Services\TenantContext::class)->getOrNull())->loginEmailDomain();
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
                        <label for="email" class="form-label">Correo de acceso @if($editing)<span class="text-danger">*</span>@endif</label>
                        <input type="email" id="email" name="email" value="{{ $val('email') }}" @if($editing) required @endif
                               class="form-control @error('email') is-invalid @enderror" aria-describedby="email_help"
                               @unless($editing) placeholder="DNI@{{ $loginDomain }}" @endunless>
                        <div id="email_help" class="form-text">
                            @if($editing)
                                Con este correo ingresa a la app.
                            @else
                                Vacío: se genera con su documento, <code>DNI@{{ $loginDomain }}</code>. Escríbelo solo si usará otro correo.
                            @endif
                        </div>
                    </div>
                    <div class="col-md-6">
                        <span class="form-label d-block">Contraseña</span>
                        @if($editing)
                            <button type="submit" form="resetAccessForm" class="btn btn-outline-warning"><i class="bi bi-key me-1"></i>Restablecer acceso</button>
                            <div class="form-text">Genera una contraseña temporal nueva y cierra sus sesiones en la app. La app no permite recuperarla ni cambiarla.</div>
                        @else
                            <p class="form-text mt-0 mb-0">Se genera una contraseña temporal al guardar. Vence en {{ \App\Modules\Core\Services\CredentialProvisioner::TEMP_TTL_HOURS }} horas y deberá cambiarla en su primer ingreso.</p>
                        @endif
                    </div>
                    <div class="col-md-4">
                        <label for="document_type" class="form-label">Tipo de documento <span class="text-danger">*</span></label>
                        <select id="document_type" name="document_type" class="form-select">
                            @foreach(['dni' => 'DNI', 'ce' => 'Carné de extranjería', 'passport' => 'Pasaporte'] as $code => $label)
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
                    <div class="col-md-4">
                        <label for="birth_date" class="form-label">Fecha de nacimiento</label>
                        <input type="date" id="birth_date" name="birth_date" value="{{ old('birth_date', isset($employee) ? $employee->birth_date?->format('Y-m-d') : '') }}" max="{{ now()->subDay()->format('Y-m-d') }}" class="form-control @error('birth_date') is-invalid @enderror" aria-describedby="birth_date_help">
                        <div id="birth_date_help" class="form-text">Para el módulo de cumpleaños (en la app solo se muestran día y mes).</div>
                        @error('birth_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                    @php
                        $positionOptions = \App\Modules\Core\Models\Position::with('department:id,name')
                            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $employee?->position_id))
                            ->orderBy('name')->get(['id', 'name', 'department_id', 'is_roaming'])
                            ->groupBy(fn ($p) => $p->department?->name ?? 'Sin área');
                    @endphp
                    <div class="col-md-4">
                        <label for="{{ $positionOptions->isNotEmpty() ? 'position_id' : 'position' }}" class="form-label">Cargo</label>
                        @if($positionOptions->isNotEmpty())
                            <select id="position_id" name="position_id" class="form-select @error('position_id') is-invalid @enderror">
                                <option value="">Sin cargo</option>
                                @foreach($positionOptions as $group => $items)
                                    <optgroup label="{{ $group }}">
                                        @foreach($items as $p)
                                            <option value="{{ $p->id }}" data-department="{{ $p->department_id }}" data-roaming="{{ $p->is_roaming ? 1 : 0 }}" @selected($val('position_id') == $p->id)>{{ $p->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('position_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if($employee?->position && ! $employee->position_id)
                                <div class="form-text">Antes: «{{ $employee->position }}». Elige su cargo del catálogo.</div>
                            @else
                                <div class="form-text">Los cargos se administran en <a href="{{ route('organization.index') }}">Organización</a>.</div>
                            @endif
                        @else
                            <input type="text" id="position" name="position" value="{{ $val('position') }}" placeholder="Ej.: Almacenero" class="form-control">
                            <div class="form-text">Crea el catálogo de cargos en <a href="{{ route('organization.index') }}">Organización</a>.</div>
                        @endif
                    </div>
                    <div class="col-md-4">
                        <label for="department" class="form-label">Área</label>
                        <input type="text" id="department" name="department" value="{{ $val('department') }}" placeholder="Ej.: Operaciones" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label for="base_salary" class="form-label">Sueldo base mensual</label>
                        <input type="number" step="0.01" min="0" id="base_salary" name="base_salary" value="{{ $val('base_salary') }}" placeholder="0.00" class="form-control">
                    </div>
                    @php
                        $departments = \App\Modules\Core\Models\Department::where('is_active', true)->orderBy('name')->get(['id', 'name']);
                        $scopeActor  = Auth::user();
                    @endphp
                    @if($departments->isNotEmpty())
                        <div class="col-md-6">
                            <label for="department_id" class="form-label">Área de la organización</label>
                            <select id="department_id" name="department_id" class="form-select @error('department_id') is-invalid @enderror">
                                <option value="">Sin área</option>
                                @foreach($departments as $d)
                                    <option value="{{ $d->id }}" @selected($val('department_id') == $d->id)>{{ $d->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Catálogo de Organización: agrupa la dotación por sede y área.</div>
                        </div>
                    @endif
                    <div class="col-md-6">
                        <div class="form-check form-switch mt-md-4">
                            <input type="hidden" name="roaming_attendance_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="roaming_attendance_enabled" name="roaming_attendance_enabled" value="1" @checked($val('roaming_attendance_enabled'))>
                            <label class="form-check-label" for="roaming_attendance_enabled">Marcación itinerante</label>
                        </div>
                        <div class="form-text">Choferes, relevos y técnicos: marcan en cualquier sede de la empresa o en ruta, sin exigir la geocerca de su sede.</div>
                    </div>
                    @if($scopeActor->isOrgScoped())
                        <div class="col-12">
                            <div class="alert alert-info small mb-0">Tu alcance es <strong>{{ $scopeActor->scopeLabel() }}</strong>: los supervisores y mandos que registres quedan con ese mismo alcance.</div>
                        </div>
                    @else
                        @php
                            $scopeZones    = \App\Modules\Core\Models\Zone::orderBy('name')->get(['id', 'name']);
                            $scopeBranches = \App\Modules\Core\Models\Branch::orderBy('name')->get(['id', 'name']);
                        @endphp
                        <div class="col-md-4">
                            <label for="scope_type" class="form-label">Alcance en el panel</label>
                            <select id="scope_type" name="scope_type" class="form-select @error('scope_type') is-invalid @enderror">
                                @foreach(\App\Modules\Core\Models\User::SCOPES as $value => $label)
                                    <option value="{{ $value }}" @selected($val('scope_type', 'global') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Para supervisores zonales y administradores de agencia. No aplica al rol Colaborador.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="scope_zone_id" class="form-label">Zona asignada</label>
                            <select id="scope_zone_id" name="scope_zone_id" class="form-select @error('scope_zone_id') is-invalid @enderror">
                                <option value="">—</option>
                                @foreach($scopeZones as $z)
                                    <option value="{{ $z->id }}" @selected($val('scope_zone_id') == $z->id)>{{ $z->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="scope_branch_id" class="form-label">Sede asignada</label>
                            <select id="scope_branch_id" name="scope_branch_id" class="form-select @error('scope_branch_id') is-invalid @enderror">
                                <option value="">—</option>
                                @foreach($scopeBranches as $b)
                                    <option value="{{ $b->id }}" @selected($val('scope_branch_id') == $b->id)>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
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
                            {{ $s->name }} ({{ hora($s->start_time) }} – {{ hora($s->end_time) }})
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

{{-- Al elegir un cargo del catálogo se completa su área y, si es itinerante, la marcación en ruta --}}
<script>
document.getElementById('position_id')?.addEventListener('change', (event) => {
    const option = event.target.selectedOptions[0];
    const department = document.getElementById('department_id');
    if (department && !department.value && option?.dataset.department) department.value = option.dataset.department;
    if (option?.dataset.roaming === '1') document.getElementById('roaming_attendance_enabled').checked = true;
});
</script>
