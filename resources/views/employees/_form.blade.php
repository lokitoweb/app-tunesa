{{-- Formulario compartido de alta y edición de colaboradores: secciones con un solo propósito y la columna de marcación a la derecha --}}
@php
    $employee ??= null;
    $editing = (bool) $employee;
    $val = fn (string $field, $default = null) => old($field, $employee?->{$field} ?? $default);
    $roleOptions = [
        'employee'      => 'Empleado (marca con GPS y selfie)',
        'supervisor'    => 'Supervisor de turno u obra',
        'accountant'    => 'Contabilidad (boletas, planilla y reportes)',
        'logistics'     => 'Logística (proveedores, requerimientos y órdenes)',
        'hr_manager'    => 'Encargado de RRHH',
        'manager'       => 'Gerencia (indicadores y reportes, solo lectura)',
        'company_admin' => 'Administrador de la empresa',
    ];
    $assignedBranchIds = $editing ? $employee->branches->pluck('id')->all() : [];
    $primaryBranchId = $editing ? $employee->branches->firstWhere('pivot.is_primary', true)?->id : null;
    $activeShiftId = $editing ? ($currentShift?->shift_id) : null;
    $loginDomain = \App\Modules\Core\Models\TenantSetting::forTenant(app(\App\Modules\Core\Services\TenantContext::class)->getOrNull())->loginEmailDomain();
    $positionOptions = \App\Modules\Core\Models\Position::with('department:id,name')
        ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $employee?->position_id))
        ->orderBy('name')->get(['id', 'name', 'department_id', 'is_roaming'])
        ->groupBy(fn ($p) => $p->department?->name ?? 'Sin área');
    $departments = \App\Modules\Core\Models\Department::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    $scopeActor  = Auth::user();
@endphp

