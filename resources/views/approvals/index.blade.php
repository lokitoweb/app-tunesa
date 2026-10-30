@extends('layouts.app')

@section('title', 'Aprobaciones')

@section('content')
@php
    $statusLabels = ['pending' => 'Pendientes', 'approved' => 'Aprobadas', 'rejected' => 'Rechazadas', 'cancelled' => 'Canceladas'];
@endphp

@php $totalPending = ($counts['leave'] ?? 0) + ($counts['justifications'] ?? 0) + ($counts['overtime'] ?? 0); @endphp
<header class="page-head">
    <div class="page-head-text">
        <h1 class="page-title">Aprobaciones</h1>
        <p class="page-subtitle">Vacaciones, permisos, licencias médicas, justificaciones de asistencia y horas extra.</p>
    </div>
    <div class="page-actions" id="apprHeaderBadge">
        @if($totalPending > 0)
            <span class="status-pill tone-rose"><i class="bi bi-bell"></i>{{ $totalPending }} pendientes</span>
        @else
            <span class="status-pill tone-emerald"><i class="bi bi-check-circle"></i>Al día</span>
        @endif
    </div>
</header>

{{-- Zona que se actualiza por AJAX (pestaña, estado y página) sin cambiar la URL --}}
<div id="approvalsDynamicWrapper" class="appr-dynamic-area">
<section class="surface">
    <div class="filter-row justify-content-between">
        <div class="seg-tabs" role="tablist" aria-label="Tipo de solicitud">
            @foreach ([['leave', 'Solicitudes', null], ['justifications', 'Justificaciones', 'attendance.review'], ['overtime', 'Horas extra', null]] as [$tkey, $tlabel, $tcan])
                @if(! $tcan || auth()->user()->can($tcan))
                    <button type="button" role="tab" class="seg-tab {{ $tab === $tkey ? 'is-active' : '' }}" data-tab="{{ $tkey }}" aria-selected="{{ $tab === $tkey ? 'true' : 'false' }}" onclick="switchTab('{{ $tkey }}')">
                        {{ $tlabel }}
                        @if($counts[$tkey] ?? 0)<span class="count-pill tone-rose">{{ $counts[$tkey] }}</span>@endif
                    </button>
                @endif
            @endforeach
        </div>
        <div class="seg-tabs" role="group" aria-label="Filtrar por estado">
            @foreach($statusLabels as $key => $label)
                @continue(in_array($tab, ['justifications', 'overtime'], true) && $key === 'cancelled')
                <button type="button" class="seg-tab {{ $status === $key ? 'is-active' : '' }}" data-status="{{ $key }}" aria-pressed="{{ $status === $key ? 'true' : 'false' }}" onclick="switchStatus('{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if($tab === 'overtime' && $status === 'pending' && $items->isNotEmpty())
        <form method="POST" action="{{ route('approvals.overtime.bulk') }}" id="overtimeBulk" class="surface-toolbar">
            @csrf
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="overtimeAll" onclick="document.querySelectorAll('[data-overtime-pick]').forEach((c) => c.checked = this.checked)">
                <label class="form-check-label small fw-semibold" for="overtimeAll">Seleccionar todas para aprobación masiva</label>
            </div>
            <button class="btn btn-sm btn-success ms-auto"><i class="bi bi-check2-all me-1"></i>Aprobar las marcadas</button>
        </form>
    @endif

    <div class="table-responsive">
    @if($tab === 'leave')
        <table class="table data-table table-cards align-middle mb-0">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Tipo</th>
                    <th>Fechas</th>
                    <th>Días</th>
                    <th>Motivo</th>
                    <th>Estado</th>
                    <th class="text-end">Acción</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $leave)
                    <tr>
                        <td class="cell-main">
                            @include('approvals.partials.person', ['person' => $leave->employee, 'tenantId' => $leave->tenant_id])
                            @if($leave->isPending())
                                <div class="cell-sub" title="Puede aprobarla su jefe inmediato o RR. HH."><i class="bi bi-person-check me-1"></i>Revisa: {{ $leave->reviewersLabel() }}</div>
                            @endif
                        </td>
                        <td data-label="Tipo">
                            @php $leaveTone = ['vacation' => 'blue', 'permission' => 'amber', 'medical_leave' => 'rose'][$leave->type] ?? 'neutral'; @endphp
                            <span class="status-pill tone-{{ $leaveTone }}">{{ $leave->type_label }}</span>
                        </td>
                        <td class="text-nowrap" data-label="Fechas">
                            <div>
                                <div class="cell-title">{{ $leave->start_date->format('d/m/Y') }} → {{ $leave->end_date->format('d/m/Y') }}</div>
                                @if($leave->start_time)
                                    <div class="cell-sub">{{ hora($leave->start_time) }} – {{ hora($leave->end_time) }}</div>
                                @endif
                            </div>
                        </td>
                        <td class="text-nowrap" data-label="Días">
                            <span>{{ rtrim(rtrim((string) $leave->days, '0'), '.') }} {{ (float) $leave->days == 1 ? 'día' : 'días' }}</span>
                        </td>
                        <td data-label="Motivo">
                            <div class="appr-reason">
                                {{ $leave->reason }}
                                @if($leave->attachment_path)
                                    <div><a href="{{ route('approvals.leave.attachment', $leave->id) }}" target="_blank" rel="noopener" class="small"><i class="bi bi-paperclip me-1"></i>Ver sustento</a></div>
                                @endif
                            </div>
                        </td>
                        <td data-label="Estado">
                            <div>
                                <x-status-badge type="leave_status" :value="$leave->status" />
                                @if($leave->reviewer)
                                    <div class="cell-sub mt-1"><i class="bi bi-person-check me-1"></i>{{ $leave->reviewer->full_name }} · {{ $leave->reviewed_at?->format('d/m h:i a') }}</div>
                                @endif
                                @if($leave->review_notes)
                                    <div class="appr-review-note"><i class="bi bi-chat-left-quote me-1"></i>{{ $leave->review_notes }}</div>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="row-actions">
                                @if($leave->isPending())
                                    @include('approvals.partials.review-buttons', ['action' => route('approvals.leave.review', $leave->id), 'kind' => 'leave', 'who' => $leave->employee?->full_name, 'what' => $leave->type_label . ' · ' . $leave->start_date->format('d/m/Y') . ' → ' . $leave->end_date->format('d/m/Y')])
                                @else
                                    <span class="cell-sub"><i class="bi bi-check2-all me-1"></i>Procesado</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    @include('approvals.partials.empty', ['cols' => 7, 'what' => 'solicitudes'])
                @endforelse
            </tbody>
        </table>
    @elseif($tab === 'overtime')
        <table class="table data-table table-cards align-middle mb-0">
            <thead>
                <tr>
                    @if($status === 'pending')<th style="width: 2.5rem;"><span class="visually-hidden">Marcar</span></th>@endif
                    <th>Colaborador</th>
                    <th>Fecha</th>
                    <th>Jornada</th>
                    <th class="text-end">Horas extra</th>
                    <th>Estado</th>
                    <th class="text-end">Acción</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $att)
                    @php $own = (int) $att->user_id === (int) auth()->id(); @endphp
                    <tr>
                        @if($status === 'pending')
                            <td>
                                @unless($own)
                                    <input class="form-check-input" type="checkbox" name="ids[]" value="{{ $att->id }}" form="overtimeBulk" data-overtime-pick aria-label="Marcar a {{ $att->user?->full_name }}">
                                @endunless
                            </td>
                        @endif
                        <td class="cell-main">
                            @include('approvals.partials.person', ['person' => $att->user, 'tenantId' => $att->tenant_id])
                        </td>
                        <td class="text-nowrap" data-label="Fecha">
                            <div>
                                <a href="{{ route('attendance.show', $att->id) }}" class="cell-title text-decoration-none">{{ $att->work_date->format('d/m/Y') }}</a>
                                <div class="cell-sub">{{ $att->branch?->name ?? 'Sin sede' }}</div>
                            </div>
                        </td>
                        <td class="text-nowrap" data-label="Jornada">
                            <div>
                                <div class="cell-title">{{ $att->clock_in_at?->format('h:i a') }} – {{ $att->clock_out_at?->format('h:i a') }}</div>
                                <div class="cell-sub">{{ formato_horas_minutos($att->worked_hours) }} trabajadas{{ $att->shift ? ' · turno de ' . formato_horas_minutos($att->shift->daily_hours) : '' }}</div>
                            </div>
                        </td>
                        <td class="text-end" data-label="Horas extra">
                            <div>
                                <span class="status-pill tone-brand">+{{ formato_horas_minutos($att->overtime_hours) }}</span>
                                @if($att->overtime_status === 'approved' && (float) $att->overtime_approved_hours < (float) $att->overtime_hours)
                                    <div class="cell-sub mt-1">{{ formato_horas_minutos($att->overtime_approved_hours) }} aprobadas</div>
                                @endif
                            </div>
                        </td>
                        <td data-label="Estado">
                            <div>
                                <x-status-badge type="overtime_status" :value="$att->overtime_status" />
                                @if($att->overtimeReviewer)
                                    <div class="cell-sub mt-1"><i class="bi bi-person-check me-1"></i>{{ $att->overtimeReviewer->full_name }} · {{ $att->overtime_reviewed_at?->format('d/m h:i a') }}</div>
                                @endif
                                @if($att->overtime_review_notes)
                                    <div class="appr-review-note"><i class="bi bi-chat-left-quote me-1"></i>{{ $att->overtime_review_notes }}</div>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="row-actions">
                                @if($att->overtime_status === 'pending' && $own)
                                    <span class="cell-sub"><i class="bi bi-info-circle me-1"></i>Las revisa tu jefatura o RRHH.</span>
                                @elseif($att->overtime_status === 'pending')
                                    @include('approvals.partials.review-buttons', ['action' => route('approvals.overtime.review', $att->id), 'kind' => 'overtime', 'who' => $att->user?->full_name, 'what' => 'Horas extra del ' . $att->work_date->format('d/m/Y') . ': +' . formato_horas_minutos($att->overtime_hours), 'hours' => (float) $att->overtime_hours])
                                @else
                                    <span class="cell-sub"><i class="bi bi-check2-all me-1"></i>Procesado</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    @include('approvals.partials.empty', ['cols' => 7, 'what' => 'horas extra'])
                @endforelse
            </tbody>
        </table>
    @else
        <table class="table data-table table-cards align-middle mb-0">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Fecha</th>
                    <th>Sucursal</th>
                    <th>Asistencia</th>
                    <th>Justificación</th>
                    <th class="text-end">Acción</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $att)
                    <tr>
                        <td class="cell-main">
                            @include('approvals.partials.person', ['person' => $att->user, 'tenantId' => $att->tenant_id])
                        </td>
                        <td class="text-nowrap" data-label="Fecha">
                            <a href="{{ route('attendance.show', $att->id) }}" class="cell-title text-decoration-none">{{ $att->work_date->format('d/m/Y') }}</a>
                        </td>
                        <td data-label="Sucursal">
                            <span>{{ $att->branch?->name ?? 'Sin sede' }}</span>
                        </td>
                        <td data-label="Asistencia">
                            <div>
                                @include('partials.attendance-status', ['status' => $att->status])
                                @if($att->late_minutes)
                                    <div class="cell-sub mt-1">({{ formato_tardanza($att) }})</div>
                                @endif
                                @if($att->clock_in_at)
                                    <div class="cell-sub">Entrada: {{ hora($att->clock_in_at, true) }}</div>
                                @endif
                            </div>
                        </td>
                        <td data-label="Justificación">
                            <div class="appr-reason">
                                {{ \Illuminate\Support\Str::limit($att->notes, 140) }}
                                @if($att->justification_file)
                                    <div><a href="{{ route('attendance.justification-file', $att->id) }}" target="_blank" rel="noopener" class="small"><i class="bi bi-paperclip me-1"></i>Ver sustento</a></div>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="row-actions">
                                @if($att->justification_status === 'pending')
                                    @include('approvals.partials.review-buttons', ['action' => route('attendance.review', $att->id), 'kind' => 'justification', 'who' => $att->user?->full_name, 'what' => 'Justificación del ' . $att->work_date->format('d/m/Y')])
                                @else
                                    <x-status-badge type="justification_status" :value="$att->justification_status" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    @include('approvals.partials.empty', ['cols' => 6, 'what' => 'justificaciones'])
                @endforelse
            </tbody>
        </table>
    @endif
    </div>

    @if($items->total() > 0)
        <div class="table-pagination-footer">
            <div class="table-pagination-info">
                <span>
                    Mostrando <strong>{{ $items->firstItem() }}</strong> a <strong>{{ $items->lastItem() }}</strong> de <strong>{{ $items->total() }}</strong> {{ $tab === 'leave' ? 'solicitudes' : ($tab === 'overtime' ? 'registros de horas extra' : 'justificaciones') }}
                </span>
                <div class="per-page-selector ms-sm-2">
                    <label for="approvalsPerPageSelect" class="text-secondary small mb-0">Mostrar:</label>
                    <select id="approvalsPerPageSelect" class="form-select form-select-sm" onchange="changeApprovalsPerPage(this.value)">
                        <option value="10" @selected($items->perPage() == 10)>10</option>
                        <option value="20" @selected($items->perPage() == 20)>20</option>
                        <option value="50" @selected($items->perPage() == 50)>50</option>
                        <option value="100" @selected($items->perPage() == 100)>100</option>
                    </select>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                @if($items->hasPages())
                    {{ $items->links() }}
                @else
                    <nav aria-label="Navegación de páginas">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item disabled" aria-disabled="true"><span class="page-link" aria-label="Anterior">&lsaquo;</span></li>
                            <li class="page-item active" aria-current="page"><span class="page-link">1</span></li>
                            <li class="page-item disabled" aria-disabled="true"><span class="page-link" aria-label="Siguiente">&rsaquo;</span></li>
                        </ul>
                    </nav>
                @endif
                <span class="pagination-page-indicator">Pág. {{ $items->currentPage() }} de {{ $items->lastPage() }}</span>
            </div>
        </div>
    @endif
