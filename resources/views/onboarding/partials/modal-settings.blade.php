<div class="modal-header">
    <div class="d-flex align-items-center gap-3 min-w-0">
        <div class="onb-stage-badge-icon badge-icon-personal" style="width: 44px; height: 44px; font-size: 1.35rem; border-radius: 10px;">
            <i class="bi bi-sliders"></i>
        </div>
        <div class="min-w-0">
            <h2 class="modal-title fs-5 fw-bold text-body mb-0" id="onbSettingsModalTitle">Etapas de incorporación</h2>
            <div class="d-flex align-items-center gap-2 mt-1">
                <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 d-inline-flex align-items-center" style="font-size: 0.75rem; font-weight: 600;">
                    <i class="bi bi-building" style="margin-right: 6px;"></i>
                    <span>{{ $currentTenant?->company_name ?? 'Configuración de la empresa' }}</span>
                </span>
            </div>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar" style="font-size: 0.75rem;"></button>
</div>

<form method="POST" action="{{ route('onboarding.settings.update') }}" id="onbSettingsForm">
    @csrf
    @method('PUT')
    <input type="hidden" name="tenant_id" value="{{ $tenantId }}">

    <div class="modal-body">
        @if(count($tenants) > 0)
            <div class="mb-4 p-3 rounded-3 border" style="background: var(--bg-surface); border-color: var(--border-color) !important; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                <label for="modalTenantSelect" class="form-label small fw-bold text-secondary mb-1.5 d-flex align-items-center gap-2">
                    <i class="bi bi-buildings text-primary fs-6"></i>
                    <span>Empresa a configurar:</span>
                </label>
                <select id="modalTenantSelect" class="form-select" style="height: 38px; font-size: 0.8125rem; border-radius: 8px;" onchange="loadOnboardingSettingsModal(this.value)">
                    @foreach($tenants as $t)
                        <option value="{{ $t->id }}" @selected($tenantId === $t->id)>{{ $t->company_name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="alert alert-info d-flex align-items-start gap-2.5 mb-4 p-3" style="border-radius: 10px; background: rgba(var(--accent-cyan-rgb), 0.06); border: 1px solid rgba(var(--accent-cyan-rgb), 0.2); color: var(--text-primary);">
            <i class="bi bi-info-circle-fill fs-5 text-primary flex-shrink-0 mt-0.5"></i>
            <div class="small" style="font-size: 0.8125rem; line-height: 1.45;">
                Selecciona qué requisitos debe completar obligatoriamente cada nuevo ingreso antes de que RRHH apruebe su expediente y quede habilitado para marcar asistencia.
            </div>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-3 px-1">
            <span class="small fw-bold text-uppercase tracking-wider text-secondary d-inline-flex align-items-center" style="font-size: 0.775rem; letter-spacing: 0.05em;">
                <i class="bi bi-check2-square text-primary" style="margin-right: 8px;"></i>
                <span>Requisitos configurables</span>
            </span>
            <span class="small text-secondary" style="font-size: 0.75rem;">
                Activa o desactiva con el interruptor
            </span>
        </div>

        <div class="onb-stages-config-list">
            @php
                $stageMeta = [
                    'personal_data' => ['icon' => 'bi-person-vcard', 'class' => 'badge-icon-personal'],
                    'uniforms'      => ['icon' => 'bi-person-standing', 'class' => 'badge-icon-uniforms'],
                    'documents'     => ['icon' => 'bi-file-earmark-lock2', 'class' => 'badge-icon-documents'],
                    'induction'     => ['icon' => 'bi-mortarboard', 'class' => 'badge-icon-induction'],
                ];
            @endphp

            @foreach($stages as $stage)
                @php
                    $meta = $stageMeta[$stage->key] ?? ['icon' => 'bi-check2-circle', 'class' => 'badge-icon-personal'];
                @endphp
                <div class="onb-settings-card">
                    <div class="onb-settings-card-header">
                        <div class="d-flex align-items-center gap-3 min-w-0">
                            <div class="onb-stage-badge-icon {{ $meta['class'] }}">
                                <i class="bi {{ $meta['icon'] }}"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="fw-bold text-body" style="font-size: 0.925rem;">
                                    {{ $stage->title() }}
                                </div>
                                <span class="badge rounded-pill bg-light text-secondary border mt-0.5" style="font-size: 0.675rem;">
                                    Etapa de incorporación
                                </span>
                            </div>
                        </div>

                        <div class="onb-switch-lg form-check form-switch">
                            <input class="form-check-input" 
                                   type="checkbox" 
                                   role="switch" 
                                   id="modal_req_{{ $stage->key }}" 
                                   name="required[]" 
                                   value="{{ $stage->key }}" 
                                   @checked(old('required') ? in_array($stage->key, old('required', [])) : $stage->is_required)>
                        </div>
                    </div>

                    <p class="onb-settings-desc">
                        {{ $stage->description() }}
                    </p>

                    @if($stage->key === 'documents')
                        <div class="onb-settings-extra">
                            <div class="onb-docs-panel">
                                <div class="d-flex align-items-center gap-2 mb-1.5 text-body fw-semibold">
                                    <i class="bi bi-file-earmark-check text-primary fs-6"></i>
                                    <span>Documentos exigidos al colaborador:</span>
                                </div>
                                <div class="text-secondary ps-4" style="font-size: 0.785rem; line-height: 1.4;">
                                    {{ implode(', ', $documents) ?: 'Ningún documento configurado actualmente.' }}
                                </div>
                                @can('company.configure')
                                    <div class="mt-3 ps-4">
                                        <a href="{{ route('settings.mobile') }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-2 py-1.5 px-3" style="border-radius: 6px; font-size: 0.775rem; font-weight: 600;">
                                            <i class="bi bi-gear-fill"></i>
                                            <span>Modificar en Configuración de la app</span>
                                        </a>
                                    </div>
                                @endcan
                            </div>
                        </div>
                    @endif

                    @if($stage->key === 'induction')
                        <div class="onb-settings-extra">
                            <div class="onb-induction-panel">
                                <label for="modal_course_id" class="form-label small fw-bold text-secondary mb-2 d-flex align-items-center gap-2">
                                    <i class="bi bi-collection-play text-primary fs-6"></i>
                                    <span>Curso de inducción asignado:</span>
                                </label>
                                <select id="modal_course_id" name="course_id" class="form-select form-select-sm @error('course_id') is-invalid @enderror" style="height: 38px; font-size: 0.8125rem; border-radius: 8px;">
                                    <option value="">Automático: primer curso publicado de categoría Inducción</option>
                                    @foreach($courses as $c)
                                        <option value="{{ $c->id }}" @selected(old('course_id', $stage->course_id) == $c->id)>{{ $c->title }}</option>
                                    @endforeach
                                </select>
                                @error('course_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                @if($courses->isEmpty())
                                    <div class="d-flex align-items-center gap-2 text-warning-emphasis small mt-2.5 p-2 rounded-2 bg-warning-subtle" style="font-size: 0.775rem;">
                                        <i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="margin-right: 8px;"></i>
                                        <span>No hay cursos publicados para esta empresa: la etapa no aplicará hasta que publiques uno.</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary px-4 py-2" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 600; font-size: 0.825rem;">
            Cancelar
        </button>
        <button type="submit" class="btn btn-primary px-4 py-2 d-inline-flex align-items-center gap-2 shadow-sm" style="border-radius: 8px; font-weight: 600; font-size: 0.825rem; white-space: nowrap;">
            <i class="bi bi-check2-circle fs-6"></i>
            <span>Guardar etapas</span>
        </button>
    </div>
</form>
