{{-- Modal de Detalle de Denuncia Ética — Diseño Ejecutivo Estándar App-Tunesa con Tabs --}}
<div class="modal-header emp-modal-header">
    <div class="d-flex align-items-center gap-3 min-w-0">
        <div class="emp-modal-icon flex-shrink-0">
            <i class="bi bi-shield-lock-fill"></i>
        </div>
        <div class="min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap mb-0.5">
                <span class="ethics-code-badge" style="font-size: 0.8rem; font-weight: 700; padding: 2px 8px;">
                    #{{ $case->public_code }}
                </span>
                <h2 class="modal-title fs-5 fw-bold text-body text-truncate mb-0" id="ethicsModalTitle" title="{{ $case->subject }}">
                    {{ $case->subject }}
                </h2>
                <x-status-badge type="ethics_status" :value="$case->status" />
                <span class="ethics-origin-pill {{ $case->isFromApp() ? 'origin-app' : 'origin-web' }}" style="font-size: 0.75rem; padding: 2px 8px;">
                    <i class="bi bi-{{ $case->isFromApp() ? 'person-lock' : 'incognito' }}" style="margin-right: 5px;"></i>
                    <span>{{ $case->isFromApp() ? 'App Móvil' : 'Web Anónima' }}</span>
                </span>
            </div>
            <div class="small text-muted d-flex align-items-center gap-2 flex-wrap" style="font-size: 0.785rem;">
                <span><i class="bi bi-calendar3 me-1"></i>Recibida el {{ $case->reported_on->format('d/m/Y H:i') }}</span>
                @if(($globalView ?? false) || !isset($currentTenant) || (isset($currentTenant) && $currentTenant->id !== $case->tenant_id))
                    <span>· <i class="bi bi-buildings me-1"></i>{{ $case->tenant?->company_name ?? 'Empresa' }}</span>
                @endif
                <span>· <i class="bi bi-{{ $case->categoryIcon() }} me-1"></i>{{ $case->categoryLabel() }}</span>
                @if($case->closed_at)
                    <span class="text-success fw-medium">· <i class="bi bi-check-circle me-1"></i>Cerrada el {{ $case->closed_at->format('d/m/Y H:i') }}</span>
                @endif
            </div>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar" style="font-size: 0.8rem;"></button>
</div>

{{-- Barra de Navegación por Pestañas (Design System App-Tunesa) --}}
<div class="emp-modal-nav-wrap">
    <ul class="nav nav-pills emp-modal-tabs" id="ethicsModalTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-ethics-hechos-btn" data-bs-toggle="pill" data-bs-target="#tab-ethics-hechos" type="button" role="tab" aria-selected="true">
                <i class="bi bi-file-earmark-text" style="margin-right: 6px;"></i>
                <span>1. Hechos y Evidencia</span>
                @if($case->attachments->whereNull('message_id')->isNotEmpty())
                    <span class="badge rounded-pill bg-light text-secondary border ms-1" style="font-size: 0.675rem;">
                        {{ $case->attachments->whereNull('message_id')->count() }}
                    </span>
                @endif
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-ethics-comunicacion-btn" data-bs-toggle="pill" data-bs-target="#tab-ethics-comunicacion" type="button" role="tab" aria-selected="false">
                <i class="bi bi-chat-left-dots" style="margin-right: 6px;"></i>
                <span>2. Canal de Comunicación</span>
                @if($case->messages->isNotEmpty())
                    <span class="badge rounded-pill bg-primary text-white ms-1" style="font-size: 0.675rem;">
                        {{ $case->messages->count() }}
                    </span>
                @endif
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-ethics-gestion-btn" data-bs-toggle="pill" data-bs-target="#tab-ethics-gestion" type="button" role="tab" aria-selected="false">
                <i class="bi bi-sliders2" style="margin-right: 6px;"></i>
                <span>3. Gestión del Expediente</span>
            </button>
        </li>
    </ul>
</div>