</section>
</div>

{{-- Diálogo único para aprobar o rechazar: se llena con los datos del botón pulsado --}}
<div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="" class="modal-content" id="reviewForm">
            @csrf
            <input type="hidden" id="reviewDecision" value="">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title h5" id="reviewModalTitle">Aprobar</h2>
                    <div class="small text-body-secondary" id="reviewModalWho"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="small text-body-secondary mb-3" id="reviewModalWhat"></p>
                <div class="mb-3 d-none" id="reviewHoursBox">
                    <label class="form-label small fw-semibold" for="reviewHours">Horas a aprobar</label>
                    <div class="input-group">
                        <input type="number" name="hours" id="reviewHours" min="0.25" step="0.25" class="form-control" disabled>
                        <span class="input-group-text">h</span>
                    </div>
                    <div class="form-text">Puedes aprobar menos horas de las registradas.</div>
                </div>
                <label class="form-label small fw-semibold" for="reviewNotes">Comentario <span id="reviewNotesHint" class="text-body-secondary fw-normal">(opcional)</span></label>
                <textarea id="reviewNotes" class="form-control" rows="3" maxlength="500" placeholder="Escribe el motivo…"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success" id="reviewSubmit">Aprobar</button>
            </div>
        </form>
    </div>
</div>

<script>
{{-- Estado reactivo interno de Aprobaciones (Totalmente asíncrono y desacoplado de la URL del navegador) --}}
let currentApprovalsState = {
    tab: '{{ $tab }}',
    status: '{{ $status }}',
    per_page: '{{ $items->perPage() }}',
    page: {{ $items->currentPage() }}
};

