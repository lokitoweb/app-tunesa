@php
    $actor = Auth::user();
    $roleOptions = [
        'employee'      => ['label' => 'Colaborador', 'desc' => 'Personal operativo (marca con GPS y selfie)'],
        'supervisor'    => ['label' => 'Supervisor', 'desc' => 'Supervisor de sede o turno (asistencia de su equipo)'],
        'accountant'    => ['label' => 'Contabilidad', 'desc' => 'Gestión de boletas, adelantos y planillas'],
        'logistics'     => ['label' => 'Logística', 'desc' => 'Proveedores, requerimientos, cotizaciones y órdenes'],
        'hr_manager'    => ['label' => 'Recursos Humanos', 'desc' => 'Administración de personal, legajos y turnos'],
        'manager'       => ['label' => 'Gerencia', 'desc' => 'Visualización ejecutiva de indicadores y reportes'],
        'company_admin' => ['label' => 'Administrador de Empresa', 'desc' => 'Acceso y administración total de la empresa'],
    ];

    $posGrouped = ($positionsCatalog ?? collect())->groupBy(fn ($p) => $p->department?->name ?? 'General');
@endphp

{{-- Modal de Alta de Colaborador (Executive Modern Design) --}}
<div class="modal fade" id="employeeModal" tabindex="-1" aria-labelledby="employeeModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content emp-modal-content">
            {{-- Modal Header --}}
            <div class="modal-header emp-modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="emp-modal-icon">
                        <i class="bi bi-person-plus-fill"></i>
                    </div>
                    <div>
                        <h2 class="modal-title fs-5 fw-bold mb-0" id="employeeModalLabel" style="color: var(--text-primary); letter-spacing: -0.01em;">Nuevo Colaborador</h2>
                        <p class="small text-muted mb-0" style="font-size: 0.775rem;">Registro de personal, credenciales de acceso, rol y sedes de marcación.</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            {{-- Modal Form --}}
            <form id="createEmployeeForm" action="{{ route('employees.store') }}" method="POST" enctype="multipart/form-data" novalidate>
                @csrf

                {{-- Modal Navigation Tabs --}}
                <div class="emp-modal-nav-wrap">
                    <ul class="nav nav-pills emp-modal-tabs" id="empModalTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-emp-personal-btn" data-bs-toggle="pill" data-bs-target="#tab-emp-personal" type="button" role="tab" aria-selected="true">
                                <i class="bi bi-person-vcard"></i>
                                <span>1. Datos Personales</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-emp-job-btn" data-bs-toggle="pill" data-bs-target="#tab-emp-job" type="button" role="tab" aria-selected="false">
                                <i class="bi bi-briefcase"></i>
                                <span>2. Puesto y Rol</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-emp-branches-btn" data-bs-toggle="pill" data-bs-target="#tab-emp-branches" type="button" role="tab" aria-selected="false">
                                <i class="bi bi-geo-alt"></i>
                                <span>3. Sedes y Turno</span>
                            </button>
                        </li>
                    </ul>
                </div>

                {{-- Modal Body with Tabs Content --}}
                <div class="modal-body emp-modal-body">
                    {{-- Global Error Summary Alert (Dynamic) --}}
                    <div class="alert alert-danger d-none py-2 px-3 mb-3 small d-flex align-items-center gap-2" id="modalFormErrorAlert" role="alert">
                        <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                        <span id="modalFormErrorText">Por favor revisa los campos señalados en rojo.</span>
                    </div>

                    <div class="tab-content" id="empModalTabContent">

                        {{-- TAB 1: DATOS PERSONALES Y ACCESO --}}
                        <div class="tab-pane fade show active" id="tab-emp-personal" role="tabpanel" aria-labelledby="tab-emp-personal-btn">
                            {{-- Selector de empresa si está en vista global --}}
                            @if(isset($companies) && $companies)
                                <div class="emp-section-card mb-3">
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <label for="modal_tenant_id" class="emp-form-label">
                                                Empresa a la que pertenece <span class="text-danger">*</span>
                                            </label>
                                            <select id="modal_tenant_id" name="tenant_id" class="emp-form-select" required>
                                                <option value="">Selecciona una empresa…</option>
                                                @foreach($companies as $c)
                                                    <option value="{{ $c->id }}" @selected(request('company_id') == $c->id)>
                                                        {{ $c->company_name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="invalid-feedback" id="err_tenant_id"></div>
                                            <div class="emp-form-hint">Las sucursales, turnos y cargos se cargarán según la empresa elegida.</div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="emp-section-card">
                                <div class="emp-section-title">
                                    <i class="bi bi-person-bounding-box"></i>
                                    <span>Identidad y Documentación</span>
                                </div>

                                <div class="row g-3">
                                    {{-- Nombres --}}
                                    <div class="col-12 col-md-6">
                                        <label for="modal_first_name" class="emp-form-label">Nombres <span class="text-danger">*</span></label>
                                        <input type="text" id="modal_first_name" name="first_name" class="emp-form-input" placeholder="Ej. Juan Carlos" required maxlength="100">
                                        <div class="invalid-feedback" id="err_first_name"></div>
                                    </div>

                                    {{-- Apellidos --}}
                                    <div class="col-12 col-md-6">
                                        <label for="modal_last_name" class="emp-form-label">Apellidos <span class="text-danger">*</span></label>
                                        <input type="text" id="modal_last_name" name="last_name" class="emp-form-input" placeholder="Ej. Pérez Quispe" required maxlength="100">
                                        <div class="invalid-feedback" id="err_last_name"></div>
                                    </div>

                                    {{-- Tipo de Documento --}}
                                    <div class="col-12 col-md-4">
                                        <label for="modal_document_type" class="emp-form-label">Tipo Doc. <span class="text-danger">*</span></label>
                                        <select id="modal_document_type" name="document_type" class="emp-form-select" required>
                                            <option value="dni" selected>DNI</option>
                                            <option value="ce">Carné de extranjería</option>
                                            <option value="passport">Pasaporte</option>
                                        </select>
                                        <div class="invalid-feedback" id="err_document_type"></div>
                                    </div>

                                    {{-- Número de Documento --}}
                                    <div class="col-12 col-md-4">
                                        <label for="modal_document_number" class="emp-form-label">N° de Documento <span class="text-danger">*</span></label>
                                        <input type="text" id="modal_document_number" name="document_number" class="emp-form-input" placeholder="Ej. 70889912" required maxlength="30">
                                        <div class="invalid-feedback" id="err_document_number"></div>
                                    </div>

                                    {{-- Teléfono / WhatsApp --}}
                                    <div class="col-12 col-md-4">
                                        <label for="modal_phone" class="emp-form-label">Teléfono / WhatsApp</label>
                                        <input type="tel" id="modal_phone" name="phone" class="emp-form-input" placeholder="Ej. 987654321" maxlength="30">
                                        <div class="invalid-feedback" id="err_phone"></div>
                                    </div>

                                    {{-- Fecha de Nacimiento --}}
                                    <div class="col-12 col-md-4">
                                        <label for="modal_birth_date" class="emp-form-label">Fecha de Nacimiento</label>
                                        <input type="date" id="modal_birth_date" name="birth_date" class="emp-form-input" max="{{ now()->subDay()->format('Y-m-d') }}">
                                        <div class="invalid-feedback" id="err_birth_date"></div>
                                    </div>

                                    {{-- Correo de Acceso --}}
                                    <div class="col-12 col-md-8">
                                        <label for="modal_email" class="emp-form-label">
                                            Correo de Acceso (Usuario App)
                                        </label>
                                        <input type="email" id="modal_email" name="email" class="emp-form-input"
                                               placeholder="Dejar vacío para generar DNI@{{ $loginDomain ?? 'empresa.com' }}" maxlength="150">
                                        <div class="invalid-feedback" id="err_email"></div>
                                        <div class="emp-form-hint" id="modalEmailHint">
                                            Si se deja vacío, el sistema genera automáticamente: <code>DNI@{{ $loginDomain ?? 'empresa.com' }}</code>
                                        </div>
                                    </div>

                                    {{-- Foto de Perfil --}}
                                    <div class="col-12">
                                        <label for="modal_avatar" class="emp-form-label">Foto de Perfil (Opcional)</label>
                                        <input type="file" id="modal_avatar" name="avatar" class="emp-form-input" accept="image/*">
                                        <div class="invalid-feedback" id="err_avatar"></div>
                                    </div>
                                </div>
                            </div>

                            {{-- Nota de Seguridad Credenciales --}}
                            <div class="emp-info-banner mt-3">
                                <i class="bi bi-shield-lock-fill"></i>
                                <div>
                                    <strong class="d-block text-emphasis">Generación Segura de Credenciales:</strong>
                                    <span>Al guardar, el sistema generará una contraseña temporal de 72 horas para el primer acceso a la app móvil. Podrás copiarla o enviarla por WhatsApp al colaborador.</span>
                                </div>
                            </div>
                        </div>

                        {{-- TAB 2: PUESTO, ROL Y ORGANIZACIÓN --}}
                        <div class="tab-pane fade" id="tab-emp-job" role="tabpanel" aria-labelledby="tab-emp-job-btn">
                            <div class="emp-section-card">
                                <div class="emp-section-title">
                                    <i class="bi bi-shield-check"></i>
                                    <span>Rol y Jerarquía de Acceso</span>
                                </div>

                                <div class="row g-3">
                                    {{-- Rol en el sistema --}}
                                    <div class="col-12 col-md-6">
                                        <label for="modal_role" class="emp-form-label">Rol en el Sistema <span class="text-danger">*</span></label>
                                        <select id="modal_role" name="role" class="emp-form-select" required>
                                            @foreach($roleOptions as $roleKey => $roleData)
                                                @if($actor->canAssignRole($roleKey))
                                                    <option value="{{ $roleKey }}" @selected($roleKey === 'employee')>
                                                        {{ $roleData['label'] }} — {{ $roleData['desc'] }}
                                                    </option>
                                                @endif
                                            @endforeach
                                        </select>
                                        <div class="invalid-feedback" id="err_role"></div>
                                        <div class="emp-form-hint">Solo se listan roles permitidos según tu rango administrativo.</div>
                                    </div>

                                    {{-- Código de Empleado --}}
                                    <div class="col-12 col-md-6">
                                        <label for="modal_employee_code" class="emp-form-label">Código de Empleado</label>
                                        <input type="text" id="modal_employee_code" name="employee_code" class="emp-form-input" placeholder="Auto-generado si se deja vacío (Ej: EMP-001)" maxlength="50">
                                        <div class="invalid-feedback" id="err_employee_code"></div>
                                    </div>

                                    {{-- Cargo del Catálogo --}}
                                    <div class="col-12 col-md-6">
                                        <label for="modal_position_id" class="emp-form-label">Cargo (Puesto de Trabajo)</label>
                                        <select id="modal_position_id" name="position_id" class="emp-form-select">
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
                                        <div class="invalid-feedback" id="err_position_id"></div>
                                        @include('employees._new-position', ['select' => 'modal_position_id', 'areaSelect' => 'modal_department_id'])
                                    </div>

                                    {{-- Área / Departamento --}}
                                    <div class="col-12 col-md-6">
                                        <label for="modal_department_id" class="emp-form-label">Área Organizacional</label>
                                        <select id="modal_department_id" name="department_id" class="emp-form-select">
                                            <option value="">Sin área específica</option>
                                            @foreach($departments as $d)
                                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="invalid-feedback" id="err_department_id"></div>
                                    </div>

                                    {{-- Marcación Itinerante (Switch) --}}
                                    <div class="col-12">
                                        <div class="emp-switch-box">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch" id="modal_roaming_attendance_enabled" name="roaming_attendance_enabled" value="1">
                                                <label class="form-check-label fw-bold" for="modal_roaming_attendance_enabled" style="color: var(--text-primary); font-size: 0.85rem;">
                                                    Marcación itinerante (en ruta o cualquier sede)
                                                </label>
                                            </div>
                                            <div class="small text-muted mt-1" style="font-size: 0.775rem;">
                                                Apto para choferes, estibadores móviles o técnicos en campo: permite marcar asistencia sin exigir el radio geocerca de una sede fija.
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Alcance Territorial (Para supervisores o mandos) --}}
                                    <div class="col-12" id="scopeContainer">
                                        @if($actor->isOrgScoped())
                                            <div class="emp-info-banner">
                                                <i class="bi bi-info-circle"></i>
                                                <div>
                                                    <strong>Alcance Territorial Limitado:</strong>
                                                    <span>Tu alcance es <strong>{{ $actor->scopeLabel() }}</strong>. Los mandos que registres heredarán automáticamente esta asignación.</span>
                                                </div>
                                            </div>
                                        @else
                                            <div class="p-3 border rounded-3" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                                                <label for="modal_scope_type" class="emp-form-label">Alcance territorial en el panel</label>
                                                <select id="modal_scope_type" name="scope_type" class="emp-form-select mb-2">
                                                    @foreach(\App\Modules\Core\Models\User::SCOPES as $sKey => $sLabel)
                                                        <option value="{{ $sKey }}">{{ $sLabel }}</option>
                                                    @endforeach
                                                </select>

                                                <div class="row g-2 mt-1">
                                                    <div class="col-md-6 d-none" id="scopeZoneWrap">
                                                        <label for="modal_scope_zone_id" class="emp-form-label">Zona asignada</label>
                                                        <select id="modal_scope_zone_id" name="scope_zone_id" class="emp-form-select">
                                                            <option value="">Selecciona zona…</option>
                                                            @foreach($zones as $z)
                                                                <option value="{{ $z->id }}">{{ $z->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        <div class="invalid-feedback" id="err_scope_zone_id"></div>
                                                    </div>
                                                    <div class="col-md-6 d-none" id="scopeBranchWrap">
                                                        <label for="modal_scope_branch_id" class="emp-form-label">Sede asignada</label>
                                                        <select id="modal_scope_branch_id" name="scope_branch_id" class="emp-form-select">
                                                            <option value="">Selecciona sede…</option>
                                                            @foreach($branches as $b)
                                                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                                                            @endforeach
                                                        </select>
                                                        <div class="invalid-feedback" id="err_scope_branch_id"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Fecha de Ingreso y Sueldo Base --}}
                                    <div class="col-12 col-md-6">
                                        <label for="modal_hire_date" class="emp-form-label">Fecha de Ingreso Laboral</label>
                                        <input type="date" id="modal_hire_date" name="hire_date" value="{{ date('Y-m-d') }}" class="emp-form-input">
                                        <div class="invalid-feedback" id="err_hire_date"></div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label for="modal_base_salary" class="emp-form-label">Sueldo Base Mensual (S/.)</label>
                                        <input type="number" step="0.01" min="0" id="modal_base_salary" name="base_salary" class="emp-form-input" placeholder="0.00">
                                        <div class="invalid-feedback" id="err_base_salary"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- TAB 3: SEDES Y TURNO LABORAL --}}
                        <div class="tab-pane fade" id="tab-emp-branches" role="tabpanel" aria-labelledby="tab-emp-branches-btn">
                            <div class="row g-3">
                                {{-- Sucursales Autorizadas --}}
                                <div class="col-12">
                                    <div class="emp-section-card">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <div class="emp-section-title mb-0">
                                                <i class="bi bi-geo-alt-fill"></i>
                                                <span>Sedes Autorizadas para Marcar Asistencia <span class="text-danger">*</span></span>
                                            </div>
                                            <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.7rem;">Elige una como Principal</span>
                                        </div>
                                        <div class="small text-muted mb-3" style="font-size: 0.775rem;">
                                            Selecciona los lugares donde el colaborador puede fichar con geocerca GPS y define la sede principal.
                                        </div>

                                        <div class="emp-branches-container" id="modalBranchesList">
                                            @forelse($branches as $b)
                                                <div class="emp-branch-option-row">
                                                    <div class="form-check d-flex align-items-center gap-2 mb-0">
                                                        <input class="form-check-input branch-check" type="checkbox" name="branch_ids[]" value="{{ $b->id }}" id="m_branch_{{ $b->id }}" @checked($loop->first)>
                                                        <label class="form-check-label fw-bold" for="m_branch_{{ $b->id }}" style="font-size: 0.825rem; color: var(--text-primary);">
                                                            {{ $b->name }}
                                                        </label>
                                                    </div>
                                                    <div class="form-check form-check-inline mb-0">
                                                        <input class="form-check-input primary-branch-radio" type="radio" name="primary_branch_id" value="{{ $b->id }}" id="m_primary_{{ $b->id }}" @checked($loop->first)>
                                                        <label class="form-check-label small" for="m_primary_{{ $b->id }}" style="font-size: 0.75rem; color: var(--accent-cyan); cursor: pointer;">
                                                            <i class="bi bi-star-fill star-icon me-1"></i>Sede Principal
                                                        </label>
                                                    </div>
                                                </div>
                                            @empty
                                                <div class="p-3 text-center text-muted small" id="noBranchesNotice">
                                                    No hay sucursales activas registradas para esta empresa.
                                                </div>
                                            @endforelse
                                        </div>
                                        <div class="invalid-feedback d-block" id="err_branch_ids"></div>
                                        <div class="invalid-feedback d-block" id="err_primary_branch_id"></div>
                                    </div>
                                </div>

                                {{-- Turno Inicial --}}
                                <div class="col-12">
                                    <div class="emp-section-card">
                                        <div class="emp-section-title">
                                            <i class="bi bi-clock-history"></i>
                                            <span>Turno Laboral Asignado</span>
                                        </div>
                                        <div class="small text-muted mb-2" style="font-size: 0.775rem;">
                                            Horario de entrada y salida usado para calcular tardanzas, faltas y horas extra.
                                        </div>

                                        <select id="modal_shift_id" name="shift_id" class="emp-form-select">
                                            <option value="">Sin turno asignado (se asignará después)</option>
                                            @foreach($shifts as $s)
                                                <option value="{{ $s->id }}">
                                                    {{ $s->name }} ({{ hora($s->start_time) }} – {{ hora($s->end_time) }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <div class="invalid-feedback" id="err_shift_id"></div>
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
                    <button type="submit" id="btnSubmitEmployee" class="btn btn-primary emp-btn-primary px-4 shadow-sm" style="font-size: 0.875rem; font-weight: 600; border-radius: 8px; min-height: 38px; gap: 8px;">
                        <span class="spinner-border spinner-border-sm d-none" id="btnSubmitSpinner" role="status" aria-hidden="true"></span>
                        <i class="bi bi-person-check-fill fs-6" id="btnSubmitIcon"></i>
                        <span id="btnSubmitText">Crear Colaborador</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal de Credenciales Generadas (1-Click Copy & WhatsApp Share) --}}
