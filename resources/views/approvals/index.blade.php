@extends('layouts.app')

@section('title', 'Aprobaciones')

@section('content')
@php
    $statusLabels = ['pending' => 'Pendientes', 'approved' => 'Aprobadas', 'rejected' => 'Rechazadas', 'cancelled' => 'Canceladas'];
@endphp

{{-- Header Ejecutivo con Título, Subtítulo y Badge de Resumen --}}
<div class="appr-header-bar">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1" id="apprHeaderBadge">
            <h1 class="h3 fw-bold mb-0 text-body">Aprobaciones</h1>
            @php $totalPending = ($counts['leave'] ?? 0) + ($counts['justifications'] ?? 0) + ($counts['overtime'] ?? 0); @endphp
            @if($totalPending > 0)
                <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" style="font-size: 0.775rem; font-weight: 700;">
                    <i class="bi bi-bell-fill me-1"></i>{{ $totalPending }} pendientes
                </span>
            @else
                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.775rem; font-weight: 600;">
                    <i class="bi bi-check-circle me-1"></i>Al día
                </span>
            @endif
        </div>
        <p class="text-secondary small mb-0">Vacaciones, permisos, licencias médicas, justificaciones de asistencia y horas extra.</p>
    </div>
</div>

{{-- Contenedor Reactivo Dinámico AJAX (Actualización Fluida sin Recargas ni Parámetros Expuestos en URL) --}}
<div id="approvalsDynamicWrapper" class="appr-dynamic-area">
    {{-- Barra de Navegación Segmentada (Pestañas de Módulo y Filtro de Estados) --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div class="appr-tab-group">
            <button type="button" 
                    class="appr-tab-btn {{ $tab === 'leave' ? 'active' : '' }}" 
                    data-tab="leave" 
                    onclick="switchTab('leave')"
                    @if($tab === 'leave') aria-current="page" @endif>
                <i class="bi bi-calendar-check"></i>
                <span>Solicitudes</span>
                @if($counts['leave'])
                    <span class="appr-counter-pill">{{ $counts['leave'] }}</span>
                @endif
            </button>
            @can('attendance.review')
            <button type="button" 
                    class="appr-tab-btn {{ $tab === 'justifications' ? 'active' : '' }}" 
                    data-tab="justifications" 
                    onclick="switchTab('justifications')"
                    @if($tab === 'justifications') aria-current="page" @endif>
                <i class="bi bi-card-checklist"></i>
                <span>Justificaciones</span>
                @if($counts['justifications'])
                    <span class="appr-counter-pill">{{ $counts['justifications'] }}</span>
                @endif
            </button>
            @endcan
            <button type="button" 
                    class="appr-tab-btn {{ $tab === 'overtime' ? 'active' : '' }}" 
                    data-tab="overtime" 
                    onclick="switchTab('overtime')"
                    @if($tab === 'overtime') aria-current="page" @endif>
                <i class="bi bi-clock-history"></i>
                <span>Horas extra</span>
                @if($counts['overtime'])
                    <span class="appr-counter-pill">{{ $counts['overtime'] }}</span>
                @endif
            </button>
        </div>

        <div class="appr-status-group" role="group" aria-label="Filtrar por estado">
            @foreach($statusLabels as $key => $label)
                @continue(in_array($tab, ['justifications', 'overtime'], true) && $key === 'cancelled')
                <button type="button" 
                        class="appr-status-btn {{ $status === $key ? 'active' : '' }}"
                        data-status="{{ $key }}"
                        onclick="switchStatus('{{ $key }}')">
                    @if($key === 'pending')
                        <i class="bi bi-hourglass-split"></i>
                    @elseif($key === 'approved')
                        <i class="bi bi-check2-circle"></i>
                    @elseif($key === 'rejected')
                        <i class="bi bi-x-circle"></i>
                    @else
                        <i class="bi bi-dash-circle"></i>
                    @endif
                    <span>{{ $label }}</span>
                </button>
            @endforeach
        </div>
    </div>

<div class="card border-0 shadow-sm appr-card">
    <div class="table-responsive">
    @if($tab === 'leave')
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-4">Colaborador</th>
                    <th>Tipo</th>
                    <th>Fechas</th>
                    <th>Días</th>
                    <th>Motivo</th>
                    <th>Estado</th>
                    <th class="pe-4" style="min-width: 270px;">Acción</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $leave)
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-3">
                                @if(!empty($leave->employee?->avatar_path))
                                    <img src="{{ asset('storage/' . $leave->employee->avatar_path) }}" alt="{{ $leave->employee->first_name }}" class="attendance-user-avatar">
                                @else
                                    <span class="attendance-user-initials">
                                        {{ mb_substr($leave->employee?->first_name ?? 'U', 0, 1) }}{{ mb_substr($leave->employee?->last_name ?? '', 0, 1) }}
                                    </span>
                                @endif
                                <div class="min-w-0">
                                    <div class="fw-bold text-body text-truncate" style="font-size: 0.875rem;">
                                        {{ $leave->employee?->full_name }}
                                    </div>
                                    <div class="small text-secondary text-truncate" style="font-size: 0.775rem;">
                                        {{ $leave->employee?->employee_code ?? '—' }}
                                    </div>
                                    @if($leave->isPending())
                                        <div class="small text-secondary" style="font-size: 0.75rem;" title="Puede aprobarla su jefe inmediato o RR. HH.">
                                            <i class="bi bi-person-check me-1"></i>Revisa: {{ $leave->reviewersLabel() }}
                                        </div>
                                    @endif
                                    @include('partials.tenant-badge', ['tenantId' => $leave->tenant_id])
                                </div>
                            </div>
                        </td>
                        <td>
                            @if($leave->type === 'vacation')
                                <span class="appr-type-badge type-vacation"><i class="bi bi-sun"></i> {{ $leave->type_label }}</span>
                            @elseif($leave->type === 'permission')
                                <span class="appr-type-badge type-permission"><i class="bi bi-clock"></i> {{ $leave->type_label }}</span>
                            @elseif($leave->type === 'medical_leave')
                                <span class="appr-type-badge type-medical"><i class="bi bi-heart-pulse"></i> {{ $leave->type_label }}</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">{{ $leave->type_label }}</span>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            <div class="fw-semibold text-body" style="font-size: 0.825rem;">
                                <i class="bi bi-calendar3 text-muted me-1"></i>{{ $leave->start_date->format('d/m/Y') }} → {{ $leave->end_date->format('d/m/Y') }}
                            </div>
                            @if($leave->start_time)
                                <div class="small text-secondary mt-0.5">
                                    <i class="bi bi-clock text-muted me-1"></i>{{ hora($leave->start_time) }} – {{ hora($leave->end_time) }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" style="font-size: 0.775rem; font-weight: 600;">
                                {{ rtrim(rtrim((string) $leave->days, '0'), '.') }} {{ (float)$leave->days == 1 ? 'día' : 'días' }}
                            </span>
                        </td>
                        <td>
                            <div class="small text-body" style="max-width: 280px; line-height: 1.4;">{{ $leave->reason }}</div>
                            @if($leave->attachment_path)
                                <div>
                                    <a href="{{ route('approvals.leave.attachment', $leave->id) }}" target="_blank" rel="noopener" class="appr-attachment-link">
                                        <i class="bi bi-paperclip"></i>
                                        <span>Ver sustento</span>
                                    </a>
                                </div>
                            @endif
                        </td>
                        <td>
                            <x-status-badge type="leave_status" :value="$leave->status" />
                            @if($leave->reviewer)
                                <div class="small text-secondary mt-1" style="font-size: 0.75rem;">
                                    <i class="bi bi-person-check me-1"></i>{{ $leave->reviewer->full_name }} · {{ $leave->reviewed_at?->format('d/m h:i a') }}
                                </div>
                            @endif
                            @if($leave->review_notes)
                                <div class="appr-review-note">
                                    <i class="bi bi-chat-left-quote me-1"></i>{{ $leave->review_notes }}
                                </div>
                            @endif
                        </td>
                        <td class="pe-4">
                            @if($leave->isPending())
                                <form method="POST" action="{{ route('approvals.leave.review', $leave->id) }}" class="appr-action-box">
                                    @csrf
                                    <input name="notes" class="form-control form-control-sm mb-2" placeholder="Comentario (obligatorio si rechazas)" aria-label="Comentario" style="border-radius: 6px;">
                                    <div class="d-flex gap-2">
                                        <button name="decision" value="approved" class="btn btn-sm btn-success flex-fill d-inline-flex align-items-center justify-content-center shadow-sm" style="border-radius: 6px; font-weight: 600; height: 32px; gap: 5px;">
                                            <i class="bi bi-check-lg"></i>
                                            <span>Aprobar</span>
                                        </button>
                                        <button name="decision" value="rejected" class="btn btn-sm btn-outline-danger flex-fill d-inline-flex align-items-center justify-content-center" style="border-radius: 6px; font-weight: 600; height: 32px; gap: 5px;">
                                            <i class="bi bi-x-lg"></i>
                                            <span>Rechazar</span>
                                        </button>
                                    </div>
                                </form>
                            @else
                                <span class="small text-secondary fst-italic">
                                    <i class="bi bi-check2-all me-1"></i>Procesado
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-5 text-center">
                            <div class="empty-state py-4">
                                <div style="width: 54px; height: 54px; border-radius: 12px; background: var(--bg-surface-elevated); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 1rem; border: 1px solid var(--border-color);">
                                    <i class="bi bi-check2-all"></i>
                                </div>
                                <div class="fw-bold text-body fs-6">No hay solicitudes {{ strtolower($statusLabels[$status] ?? '') }}</div>
                                <div class="text-secondary small mt-1" style="max-width: 420px; margin: 0 auto;">No se encontraron solicitudes con este estado para el filtro actual.</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @elseif($tab === 'overtime')
        @if($status === 'pending' && $items->isNotEmpty())
            <form method="POST" action="{{ route('approvals.overtime.bulk') }}" id="overtimeBulk" class="appr-bulk-toolbar">
                @csrf
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="overtimeAll" onclick="document.querySelectorAll('[data-overtime-pick]').forEach((c) => c.checked = this.checked)">
                    <label class="form-check-label small fw-semibold text-body" for="overtimeAll">Seleccionar todas para aprobación masiva</label>
                </div>
                <button class="btn btn-sm btn-success ms-auto d-inline-flex align-items-center shadow-sm" style="border-radius: 6px; font-weight: 600; height: 32px; gap: 6px;">
                    <i class="bi bi-check2-all"></i>
                    <span>Aprobar las marcadas</span>
                </button>
            </form>
        @endif
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    @if($status === 'pending')<th style="width: 2.5rem;" class="ps-4"><span class="visually-hidden">Marcar</span></th>@endif
                    <th class="{{ $status !== 'pending' ? 'ps-4' : '' }}">Colaborador</th>
                    <th>Fecha</th>
                    <th>Jornada</th>
                    <th class="text-end">Horas extra</th>
                    <th>Estado</th>
                    <th class="pe-4" style="min-width: 280px;">Acción</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $att)
                    @php $own = (int) $att->user_id === (int) auth()->id(); @endphp
                    <tr>
                        @if($status === 'pending')
                            <td class="ps-4">
                                @unless($own)
                                    <input class="form-check-input" type="checkbox" name="ids[]" value="{{ $att->id }}" form="overtimeBulk" data-overtime-pick aria-label="Marcar a {{ $att->user?->full_name }}">
                                @endunless
                            </td>
                        @endif
                        <td class="{{ $status !== 'pending' ? 'ps-4' : '' }}">
                            <div class="d-flex align-items-center gap-3">
                                @if(!empty($att->user?->avatar_path))
                                    <img src="{{ asset('storage/' . $att->user->avatar_path) }}" alt="{{ $att->user->first_name }}" class="attendance-user-avatar">
                                @else
                                    <span class="attendance-user-initials">
                                        {{ mb_substr($att->user?->first_name ?? 'U', 0, 1) }}{{ mb_substr($att->user?->last_name ?? '', 0, 1) }}
                                    </span>
                                @endif
                                <div class="min-w-0">
                                    <div class="fw-bold text-body text-truncate" style="font-size: 0.875rem;">
                                        {{ $att->user?->full_name }}
                                    </div>
                                    <div class="small text-secondary text-truncate" style="font-size: 0.775rem;">
                                        {{ $att->user?->employee_code ?? '—' }}
                                    </div>
                                    @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])
                                </div>
                            </div>
                        </td>
                        <td class="text-nowrap">
                            <a href="{{ route('attendance.show', $att->id) }}" class="fw-semibold text-decoration-none hover-primary" style="font-size: 0.825rem;">
                                <i class="bi bi-calendar3 text-muted me-1"></i>{{ $att->work_date->format('d/m/Y') }}
                            </a>
                            <div class="small text-secondary">
                                <i class="bi bi-geo-alt text-muted me-1"></i>{{ $att->branch?->name ?? 'Sin sede' }}
                            </div>
                        </td>
                        <td class="small text-nowrap">
                            <div class="fw-semibold text-body">
                                {{ $att->clock_in_at?->format('h:i a') }} – {{ $att->clock_out_at?->format('h:i a') }}
                            </div>
                            <div class="text-secondary mt-0.5">
                                {{ formato_horas_minutos($att->worked_hours) }} trabajadas{{ $att->shift ? ' · turno de ' . formato_horas_minutos($att->shift->daily_hours) : '' }}
                            </div>
                        </td>
                        <td class="text-end">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style="font-size: 0.8rem; font-weight: 700;">
                                +{{ formato_horas_minutos($att->overtime_hours) }}
                            </span>
                            @if($att->overtime_status === 'approved' && (float) $att->overtime_approved_hours < (float) $att->overtime_hours)
                                <div class="small text-secondary fw-normal mt-0.5">{{ formato_horas_minutos($att->overtime_approved_hours) }} aprobadas</div>
                            @endif
                        </td>
                        <td>
                            <x-status-badge type="overtime_status" :value="$att->overtime_status" />
                            @if($att->overtimeReviewer)
                                <div class="small text-secondary mt-1" style="font-size: 0.75rem;">
                                    <i class="bi bi-person-check me-1"></i>{{ $att->overtimeReviewer->full_name }} · {{ $att->overtime_reviewed_at?->format('d/m h:i a') }}
                                </div>
                            @endif
                            @if($att->overtime_review_notes)
                                <div class="appr-review-note">
                                    <i class="bi bi-chat-left-quote me-1"></i>{{ $att->overtime_review_notes }}
                                </div>
                            @endif
                        </td>
                        <td class="pe-4">
                            @if($att->overtime_status === 'pending' && $own)
                                <span class="small text-secondary"><i class="bi bi-info-circle me-1"></i>Las revisa tu jefatura o RRHH.</span>
                            @elseif($att->overtime_status === 'pending')
                                <form method="POST" action="{{ route('approvals.overtime.review', $att->id) }}" class="appr-action-box">
                                    @csrf
                                    <div class="input-group input-group-sm mb-2">
                                        <span class="input-group-text bg-surface text-secondary" style="font-size: 0.775rem;">Aprobar</span>
                                        <input type="number" name="hours" value="{{ (float) $att->overtime_hours }}" min="0.25" max="{{ (float) $att->overtime_hours }}" step="0.25" class="form-control" aria-label="Horas a aprobar">
                                        <span class="input-group-text bg-surface text-secondary" style="font-size: 0.775rem;">h</span>
                                    </div>
                                    <input name="notes" class="form-control form-control-sm mb-2" placeholder="Comentario (obligatorio si rechazas)" aria-label="Comentario" maxlength="500" style="border-radius: 6px;">
                                    <div class="d-flex gap-2">
                                        <button name="decision" value="approved" class="btn btn-sm btn-success flex-fill d-inline-flex align-items-center justify-content-center shadow-sm" style="border-radius: 6px; font-weight: 600; height: 32px; gap: 5px;">
                                            <i class="bi bi-check-lg"></i>
                                            <span>Aprobar</span>
                                        </button>
                                        <button name="decision" value="rejected" class="btn btn-sm btn-outline-danger flex-fill d-inline-flex align-items-center justify-content-center" style="border-radius: 6px; font-weight: 600; height: 32px; gap: 5px;">
                                            <i class="bi bi-x-lg"></i>
                                            <span>Rechazar</span>
                                        </button>
                                    </div>
                                </form>
                            @else
                                <span class="small text-secondary fst-italic">
                                    <i class="bi bi-check2-all me-1"></i>Procesado
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-5 text-center">
                            <div class="empty-state py-4">
                                <div style="width: 54px; height: 54px; border-radius: 12px; background: var(--bg-surface-elevated); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 1rem; border: 1px solid var(--border-color);">
                                    <i class="bi bi-check2-all"></i>
                                </div>
                                <div class="fw-bold text-body fs-6">No hay horas extra {{ strtolower($statusLabels[$status] ?? '') }}</div>
                                <div class="text-secondary small mt-1" style="max-width: 420px; margin: 0 auto;">No se encontraron horas extra con este estado para el filtro actual.</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @else
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-4">Colaborador</th>
                    <th>Fecha</th>
                    <th>Sucursal</th>
                    <th>Asistencia</th>
                    <th>Justificación</th>
                    <th class="pe-4" style="min-width: 260px;">Acción</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $att)
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-3">
                                @if(!empty($att->user?->avatar_path))
                                    <img src="{{ asset('storage/' . $att->user->avatar_path) }}" alt="{{ $att->user->first_name }}" class="attendance-user-avatar">
                                @else
                                    <span class="attendance-user-initials">
                                        {{ mb_substr($att->user?->first_name ?? 'U', 0, 1) }}{{ mb_substr($att->user?->last_name ?? '', 0, 1) }}
                                    </span>
                                @endif
                                <div class="min-w-0">
                                    <div class="fw-bold text-body text-truncate" style="font-size: 0.875rem;">
                                        {{ $att->user?->full_name }}
                                    </div>
                                    <div class="small text-secondary text-truncate" style="font-size: 0.775rem;">
                                        {{ $att->user?->employee_code ?? '—' }}
                                    </div>
                                    @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])
                                </div>
                            </div>
                        </td>
                        <td class="text-nowrap">
                            <a href="{{ route('attendance.show', $att->id) }}" class="fw-semibold text-decoration-none hover-primary" style="font-size: 0.825rem;">
                                <i class="bi bi-calendar3 text-muted me-1"></i>{{ $att->work_date->format('d/m/Y') }}
                            </a>
                        </td>
                        <td>
                            <span class="small text-body"><i class="bi bi-geo-alt text-muted me-1"></i>{{ $att->branch?->name ?? 'Sin sede' }}</span>
                        </td>
                        <td>
                            @include('partials.attendance-status', ['status' => $att->status])
                            @if($att->late_minutes)
                                <div class="small text-secondary mt-0.5 fw-medium">({{ formato_tardanza($att) }})</div>
                            @endif
                            @if($att->clock_in_at)
                                <div class="small text-muted mt-0.5" style="font-size: 0.75rem;">
                                    <i class="bi bi-clock me-1"></i>Entrada: {{ hora($att->clock_in_at, true) }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="small text-body" style="max-width: 280px; line-height: 1.4;">
                                {{ \Illuminate\Support\Str::limit($att->notes, 140) }}
                            </div>
                            @if($att->justification_file)
                                <div>
                                    <a href="{{ route('attendance.justification-file', $att->id) }}" target="_blank" rel="noopener" class="appr-attachment-link">
                                        <i class="bi bi-paperclip"></i>
                                        <span>Ver sustento</span>
                                    </a>
                                </div>
                            @endif
                        </td>
                        <td class="pe-4">
                            @if($att->justification_status === 'pending')
                                <form method="POST" action="{{ route('attendance.review', $att->id) }}" class="appr-action-box">
                                    @csrf
                                    <input name="review_notes" class="form-control form-control-sm mb-2" placeholder="Comentario" aria-label="Comentario" style="border-radius: 6px;">
                                    <div class="d-flex gap-2">
                                        <button name="justification_status" value="approved" class="btn btn-sm btn-success flex-fill d-inline-flex align-items-center justify-content-center shadow-sm" style="border-radius: 6px; font-weight: 600; height: 32px; gap: 5px;">
                                            <i class="bi bi-check-lg"></i>
                                            <span>Aprobar</span>
                                        </button>
                                        <button name="justification_status" value="rejected" class="btn btn-sm btn-outline-danger flex-fill d-inline-flex align-items-center justify-content-center" style="border-radius: 6px; font-weight: 600; height: 32px; gap: 5px;">
                                            <i class="bi bi-x-lg"></i>
                                            <span>Rechazar</span>
                                        </button>
                                    </div>
                                </form>
                            @else
                                <x-status-badge type="justification_status" :value="$att->justification_status" />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-5 text-center">
                            <div class="empty-state py-4">
                                <div style="width: 54px; height: 54px; border-radius: 12px; background: var(--bg-surface-elevated); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 1rem; border: 1px solid var(--border-color);">
                                    <i class="bi bi-check2-all"></i>
                                </div>
                                <div class="fw-bold text-body fs-6">No hay justificaciones {{ strtolower($statusLabels[$status] ?? '') }}</div>
                                <div class="text-secondary small mt-1" style="max-width: 420px; margin: 0 auto;">No se encontraron justificaciones con este estado para el filtro actual.</div>
                            </div>
                        </td>
                    </tr>
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
                            <li class="page-item disabled" aria-disabled="true">
                                <span class="page-link" aria-label="Anterior">&lsaquo;</span>
                            </li>
                            <li class="page-item active" aria-current="page">
                                <span class="page-link">1</span>
                            </li>
                            <li class="page-item disabled" aria-disabled="true">
                                <span class="page-link" aria-label="Siguiente">&rsaquo;</span>
                            </li>
                        </ul>
                    </nav>
                @endif
                <span class="pagination-page-indicator">
                    Pág. {{ $items->currentPage() }} de {{ $items->lastPage() }}
                </span>
            </div>
        </div>
    @endif
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

{{-- Limpiar la URL inicial al cargar la página si el navegador traía query params --}}
document.addEventListener('DOMContentLoaded', function() {
    window.history.replaceState({}, '', '{{ route("approvals.index") }}');
});
</script>
@endsection