let approvalsAbortController = null;

async function loadApprovals(overrides = {}) {
    Object.assign(currentApprovalsState, overrides);

    const wrapper = document.getElementById('approvalsDynamicWrapper');
    if (wrapper) {
        wrapper.classList.add('is-loading');
    }

    if (approvalsAbortController) {
        approvalsAbortController.abort();
    }
    approvalsAbortController = new AbortController();

    try {
        const url = new URL('{{ route("approvals.index") }}', window.location.origin);
        url.searchParams.set('tab', currentApprovalsState.tab);
        url.searchParams.set('status', currentApprovalsState.status);
        url.searchParams.set('per_page', currentApprovalsState.per_page);
        url.searchParams.set('page', currentApprovalsState.page);

        const response = await fetch(url.toString(), {
            signal: approvalsAbortController.signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        });

        if (!response.ok) throw new Error('Error al actualizar datos');

        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');

        const newWrapper = doc.getElementById('approvalsDynamicWrapper');
        if (newWrapper && wrapper) {
            wrapper.innerHTML = newWrapper.innerHTML;
        }

        const newHeaderBadge = doc.getElementById('apprHeaderBadge');
        const currentHeaderBadge = document.getElementById('apprHeaderBadge');
        if (newHeaderBadge && currentHeaderBadge) {
            currentHeaderBadge.innerHTML = newHeaderBadge.innerHTML;
        }

        {{-- Mantener la URL del navegador completamente limpia sin cadenas de consulta (ej: /approvals) --}}
        window.history.replaceState({}, '', '{{ route("approvals.index") }}');
    } catch (err) {
        if (err.name !== 'AbortError') {
            console.error('Error al cargar aprobaciones:', err);
        }
    } finally {
        if (wrapper) {
            wrapper.classList.remove('is-loading');
        }
    }
}