<div class="modal fade" id="credentialsSuccessModal" tabindex="-1" aria-labelledby="credSuccessLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content emp-modal-content">
            <div class="modal-header border-0 pb-0">
                <div class="emp-modal-icon tone-emerald">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center pt-2 pb-4">
                <h3 class="fs-5 fw-bold mb-1" id="credSuccessLabel" style="color: var(--text-primary);">¡Colaborador Registrado!</h3>
                <p class="small text-muted mb-3" style="font-size: 0.8rem;">Entrega estas credenciales al colaborador para que inicie sesión en la aplicación móvil.</p>

                <div class="emp-cred-card text-start">
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom" style="border-color: var(--border-color) !important;">
                        <span class="small text-muted" style="font-size: 0.75rem;">Colaborador</span>
                        <strong class="text-emphasis" id="credModalName" style="font-size: 0.85rem;">—</strong>
                    </div>
                    <div class="mb-2">
                        <label class="small text-muted d-block mb-1" style="font-size: 0.725rem;">Correo de Acceso:</label>
                        <div class="emp-cred-field font-monospace" id="credModalEmail">—</div>
                    </div>
                    <div>
                        <label class="small text-muted d-block mb-1" style="font-size: 0.725rem;">Contraseña Temporal (72 horas):</label>
                        <div class="emp-cred-field font-monospace text-warning fw-bold" id="credModalPass">—</div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
                    <button type="button" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2" id="btnCopyCredsModal" style="border-radius: 8px; font-size: 0.825rem;">
                        <i class="bi bi-clipboard"></i>
                        <span id="btnCopyCredsText">Copiar Credenciales</span>
                    </button>
                    <a href="#" target="_blank" class="btn btn-success d-inline-flex align-items-center gap-2" id="btnWhatsappShare" style="border-radius: 8px; font-size: 0.825rem;">
                        <i class="bi bi-whatsapp"></i>
                        <span>Enviar por WhatsApp</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
