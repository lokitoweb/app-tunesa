@php
    $actor = Auth::user();
    $roleOptions = [
        'employee'      => ['label' => 'Colaborador', 'desc' => 'Personal operativo (marca con GPS y selfie)'],
        'supervisor'    => ['label' => 'Supervisor', 'desc' => 'Supervisor de sede o turno (asistencia de su equipo)'],
        'accountant'    => ['label' => 'Contabilidad', 'desc' => 'Gestión de boletas, adelantos y planillas'],
        'hr_manager'    => ['label' => 'Recursos Humanos', 'desc' => 'Administración de personal, legajos y turnos'],
        'manager'       => ['label' => 'Gerencia', 'desc' => 'Visualización ejecutiva de indicadores y reportes'],
        'company_admin' => ['label' => 'Administrador de Empresa', 'desc' => 'Acceso y administración total de la empresa'],
    ];

    $posGrouped = ($positionsCatalog ?? collect())->groupBy(fn ($p) => $p->department?->name ?? 'General');
    $departments = $departments ?? collect();
    $branches = $branches ?? collect();
    $shifts = $shifts ?? collect();
    $zones = $zones ?? collect();
@endphp

{{-- Modal de Edición de Colaborador (Executive Modern Design) --}}
<div class="modal fade" id="employeeEditModal" tabindex="-1" aria-labelledby="employeeEditModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content emp-modal-content">
            {{-- Modal Header --}}
            <div class="modal-header emp-modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="emp-modal-icon tone-blue">
                        <i class="bi bi-pencil-square"></i>
                    </div>
                    <div>
                        <h2 class="modal-title fs-5 fw-bold mb-0" id="employeeEditModalLabel" style="color: var(--text-primary); letter-spacing: -0.01em;">Editar Colaborador</h2>
                        <p class="small text-muted mb-0" id="editModalSubtitle" style="font-size: 0.775rem;">Modificar datos personales, rol, asignación de sedes y turno laboral.</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            {{-- Modal Form --}}
            <form id="editEmployeeForm" method="POST" enctype="multipart/form-data" novalidate>
                @csrf
                @method('PUT')
                <input type="hidden" id="edit_employee_id" name="id">

                {{-- Modal Navigation Tabs --}}
                <div class="emp-modal-nav-wrap">
                    <ul class="nav nav-pills emp-modal-tabs" id="empEditModalTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-edit-personal-btn" data-bs-toggle="pill" data-bs-target="#tab-edit-personal" type="button" role="tab" aria-selected="true">
                                <i class="bi bi-person-vcard"></i>
                                <span>1. Datos Personales</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-edit-job-btn" data-bs-toggle="pill" data-bs-target="#tab-edit-job" type="button" role="tab" aria-selected="false">
                                <i class="bi bi-briefcase"></i>
                                <span>2. Puesto y Rol</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-edit-branches-btn" data-bs-toggle="pill" data-bs-target="#tab-edit-branches" type="button" role="tab" aria-selected="false">
                                <i class="bi bi-geo-alt"></i>
                                <span>3. Sedes y Turno</span>
                            </button>
                        </li>
                    </ul>
                </div>

                {{-- Modal Body with Tabs Content --}}
                <div class="modal-body emp-modal-body">
                    {{-- Global Error Summary Alert (Dynamic) --}}
                    <div class="alert alert-danger d-none py-2 px-3 mb-3 small d-flex align-items-center gap-2" id="editModalErrorAlert" role="alert">
                        <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                        <span id="editModalErrorText">Por favor revisa los campos señalados en rojo.</span>
                    </div>

                    <div class="tab-content" id="empEditModalTabContent">

                        {{-- TAB 1: DATOS PERSONALES Y ACCESO --}}
                        <div class="tab-pane fade show active" id="tab-edit-personal" role="tabpanel" aria-labelledby="tab-edit-personal-btn">
                            <div class="emp-section-card">
                                <div class="emp-section-title">
                                    <i class="bi bi-person-bounding-box"></i>
                                    <span>Identidad y Acceso</span>
                                </div>

                                <div class="row g-3">
                                    {{-- Nombres --}}
                                    <div class="col-12 col-md-6">
                                        <label for="edit_first_name" class="emp-form-label">Nombres <span class="text-danger">*</span></label>
                                        <input type="text" id="edit_first_name" name="first_name" class="emp-form-input" required maxlength="100">
                                        <div class="invalid-feedback" id="edit_err_first_name"></div>
                                    </div>

                                    {{-- Apellidos --}}
                                    <div class="col-12 col-md-6">
                                        <label for="edit_last_name" class="emp-form-label">Apellidos <span class="text-danger">*</span></label>
                                        <input type="text" id="edit_last_name" name="last_name" class="emp-form-input" required maxlength="100">
                                        <div class="invalid-feedback" id="edit_err_last_name"></div>
                                    </div>

                                    {{-- Tipo de Documento --}}
                                    <div class="col-12 col-md-4">
                                        <label for="edit_document_type" class="emp-form-label">Tipo Doc. <span class="text-danger">*</span></label>
                                        <select id="edit_document_type" name="document_type" class="emp-form-select" required>
                                            <option value="dni">DNI</option>
                                            <option value="ce">Carné de extranjería</option>
                                            <option value="passport">Pasaporte</option>
                                        </select>
                                        <div class="invalid-feedback" id="edit_err_document_type"></div>
                                    </div>

                                    {{-- Número de Documento --}}
                                    <div class="col-12 col-md-4">
                                        <label for="edit_document_number" class="emp-form-label">N° de Documento <span class="text-danger">*</span></label>
                                        <input type="text" id="edit_document_number" name="document_number" class="emp-form-input" required maxlength="30">
                                        <div class="invalid-feedback" id="edit_err_document_number"></div>
                                    </div>

                                    {{-- Teléfono / WhatsApp --}}
                                    <div class="col-12 col-md-4">
                                        <label for="edit_phone" class="emp-form-label">Teléfono / WhatsApp</label>
                                        <input type="tel" id="edit_phone" name="phone" class="emp-form-input" maxlength="30">
                                        <div class="invalid-feedback" id="edit_err_phone"></div>
                                    </div>

                                    {{-- Fecha de Nacimiento --}}
                                    <div class="col-12 col-md-4">
                                        <label for="edit_birth_date" class="emp-form-label">Fecha de Nacimiento</label>
                                        <input type="date" id="edit_birth_date" name="birth_date" class="emp-form-input" max="{{ now()->subDay()->format('Y-m-d') }}">
                                        <div class="invalid-feedback" id="edit_err_birth_date"></div>
                                    </div>

                                    {{-- Correo de Acceso --}}
                                    <div class="col-12 col-md-5">
                                        <label for="edit_email" class="emp-form-label">
                                            Correo de Acceso (App) <span class="text-danger">*</span>
                                        </label>
                                        <input type="email" id="edit_email" name="email" class="emp-form-input" required maxlength="150">
                                        <div class="invalid-feedback" id="edit_err_email"></div>
                                    </div>

                                    {{-- Estado de la Cuenta --}}
                                    <div class="col-12 col-md-3">
                                        <label for="edit_status" class="emp-form-label">Estado <span class="text-danger">*</span></label>
                                        <select id="edit_status" name="status" class="emp-form-select" required>
                                            <option value="active">Activo</option>
                                            <option value="inactive">Inactivo</option>
                                            <option value="suspended">Suspendido</option>
                                        </select>
                                        <div class="invalid-feedback" id="edit_err_status"></div>
                                    </div>

                                    {{-- Foto de Perfil --}}
                                    <div class="col-12">
                                        <label for="edit_avatar" class="emp-form-label">Actualizar Foto de Perfil</label>
                                        <input type="file" id="edit_avatar" name="avatar" class="emp-form-input" accept="image/*">
                                        <div class="invalid-feedback" id="edit_err_avatar"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- TAB 2: PUESTO, ROL Y ORGANIZACIÓN --}}
                        <div class="tab-pane fade" id="tab-edit-job" role="tabpanel" aria-labelledby="tab-edit-job-btn">
                            <div class="emp-section-card">
                                <div class="emp-section-title">
                                    <i class="bi bi-shield-check"></i>
                                    <span>Rol y Jerarquía de Acceso</span>
                                </div>

                                <div class="row g-3">
                                    {{-- Rol en el sistema --}}
                                    <div class="col-12 col-md-6">
                                        <label for="edit_role" class="emp-form-label">Rol en el Sistema <span class="text-danger">*</span></label>
                                        <select id="edit_role" name="role" class="emp-form-select" required>
                                            @foreach($roleOptions as $roleKey => $roleData)
                                                @if($actor->canAssignRole($roleKey))
                                                    <option value="{{ $roleKey }}">
                                                        {{ $roleData['label'] }} — {{ $roleData['desc'] }}
                                                    </option>
                                                @endif
                                            @endforeach
                                        </select>
                                        <div class="invalid-feedback" id="edit_err_role"></div>
                                    </div>

                                    {{-- Código de Empleado --}}
                                    <div class="col-12 col-md-6">
                                        <label for="edit_employee_code" class="emp-form-label">Código de Empleado</label>
                                        <input type="text" id="edit_employee_code" name="employee_code" class="emp-form-input" maxlength="50">
                                        <div class="invalid-feedback" id="edit_err_employee_code"></div>
                                    </div>

                                    {{-- Cargo del Catálogo --}}
                                    <div class="col-12 col-md-6">
                                        <label for="edit_position_id" class="emp-form-label">Cargo (Puesto de Trabajo)</label>
                                        <select id="edit_position_id" name="position_id" class="emp-form-select">
                                            <option value="">Sin cargo asignado</option>
                                            @foreach($posGrouped as $groupName => $pItems)
                                                <optgroup label="{{ $groupName }}">
                                                    @foreach($pItems as $p)
                                                        <option value="{{ $p->id }}" data-department="{{ $p->department_id }}" data-roaming="{{ $p->is_roaming ? '1' : '0' }}">
                                                            {{ $p->name }}{{ $p->is_roaming ? ' (Itinerante)' : '' }}
                                                        </option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        </select>
                                        <div class="invalid-feedback" id="edit_err_position_id"></div>
                                    </div>

                                    {{-- Área / Departamento --}}
                                    <div class="col-12 col-md-6">
                                        <label for="edit_department_id" class="emp-form-label">Área Organizacional</label>
                                        <select id="edit_department_id" name="department_id" class="emp-form-select">
                                            <option value="">Sin área específica</option>
                                            @foreach($departments as $d)
                                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="invalid-feedback" id="edit_err_department_id"></div>
                                    </div>

                                    {{-- Marcación Itinerante (Switch) --}}
                                    <div class="col-12">
                                        <div class="emp-switch-box">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch" id="edit_roaming_attendance_enabled" name="roaming_attendance_enabled" value="1">
                                                <label class="form-check-label fw-bold" for="edit_roaming_attendance_enabled" style="color: var(--text-primary); font-size: 0.85rem;">
                                                    Marcación itinerante (en ruta o cualquier sede)
                                                </label>
                                            </div>
                                            <div class="small text-muted mt-1" style="font-size: 0.775rem;">
                                                Permite marcar asistencia sin exigir el radio geocerca de una sede fija.
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Alcance Territorial (Para supervisores o mandos) --}}
                                    <div class="col-12" id="editScopeContainer">
                                        @if($actor->isOrgScoped())
                                            <div class="emp-info-banner">
                                                <i class="bi bi-info-circle"></i>
                                                <div>
                                                    <strong>Alcance Territorial:</strong>
                                                    <span>Hereda el alcance: <strong>{{ $actor->scopeLabel() }}</strong>.</span>
                                                </div>
                                            </div>
                                        @else
                                            <div class="p-3 border rounded-3" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                                                <label for="edit_scope_type" class="emp-form-label">Alcance territorial en el panel</label>
                                                <select id="edit_scope_type" name="scope_type" class="emp-form-select mb-2">
                                                    @foreach(\App\Modules\Core\Models\User::SCOPES as $sKey => $sLabel)
                                                        <option value="{{ $sKey }}">{{ $sLabel }}</option>
                                                    @endforeach
                                                </select>

                                                <div class="row g-2 mt-1">
                                                    <div class="col-md-6 d-none" id="editScopeZoneWrap">
                                                        <label for="edit_scope_zone_id" class="emp-form-label">Zona asignada</label>
                                                        <select id="edit_scope_zone_id" name="scope_zone_id" class="emp-form-select">
                                                            <option value="">Selecciona zona…</option>
                                                            @foreach($zones as $z)
                                                                <option value="{{ $z->id }}">{{ $z->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        <div class="invalid-feedback" id="edit_err_scope_zone_id"></div>
                                                    </div>
                                                    <div class="col-md-6 d-none" id="editScopeBranchWrap">
                                                        <label for="edit_scope_branch_id" class="emp-form-label">Sede asignada</label>
                                                        <select id="edit_scope_branch_id" name="scope_branch_id" class="emp-form-select">
                                                            <option value="">Selecciona sede…</option>
                                                            @foreach($branches as $b)
                                                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        <div class="invalid-feedback" id="edit_err_scope_branch_id"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Fecha de Ingreso y Sueldo Base --}}
                                    <div class="col-12 col-md-6">
                                        <label for="edit_hire_date" class="emp-form-label">Fecha de Ingreso</label>
                                        <input type="date" id="edit_hire_date" name="hire_date" class="emp-form-input">
                                        <div class="invalid-feedback" id="edit_err_hire_date"></div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label for="edit_base_salary" class="emp-form-label">Sueldo Base Mensual (S/.)</label>
                                        <input type="number" step="0.01" min="0" id="edit_base_salary" name="base_salary" class="emp-form-input" placeholder="0.00">
                                        <div class="invalid-feedback" id="edit_err_base_salary"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- TAB 3: SEDES Y TURNO LABORAL --}}
                        <div class="tab-pane fade" id="tab-edit-branches" role="tabpanel" aria-labelledby="tab-edit-branches-btn">
                            <div class="row g-3">
                                {{-- Sucursales Autorizadas --}}
                                <div class="col-12">
                                    <div class="emp-section-card">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <div class="emp-section-title mb-0">
                                                <i class="bi bi-geo-alt-fill"></i>
                                                <span>Sedes Autorizadas para Marcar <span class="text-danger">*</span></span>
                                            </div>
                                            <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.7rem;">Elige una como Principal</span>
                                        </div>

                                        <div class="emp-branches-container" id="editBranchesList">
                                            @foreach($branches as $b)
                                                <div class="emp-branch-option-row">
                                                    <div class="form-check d-flex align-items-center gap-2 mb-0">
                                                        <input class="form-check-input edit-branch-check" type="checkbox" name="branch_ids[]" value="{{ $b->id }}" id="edit_branch_{{ $b->id }}">
                                                        <label class="form-check-label fw-bold" for="edit_branch_{{ $b->id }}" style="font-size: 0.825rem; color: var(--text-primary);">
                                                            {{ $b->name }}
                                                        </label>
                                                    </div>
                                                    <div class="form-check form-check-inline mb-0">
                                                        <input class="form-check-input edit-primary-branch-radio" type="radio" name="primary_branch_id" value="{{ $b->id }}" id="edit_primary_{{ $b->id }}">
                                                        <label class="form-check-label small" for="edit_primary_{{ $b->id }}" style="font-size: 0.75rem; color: var(--accent-cyan); cursor: pointer;">
                                                            <i class="bi bi-star-fill star-icon me-1"></i>Sede Principal
                                                        </label>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="invalid-feedback d-block" id="edit_err_branch_ids"></div>
                                        <div class="invalid-feedback d-block" id="edit_err_primary_branch_id"></div>
                                    </div>
                                </div>

                                {{-- Turno Inicial --}}
                                <div class="col-12">
                                    <div class="emp-section-card">
                                        <div class="emp-section-title">
                                            <i class="bi bi-clock-history"></i>
                                            <span>Turno Actual Vigente</span>
                                        </div>
                                        <select id="edit_shift_id" name="shift_id" class="emp-form-select">
                                            <option value="">Sin turno asignado</option>
                                            @foreach($shifts as $s)
                                                <option value="{{ $s->id }}">
                                                    {{ $s->name }} ({{ hora($s->start_time) }} – {{ hora($s->end_time) }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <div class="invalid-feedback" id="edit_err_shift_id"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                {{-- Modal Footer --}}
                <div class="modal-footer emp-modal-footer">
                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="font-size: 0.85rem; font-weight: 600; border-radius: 8px; min-height: 38px;">
                        Cancelar
                    </button>
                    <button type="submit" id="btnSubmitEditEmployee" class="btn btn-primary emp-btn-primary px-4 shadow-sm" style="font-size: 0.875rem; font-weight: 600; border-radius: 8px; min-height: 38px; gap: 8px;">
                        <span class="spinner-border spinner-border-sm d-none" id="btnEditSpinner" role="status" aria-hidden="true"></span>
                        <i class="bi bi-check2 fs-6" id="btnEditIcon"></i>
                        <span id="btnEditText">Guardar Cambios</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