<div class="modal-body emp-modal-body" style="background: var(--bg-surface-elevated);">
    <div class="tab-content" id="ethicsModalTabContent">

        {{-- =========================================================================
             PESTAÑA 1: HECHOS DENUNCIADOS Y EVIDENCIA
             ========================================================================= --}}
        <div class="tab-pane fade show active" id="tab-ethics-hechos" role="tabpanel" aria-labelledby="tab-ethics-hechos-btn">
            {{-- Recuadro de los Hechos Denunciados --}}
            <div class="emp-section-card mb-3">
                <div class="emp-section-title">
                    <i class="bi bi-file-text-fill"></i>
                    <span>Declaración de los hechos denunciados</span>
                </div>
                <div class="ethics-declaration-box">{{ trim($case->body) }}</div>
            </div>

            {{-- Evidencia y Fotos Adjuntas --}}
            @if($case->attachments->whereNull('message_id')->isNotEmpty())
                <div class="emp-section-card mb-3">
                    <div class="emp-section-title">
                        <i class="bi bi-images"></i>
                        <span>Evidencia y fotos adjuntas ({{ $case->attachments->whereNull('message_id')->count() }})</span>
                    </div>
                    <div class="p-3 rounded-2 border" style="background: var(--bg-surface);">
                        @include('ethics._photos', ['photos' => $case->attachments->whereNull('message_id')])
                    </div>
                </div>
            @endif

            {{-- Conclusión final si el caso está cerrado --}}
            @if($case->isClosed() && $case->resolution)
                <div class="alert alert-success d-flex align-items-start gap-2.5 mb-0" style="border-radius: 8px;">
                    <i class="bi bi-check-circle-fill text-success fs-5 flex-shrink-0 mt-0.5" style="margin-right: 6px;"></i>
                    <div>
                        <strong class="d-block mb-1 text-success-emphasis">Conclusión final comunicada al denunciante:</strong>
                        <div style="white-space: pre-line; line-height: 1.6;">{{ $case->resolution }}</div>
                    </div>
                </div>
            @endif
        </div>

        {{-- =========================================================================
             PESTAÑA 2: CANAL DE COMUNICACIÓN SEGURO
             ========================================================================= --}}
        <div class="tab-pane fade" id="tab-ethics-comunicacion" role="tabpanel" aria-labelledby="tab-ethics-comunicacion-btn">
            {{-- Historial de Mensajes --}}
            <div class="emp-section-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="emp-section-title mb-0">
                        <i class="bi bi-chat-left-dots-fill"></i>
                        <span>Conversación con el denunciante</span>
                    </div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-0.5" style="font-size: 0.7rem;">
                        <i class="bi bi-shield-lock-fill me-1"></i>Canal Cifrado
                    </span>
                </div>
                @if($case->messages->isEmpty())
                    <div class="text-center text-secondary border rounded-2" style="background: var(--bg-surface); padding: 1.85rem 1.5rem !important;">
                        <i class="bi bi-chat-square-dots text-primary fs-4 d-block mb-2"></i>
                        <div class="fw-bold text-body" style="font-size: 0.85rem; margin-bottom: 0.35rem;">Sin mensajes intercambiados aún</div>
                        <div class="text-muted small" style="font-size: 0.775rem; line-height: 1.5; max-width: 480px; margin: 0 auto;">El canal seguro está activo. Utiliza el formulario inferior para comunicarte de forma privada con el denunciante.</div>
                    </div>
                @else
                    <div class="ethics-chat-thread border rounded-2 p-2.5" style="background: var(--bg-surface); max-height: 180px; overflow-y: auto;">
                        @foreach($case->messages as $m)
                            <div class="ethics-chat-item {{ $m->fromReporter() ? 'from-reporter' : 'from-investigator' }}">
                                <div class="ethics-chat-avatar {{ $m->fromReporter() ? 'avatar-reporter' : 'avatar-investigator' }}">
                                    <i class="bi bi-{{ $m->fromReporter() ? ($case->identified ? 'person' : 'incognito') : 'person-badge' }}"></i>
                                </div>
                                <div class="d-flex flex-column">
                                    <div class="ethics-chat-meta">
                                        <strong>{{ $m->fromReporter() ? 'Denunciante' : ($m->investigator ? $m->investigator->first_name . ' ' . $m->investigator->last_name : 'Investigador') }}</strong>
                                        <span>· {{ $m->sent_on->format('d/m/Y H:i') }}</span>
                                    </div>
                                    <div class="ethics-chat-bubble">
                                        <div style="white-space: pre-line;">{{ $m->body }}</div>
                                        @include('ethics._photos', ['photos' => $m->attachments])
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Formulario para responder --}}
            @unless($case->isClosed())
                <div class="emp-section-card" style="margin-top: 1.35rem !important; padding: 1.1rem 1.25rem !important;">
                    <div class="emp-section-title mb-2.5">
                        <i class="bi bi-reply-fill me-1"></i>
                        <span>Redactar mensaje al denunciante</span>
                    </div>
                    <form method="POST" action="{{ route('ethics.reply', $case->id) }}">
                        @csrf
                        <textarea id="modal_body_{{ $case->id }}" 
                                  name="body" 
                                  rows="2" 
                                  maxlength="5000" 
                                  class="form-control ethics-reply-textarea mb-3" 
                                  placeholder="Escribe aquí tu respuesta, solicitud de información o requerimiento de pruebas para el denunciante..." 
                                  style="min-height: 70px; max-height: 95px; font-size: 0.85rem;"
                                  required></textarea>

                        @if(isset($errors) && $errors->has('body'))
                            <div class="px-2 py-1 text-danger small mb-2">{{ $errors->first('body') }}</div>
                        @endif

                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 pb-1">
                            <div class="form-check form-switch mb-0 d-inline-flex align-items-center" style="gap: 10px; padding-left: 0;">
                                <input class="form-check-input emp-custom-switch m-0" type="checkbox" role="switch" value="1" id="modal_ask_info_{{ $case->id }}" name="ask_for_info">
                                <label class="form-check-label small text-secondary fw-semibold user-select-none ps-1" for="modal_ask_info_{{ $case->id }}" style="cursor: pointer; font-size: 0.8rem;">
                                    Pedir más información (cambia a «Esperan info»)
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2 px-3.5 py-1.5 shadow-sm" style="border-radius: 7px; font-weight: 600; font-size: 0.8125rem;">
                                <i class="bi bi-send-fill me-1"></i>
                                <span>Enviar mensaje</span>
                            </button>
                        </div>
                        <div class="text-muted small mt-3 pt-2.5 border-top border-opacity-10 d-flex align-items-center" style="font-size: 0.75rem;">
                            <i class="bi bi-shield-check text-primary me-2 fs-6"></i>
                            <span>Canal bidireccional seguro · El denunciante responderá directamente en su bandeja privada.</span>
                        </div>
                    </form>
                </div>
            @endunless
        </div>

        {{-- =========================================================================
             PESTAÑA 3: GESTIÓN DEL EXPEDIENTE
             ========================================================================= --}}
        <div class="tab-pane fade" id="tab-ethics-gestion" role="tabpanel" aria-labelledby="tab-ethics-gestion-btn">
            {{-- Sección de Asignación y Estado --}}
            <div class="emp-section-card">
                <div class="emp-section-title mb-2">
                    <i class="bi bi-sliders me-1"></i>
                    <span>Resolución y Seguimiento</span>
                </div>
                <div class="row g-2.5">
                    {{-- Estado del Expediente --}}
                    <div class="col-12 col-md-6">
                        <label class="emp-form-label mb-1" style="font-size: 0.75rem;">Estado del caso</label>
                        <form method="POST" action="{{ route('ethics.status', $case->id) }}" id="modalStatusForm_{{ $case->id }}">
                            @csrf
                            @method('PUT')
                            <div class="d-flex gap-2 mb-1">
                                <select name="status" id="modalStatusSelect_{{ $case->id }}" class="emp-form-select" onchange="toggleModalResolutionField({{ $case->id }})" style="height: 36px; font-size: 0.8125rem;">
                                    @foreach(\App\Modules\Ethics\Models\EthicsCase::STATUSES as $key => $text)
                                        <option value="{{ $key }}" @selected(old('status', $case->status) === $key)>{{ $text }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary px-3 text-nowrap d-inline-flex align-items-center gap-2 shadow-sm" style="border-radius: 7px; font-weight: 600; height: 36px; font-size: 0.8125rem;">
                                    <i class="bi bi-arrow-repeat me-1"></i>
                                    <span>Guardar</span>
                                </button>
                            </div>
                            <div id="modalResolutionWrap_{{ $case->id }}" class="mt-2" style="display: {{ old('status', $case->status) === 'closed' ? 'block' : 'none' }};">
                                <label for="modalResolution_{{ $case->id }}" class="emp-form-label text-warning-emphasis mb-1" style="font-size: 0.75rem;">
                                    Conclusión final (visible para el denunciante):
                                </label>
                                <textarea id="modalResolution_{{ $case->id }}" name="resolution" rows="2" maxlength="5000" class="form-control form-control-sm p-2" placeholder="Describe qué medidas se adoptaron..." style="border-radius: 7px; font-size: 0.8125rem;">{{ old('resolution', $case->resolution) }}</textarea>
                            </div>
                        </form>
                    </div>

                    {{-- Investigador a Cargo --}}
                    <div class="col-12 col-md-6">
                        <label class="emp-form-label mb-1" style="font-size: 0.75rem;">Investigador asignado</label>
                        <form method="POST" action="{{ route('ethics.assign', $case->id) }}">
                            @csrf
                            @method('PUT')
                            <div class="d-flex gap-2">
                                <select name="assigned_to" class="emp-form-select" aria-label="Asignar investigador" style="height: 36px; font-size: 0.8125rem;">
                                    <option value="">Sin asignar</option>
                                    @foreach($investigators as $inv)
                                        <option value="{{ $inv->id }}" @selected($case->assigned_to === $inv->id)>
                                            {{ $inv->first_name }} {{ $inv->last_name }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary px-3 text-nowrap d-inline-flex align-items-center gap-2 shadow-sm" style="border-radius: 7px; font-weight: 600; height: 36px; font-size: 0.8125rem;">
                                    <i class="bi bi-person-check me-1"></i>
                                    <span>Asignar</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Sección de Privacidad e Identidad --}}
            <div class="emp-section-card mt-2">
                <div class="emp-section-title mb-1.5">
                    <i class="bi bi-shield-lock me-1"></i>
                    <span>Privacidad e Identidad</span>
                </div>
                @if(! $case->identified)
                    <div class="p-2.5 rounded-2 border d-flex align-items-center gap-3" style="background: var(--bg-surface);">
                        <div class="flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; min-width: 38px; border-radius: 9px; background: rgba(100, 116, 139, 0.12); color: var(--text-muted); border: 1px solid rgba(100, 116, 139, 0.25); font-size: 1.1rem;">
                            <i class="bi bi-incognito"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="fw-bold text-body small" style="font-size: 0.825rem;">Denuncia 100% Anónima (Web)</div>
                            <div class="text-secondary small" style="font-size: 0.775rem; line-height: 1.4; margin-top: 2px;">
                                Enviada desde el portal web público sin sesión. No se almacena dirección IP ni datos personales.
                            </div>
                        </div>
                    </div>
                @else
                    {{-- Tarjeta 1: Estado de Identidad Reservada --}}
                    <div class="ethics-identity-card">
                        <div class="d-flex align-items-center justify-content-between gap-3">
                            <div class="d-flex align-items-center gap-3 min-w-0">
                                <div class="flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; min-width: 38px; border-radius: 9px; background: rgba(var(--brand-rgb), 0.12); color: var(--accent-cyan); border: 1px solid rgba(var(--brand-rgb), 0.25); font-size: 1.1rem;">
                                    <i class="bi bi-person-lock"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="fw-bold text-body small" style="font-size: 0.825rem;">Identidad Reservada (App Móvil)</div>
                                    <div class="text-secondary small text-truncate" style="font-size: 0.775rem; line-height: 1.4; margin-top: 2px;">
                                        Identidad protegida por confidencialidad legal. Solo RR. HH. autorizado puede consultarla.
                                    </div>
                                </div>
                            </div>
                            @can('ethics.view_identity')
                                <div id="modalIdentityBtnWrapper" class="flex-shrink-0">
                                    @unless(session('ethics_identity'))
                                        <form method="POST" action="{{ route('ethics.identity', $case->id) }}" id="modalIdentityForm_{{ $case->id }}">
                                            @csrf
                                            <button type="button" id="btnRevealIdentity_{{ $case->id }}" onclick="revealEthicsIdentity({{ $case->id }}, this)" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-2 px-3.5 py-1.5 shadow-sm text-nowrap" style="border-radius: 7px; font-weight: 600; font-size: 0.8125rem;">
                                                <i class="bi bi-eye-fill me-1"></i>
                                                <span>Ver identidad</span>
                                            </button>
                                        </form>
                                    @else
                                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle px-2.5 py-1.5 fw-semibold d-inline-flex align-items-center gap-1.5" style="font-size: 0.75rem; border-radius: 6px;">
                                            <i class="bi bi-shield-check me-1"></i>
                                            <span>Identidad Revelada</span>
                                        </span>
                                    @endunless
                                </div>
                            @endcan
                        </div>
                    </div>

                    {{-- Tarjeta 2: Datos Registrados del Colaborador (ancho 100% alineado) --}}
                    @can('ethics.view_identity')
                        <div id="modalIdentityRevealWrapper">
                            @if($identity = session('ethics_identity'))
                                <div class="ethics-identity-data-card">
                                    <div class="d-flex align-items-center justify-content-between mb-3 pb-1 border-bottom">
                                        <span class="small fw-bold text-primary d-flex align-items-center gap-2">
                                            <i class="bi bi-person-vcard fs-6 me-1.5"></i>
                                            <span>Datos Registrados del Colaborador</span>
                                        </span>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-1" style="font-size: 0.725rem; border-radius: 6px;">
                                            <i class="bi bi-shield-lock me-1"></i>Confidencial · Autorizado
                                        </span>
                                    </div>
                                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem 1.25rem; font-size: 0.8125rem;">
                                        <div>
                                            <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">Nombre Completo:</span>
                                            <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600;">{{ $identity['name'] ?? '—' }}</strong>
                                        </div>
                                        <div>
                                            <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">Código Empleado:</span>
                                            <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600;">{{ $identity['employee_code'] ?? '—' }}</strong>
                                        </div>
                                        <div>
                                            <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">N° Documento:</span>
                                            <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600;">{{ !empty($identity['document']) ? (str_starts_with($identity['document'], 'DNI') ? $identity['document'] : 'DNI ' . $identity['document']) : '—' }}</strong>
                                        </div>
                                        <div>
                                            <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">Puesto / Cargo:</span>
                                            <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600;">{{ $identity['position'] ?? '—' }}</strong>
                                        </div>
                                        <div>
                                            <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">Correo Electrónico:</span>
                                            <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600; word-break: break-all;">{{ $identity['email'] ?? '—' }}</strong>
                                        </div>
                                        <div>
                                            <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">Teléfono:</span>
                                            <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600;">{{ $identity['phone'] ?? '—' }}</strong>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endcan
                @endif
            </div>

            {{-- Ficha Técnica y Trazabilidad --}}
            <div class="emp-section-card mt-2">
                <div class="emp-section-title mb-1.5">
                    <i class="bi bi-info-circle me-1"></i>
                    <span>Ficha Técnica y Trazabilidad</span>
                </div>
                <div class="row g-2" style="font-size: 0.785rem;">
                    <div class="col-6 col-md-3">
                        <span class="emp-form-label mb-0.5" style="font-size: 0.7rem;">Código único</span>
                        <strong class="text-body font-monospace fs-6">#{{ $case->public_code }}</strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <span class="emp-form-label mb-0.5" style="font-size: 0.7rem;">Canal de origen</span>
                        <span class="text-body">{{ $case->isFromApp() ? 'App Móvil' : 'Web Anónima' }}</span>
                    </div>
                    <div class="col-6 col-md-3">
                        <span class="emp-form-label mb-0.5" style="font-size: 0.7rem;">Fecha de recepción</span>
                        <span class="text-body">{{ $case->reported_on->format('d/m/Y H:i') }}</span>
                    </div>
                    <div class="col-6 col-md-3">
                        <span class="emp-form-label mb-0.5" style="font-size: 0.7rem;">Estado de cierre</span>
                        <span class="text-body">{{ $case->closed_at ? $case->closed_at->format('d/m/Y H:i') : 'En curso' }}</span>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal-footer px-3 py-2 d-flex align-items-center justify-content-end border-top" style="background: var(--bg-surface);">
    <button type="button" class="btn btn-outline-secondary px-3.5 py-1.5" data-bs-dismiss="modal" style="border-radius: 7px; font-weight: 600; font-size: 0.8125rem;">
        Cerrar
    </button>
</div>

<script>
function toggleModalResolutionField(caseId) {
    const select = document.getElementById('modalStatusSelect_' + caseId);
    const wrap = document.getElementById('modalResolutionWrap_' + caseId);
    if (select && wrap) {
        wrap.style.display = select.value === 'closed' ? 'block' : 'none';
    }
}

{{-- Asegurar cambio de tabs sin importar estado de Bootstrap data-api --}}
document.querySelectorAll('#ethicsModalTabs button[data-bs-toggle="pill"]').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const targetId = this.getAttribute('data-bs-target');
        if (!targetId) return;

        document.querySelectorAll('#ethicsModalTabs .nav-link').forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });
        this.classList.add('active');
        this.setAttribute('aria-selected', 'true');

        document.querySelectorAll('#ethicsModalTabContent .tab-pane').forEach(p => {
            p.classList.remove('show', 'active');
        });
        const targetPane = document.querySelector(targetId);
        if (targetPane) {
            targetPane.classList.add('show', 'active');
        }
    });
});
</script>