function switchTab(tab) {
    let newStatus = currentApprovalsState.status;
    if ((tab === 'justifications' || tab === 'overtime') && newStatus === 'cancelled') {
        newStatus = 'pending';
    }
    loadApprovals({ tab: tab, status: newStatus, page: 1 });
}

function switchStatus(status) {
    loadApprovals({ status: status, page: 1 });
}

function changeApprovalsPerPage(perPage) {
    loadApprovals({ per_page: perPage, page: 1 });
}

{{-- Delegación de eventos para la paginación interna sin alterar la URL ni recargar la pantalla --}}
document.addEventListener('click', function(e) {
    const pageLink = e.target.closest('#approvalsDynamicWrapper .pagination a.page-link');
    if (pageLink) {
        e.preventDefault();
        const href = pageLink.getAttribute('href');
        if (href) {
            try {
                const targetUrl = new URL(href, window.location.origin);
                const page = targetUrl.searchParams.get('page') || 1;
                loadApprovals({ page: parseInt(page, 10) });
            } catch (ex) {
                {{-- fallback --}}
            }
        }
    }
});

{{-- Diálogo de revisión: cada tipo envía los mismos campos que antes al mismo endpoint --}}
const REVIEW_FIELDS = {
    leave: { decision: 'decision', notes: 'notes', notesRequiredOnReject: true },
    overtime: { decision: 'decision', notes: 'notes', notesRequiredOnReject: true },
    justification: { decision: 'justification_status', notes: 'review_notes', notesRequiredOnReject: false },
};
document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-review]');
    if (!btn) return;
    const d = btn.dataset;
    const fields = REVIEW_FIELDS[d.kind];
    const approve = d.decision === 'approved';
    const form = document.getElementById('reviewForm');
    const decision = document.getElementById('reviewDecision');
    const notes = document.getElementById('reviewNotes');
    const hours = document.getElementById('reviewHours');
    const submit = document.getElementById('reviewSubmit');
    const notesRequired = !approve && fields.notesRequiredOnReject;

    form.action = d.action;
    decision.name = fields.decision;
    decision.value = d.decision;
    notes.name = fields.notes;
    notes.value = '';
    notes.required = notesRequired;
    document.getElementById('reviewNotesHint').textContent = notesRequired ? '(obligatorio)' : '(opcional)';
    document.getElementById('reviewModalTitle').textContent = approve ? 'Aprobar' : 'Rechazar';
    document.getElementById('reviewModalWho').textContent = d.who || '';
    document.getElementById('reviewModalWhat').textContent = d.what || '';
    const withHours = approve && d.kind === 'overtime';
    document.getElementById('reviewHoursBox').classList.toggle('d-none', !withHours);
    hours.disabled = !withHours;
    if (withHours) { hours.max = d.hours; hours.value = d.hours; }
    submit.textContent = approve ? 'Aprobar' : 'Rechazar';
    submit.className = 'btn ' + (approve ? 'btn-success' : 'btn-danger');

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewModal'));
    modal.show();
});
document.getElementById('reviewModal')?.addEventListener('shown.bs.modal', function () {
    document.getElementById(document.getElementById('reviewHours').disabled ? 'reviewNotes' : 'reviewHours').focus();
});

{{-- Limpiar la URL inicial al cargar la página si el navegador traía query params --}}
document.addEventListener('DOMContentLoaded', function() {
    window.history.replaceState({}, '', '{{ route("approvals.index") }}');
});
</script>
@endsection