<div class="emp-edit">
    <div class="emp-edit-main">
        {{-- 1. Identidad --}}
        <section class="emp-edit-card">
            <header class="emp-edit-head">
                <span class="emp-edit-icon"><i class="bi bi-person-vcard"></i></span>
                <div><h2>Datos personales</h2><p>Identificación y contacto del colaborador.</p></div>
            </header>
            <div class="emp-edit-body row g-3">
                <div class="col-md-6">
                    <label for="first_name" class="form-label">Nombres <span class="text-danger">*</span></label>
                    <div class="field-icon"><i class="bi bi-person" aria-hidden="true"></i>
                        <input type="text" id="first_name" name="first_name" value="{{ $val('first_name') }}" required class="form-control @error('first_name') is-invalid @enderror">
                    </div>
                    @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label for="last_name" class="form-label">Apellidos <span class="text-danger">*</span></label>
                    <div class="field-icon"><i class="bi bi-person" aria-hidden="true"></i>
                        <input type="text" id="last_name" name="last_name" value="{{ $val('last_name') }}" required class="form-control @error('last_name') is-invalid @enderror">
                    </div>
                    @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-lg-3">
                    <label for="document_type" class="form-label">Tipo de documento <span class="text-danger">*</span></label>
                    <select id="document_type" data-modern-select data-icon="bi-person-vcard" name="document_type" class="form-select">
                        @foreach(['dni' => 'DNI', 'ce' => 'Carné de extranjería', 'passport' => 'Pasaporte'] as $code => $label)
                            <option value="{{ $code }}" @selected($val('document_type') == $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-lg-3">
                    <label for="document_number" class="form-label">N.º de documento <span class="text-danger">*</span></label>
                    <div class="field-icon"><i class="bi bi-card-text" aria-hidden="true"></i>
                        <input type="text" id="document_number" name="document_number" value="{{ $val('document_number') }}" required class="form-control @error('document_number') is-invalid @enderror">
                    </div>
                    @error('document_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-lg-3">
                    <label for="birth_date" class="form-label">Fecha de nacimiento</label>
                    <input type="date" id="birth_date" data-modern-date data-icon="bi-cake2" data-start-years-ago="30" name="birth_date" value="{{ old('birth_date', $employee?->birth_date?->format('Y-m-d') ?? '') }}" max="{{ now()->subDay()->format('Y-m-d') }}" class="form-control @error('birth_date') is-invalid @enderror" title="Para el módulo de cumpleaños (en la app solo se muestran día y mes)">
                    @error('birth_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-lg-3">
                    <label for="phone" class="form-label">Teléfono / WhatsApp</label>
                    <div class="field-icon"><i class="bi bi-telephone" aria-hidden="true"></i>
                        <input type="tel" id="phone" name="phone" value="{{ $val('phone') }}" class="form-control">
                    </div>
                </div>
                <div class="col-12">
                    <label for="avatar" class="form-label">{{ $editing ? 'Foto de perfil (reemplazar)' : 'Foto de perfil' }}</label>
                    <input type="file" id="avatar" name="avatar" accept="image/*" class="form-control">
                </div>
            </div>
        </section>

        {{-- 2. Acceso al sistema --}}
        <section class="emp-edit-card">
            <header class="emp-edit-head">
                <span class="emp-edit-icon"><i class="bi bi-shield-lock"></i></span>
                <div><h2>Acceso y permisos</h2><p>Con qué entra a la app y al panel, y qué puede hacer.</p></div>
            </header>
            <div class="emp-edit-body row g-3">
                <div class="col-md-6">
                    <label for="email" class="form-label">Correo de acceso @if($editing)<span class="text-danger">*</span>@endif</label>
                    <div class="field-icon"><i class="bi bi-envelope" aria-hidden="true"></i>
                        <input type="email" id="email" name="email" value="{{ $val('email') }}" @if($editing) required @endif
                               class="form-control @error('email') is-invalid @enderror" aria-describedby="email_help"
                               @unless($editing) placeholder="{{ 'DNI@' . $loginDomain }}" @endunless>
                    </div>
                    <div id="email_help" class="form-text">
                        @if($editing)
                            Con este correo ingresa a la app.
                        @else
                            Vacío: se genera con su documento, <code>{{ 'DNI@' . $loginDomain }}</code>.
                        @endif
                    </div>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <span class="form-label d-block">Contraseña</span>
                    @if($editing)
                        <button type="submit" form="resetAccessForm" class="btn btn-outline-warning w-100"><i class="bi bi-key me-1"></i>Restablecer acceso</button>
                        <div class="form-text">Genera una contraseña temporal y cierra sus sesiones en la app.</div>
                    @else
                        <div class="emp-edit-note">Se genera una contraseña temporal al guardar. Vence en {{ \App\Modules\Core\Services\CredentialProvisioner::TEMP_TTL_HOURS }} horas y la cambiará en su primer ingreso.</div>
                    @endif
                </div>
                <div class="{{ $editing ? 'col-md-8' : 'col-12' }}">
                    <label for="role" class="form-label">Rol <span class="text-danger">*</span></label>
                    <select id="role" data-modern-select data-icon="bi-person-badge" name="role" class="form-select @error('role') is-invalid @enderror" aria-describedby="role_help">
                        @foreach($roleOptions as $role => $label)
                            @if(Auth::user()->canAssignRole($role) || $employee?->role === $role)
                                <option value="{{ $role }}" @selected($val('role', 'employee') == $role)>{{ $label }}</option>
                            @endif
                        @endforeach
                    </select>
                    <div id="role_help" class="form-text">Solo puedes asignar roles de menor rango que el tuyo.</div>
                    @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @if($editing)
                    <div class="col-md-4">
                        <label for="status" class="form-label">Estado <span class="text-danger">*</span></label>
                        <select id="status" data-modern-select data-icon="bi-toggle-on" name="status" class="form-select">
                            @foreach(['active' => 'Activo', 'inactive' => 'Inactivo', 'suspended' => 'Suspendido'] as $code => $label)
                                <option value="{{ $code }}" @selected($val('status') == $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if($scopeActor->isOrgScoped())
                    <div class="col-12">
                        <div class="emp-edit-note"><i class="bi bi-info-circle me-1"></i>Tu alcance es <strong>{{ $scopeActor->scopeLabel() }}</strong>: los supervisores y mandos que registres quedan con ese mismo alcance.</div>
                    </div>
                @else
                    @php
                        $scopeZones    = \App\Modules\Core\Models\Zone::orderBy('name')->get(['id', 'name']);
                        $scopeBranches = \App\Modules\Core\Models\Branch::orderBy('name')->get(['id', 'name']);
                    @endphp
                    <div class="col-md-4">
                        <label for="scope_type" class="form-label">Alcance en el panel</label>
                        <select id="scope_type" data-modern-select data-icon="bi-diagram-3" name="scope_type" class="form-select @error('scope_type') is-invalid @enderror" aria-describedby="scope_help">
                            @foreach(\App\Modules\Core\Models\User::SCOPES as $value => $label)
                                <option value="{{ $value }}" @selected($val('scope_type', 'global') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('scope_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="scope_zone_id" class="form-label">Zona asignada</label>
                        <select id="scope_zone_id" data-modern-select data-icon="bi-map" name="scope_zone_id" class="form-select @error('scope_zone_id') is-invalid @enderror">
                            <option value="">—</option>
                            @foreach($scopeZones as $z)
                                <option value="{{ $z->id }}" @selected($val('scope_zone_id') == $z->id)>{{ $z->name }}</option>
                            @endforeach
                        </select>
                        @error('scope_zone_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="scope_branch_id" class="form-label">Sede asignada</label>
                        <select id="scope_branch_id" data-modern-select data-icon="bi-building" name="scope_branch_id" class="form-select @error('scope_branch_id') is-invalid @enderror">
                            <option value="">—</option>
                            @foreach($scopeBranches as $b)
                                <option value="{{ $b->id }}" @selected($val('scope_branch_id') == $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                        @error('scope_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12"><div id="scope_help" class="form-text mt-0">El alcance limita lo que ve en el panel (supervisores zonales y administradores de agencia). No aplica al rol Empleado.</div></div>
                @endif
            </div>
        </section>

        {{-- 3. Puesto, organización y remuneración --}}
        <section class="emp-edit-card">
            <header class="emp-edit-head">
                <span class="emp-edit-icon"><i class="bi bi-briefcase"></i></span>
                <div><h2>Puesto y remuneración</h2><p>Cargo, área, ingreso y sueldo.</p></div>
            </header>
            <div class="emp-edit-body row g-3">
                <div class="col-md-6">
                    <label for="position_id" class="form-label">Cargo</label>
                    <select id="position_id" data-modern-select data-icon="bi-briefcase" name="position_id" class="form-select @error('position_id') is-invalid @enderror">
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
                        <input type="hidden" name="position" value="{{ $employee->position }}">
                        <div class="form-text">Antes: «{{ $employee->position }}». Elígelo o créalo en el catálogo.</div>
                    @endif
                    @include('employees._new-position', ['select' => 'position_id', 'areaSelect' => 'department_id'])
                </div>
                @if($departments->isNotEmpty())
                    <div class="col-md-6">
                        <label for="department_id" class="form-label">Área</label>
                        <select id="department_id" data-modern-select data-icon="bi-diagram-2" name="department_id" class="form-select @error('department_id') is-invalid @enderror">
                            <option value="">Sin área</option>
                            @foreach($departments as $d)
                                <option value="{{ $d->id }}" @selected($val('department_id') == $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                        @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        {{-- Área de texto anterior al catálogo: se conserva tal cual --}}
                        <input type="hidden" name="department" value="{{ $val('department') }}">
                    </div>
                @else
                    <div class="col-md-6">
                        <label for="department" class="form-label">Área</label>
                        <div class="field-icon"><i class="bi bi-diagram-2" aria-hidden="true"></i>
                            <input type="text" id="department" name="department" value="{{ $val('department') }}" placeholder="Ej.: Operaciones" class="form-control">
                        </div>
                    </div>
                @endif
                <div class="col-6 col-lg-3">
                    <label for="employee_code" class="form-label">Código de empleado</label>
                    <div class="field-icon"><i class="bi bi-hash" aria-hidden="true"></i>
                        <input type="text" id="employee_code" name="employee_code" value="{{ $val('employee_code') }}" placeholder="Automático" class="form-control">
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <label for="hire_date" class="form-label">Fecha de ingreso</label>
                    <input type="date" id="hire_date" data-modern-date data-icon="bi-calendar-check" name="hire_date" value="{{ old('hire_date', $employee?->hire_date?->format('Y-m-d') ?? ($editing ? '' : date('Y-m-d'))) }}" class="form-control">
                </div>
                <div class="col-6 col-lg-3">
                    <label for="base_salary" class="form-label">Sueldo base mensual</label>
                    <div class="field-icon"><span class="field-prefix" aria-hidden="true">S/</span>
                        <input type="number" step="0.01" min="0" id="base_salary" name="base_salary" value="{{ $val('base_salary') }}" placeholder="0.00" class="form-control">
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <label for="bonus" class="form-label">Bono mensual</label>
                    <div class="field-icon"><span class="field-prefix" aria-hidden="true">S/</span>
                        <input type="number" step="0.01" min="0" id="bonus" name="bonus" value="{{ $val('bonus') }}" placeholder="0.00" class="form-control">
                    </div>
                </div>
            </div>
        </section>
    </div>

    {{-- Columna derecha: dónde y cuándo marca, y guardar (queda fija al bajar en pantallas grandes) --}}
    <aside class="emp-edit-side">
        <section class="emp-edit-card">
            <header class="emp-edit-head">
                <span class="emp-edit-icon"><i class="bi bi-geo-alt"></i></span>
                <div><h2>Marcación <span class="text-danger">*</span></h2><p>Sedes donde marca con GPS; una es la principal.</p></div>
            </header>
            <div class="emp-edit-branches">
                @forelse($branches as $b)
                    <div class="emp-edit-branch">
                        <div class="form-check mb-0 min-w-0">
                            <input class="form-check-input" type="checkbox" name="branch_ids[]" value="{{ $b->id }}" id="branch{{ $b->id }}"
                                   @checked(in_array($b->id, old('branch_ids', $editing ? $assignedBranchIds : ($loop->first ? [$b->id] : []))))>
                            <label class="form-check-label text-truncate d-block" for="branch{{ $b->id }}" title="{{ $b->name }}">{{ $b->name }}</label>
                        </div>
                        <div class="form-check mb-0 flex-shrink-0">
                            <input class="form-check-input" type="radio" name="primary_branch_id" value="{{ $b->id }}" id="primary{{ $b->id }}"
                                   @checked(old('primary_branch_id', $editing ? $primaryBranchId : ($loop->first ? $b->id : null)) == $b->id)>
                            <label class="form-check-label small text-body-secondary" for="primary{{ $b->id }}">Principal</label>
                        </div>
                    </div>
                @empty
                    <div class="emp-edit-body small text-danger">No hay sucursales registradas. Crea una primero.</div>
                @endforelse
            </div>
            @error('branch_ids')<div class="emp-edit-body pt-0"><div class="invalid-feedback d-block">{{ $message }}</div></div>@enderror
            <div class="emp-edit-body emp-edit-divider">
                <div class="form-check form-switch mb-1">
                    <input type="hidden" name="roaming_attendance_enabled" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="roaming_attendance_enabled" name="roaming_attendance_enabled" value="1" @checked($val('roaming_attendance_enabled'))>
                    <label class="form-check-label fw-semibold" for="roaming_attendance_enabled">Marcación itinerante</label>
                </div>
                <div class="form-text mt-0">Choferes, relevos y técnicos: marcan en cualquier sede o en ruta, sin la geocerca de su sede.</div>
            </div>
            <div class="emp-edit-body emp-edit-divider">
                <label for="shift_id" class="form-label">{{ $editing ? 'Turno actual' : 'Turno inicial' }}</label>
                <select id="shift_id" data-modern-select data-icon="bi-clock-history" name="shift_id" class="form-select">
                    <option value="">Sin turno asignado</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}" @selected(old('shift_id', $activeShiftId) == $s->id)>{{ $s->name }} ({{ hora($s->start_time) }} – {{ hora($s->end_time) }})</option>
                    @endforeach
                </select>
                <div class="form-text">Calcula tardanzas y horas extra.</div>
            </div>
        </section>

        <div class="emp-edit-actions">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>{{ $editing ? 'Guardar cambios' : 'Crear colaborador' }}</button>
            <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </aside>
</div>

{{-- Al elegir un cargo del catálogo se completa su área y, si es itinerante, la marcación en ruta --}}
<script>
document.getElementById('position_id')?.addEventListener('change', (event) => {
    const option = event.target.selectedOptions[0];
    const department = document.getElementById('department_id');
    if (department && !department.value && option?.dataset.department) {
        department.value = option.dataset.department;
        department.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (option?.dataset.roaming === '1') document.getElementById('roaming_attendance_enabled').checked = true;
});
</script>
