@extends('layouts.app')

@section('title', 'Aprobaciones')

@section('content')
@php
    $statusLabels = ['pending' => 'Pendientes', 'approved' => 'Aprobadas', 'rejected' => 'Rechazadas', 'cancelled' => 'Canceladas'];
@endphp

<div class="page-header">
    <div>
        <h1>Aprobaciones</h1>
        <p>Vacaciones, permisos, licencias médicas, justificaciones de asistencia y horas extra.</p>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-tabs border-0">
        <li class="nav-item">
            <a href="{{ route('approvals.index', ['tab' => 'leave', 'status' => $status]) }}" class="nav-link {{ $tab === 'leave' ? 'active' : '' }}" @if($tab === 'leave') aria-current="page" @endif>
                Solicitudes @if($counts['leave'])<span class="badge rounded-pill text-bg-danger">{{ $counts['leave'] }}</span>@endif
            </a>
        </li>
        @can('attendance.review')
        <li class="nav-item">
            <a href="{{ route('approvals.index', ['tab' => 'justifications', 'status' => $status === 'cancelled' ? 'pending' : $status]) }}" class="nav-link {{ $tab === 'justifications' ? 'active' : '' }}" @if($tab === 'justifications') aria-current="page" @endif>
                Justificaciones @if($counts['justifications'])<span class="badge rounded-pill text-bg-danger">{{ $counts['justifications'] }}</span>@endif
            </a>
        </li>
        @endcan
        <li class="nav-item">
            <a href="{{ route('approvals.index', ['tab' => 'overtime', 'status' => $status === 'cancelled' ? 'pending' : $status]) }}" class="nav-link {{ $tab === 'overtime' ? 'active' : '' }}" @if($tab === 'overtime') aria-current="page" @endif>
                Horas extra @if($counts['overtime'])<span class="badge rounded-pill text-bg-danger">{{ $counts['overtime'] }}</span>@endif
            </a>
        </li>
    </ul>
    <div class="btn-group btn-group-sm" role="group" aria-label="Filtrar por estado">
        @foreach($statusLabels as $key => $label)
            @continue(in_array($tab, ['justifications', 'overtime'], true) && $key === 'cancelled')
            <a href="{{ route('approvals.index', ['tab' => $tab, 'status' => $key]) }}" class="btn {{ $status === $key ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>

<div class="card border-0">
    <div class="table-responsive">
    @if($tab === 'leave')
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Colaborador</th><th>Tipo</th><th>Fechas</th><th>Días</th><th>Motivo</th><th>Estado</th><th style="min-width: 260px;">Acción</th></tr>
            </thead>
            <tbody>
                @forelse($items as $leave)
                    <tr>
                        <td><div class="fw-semibold">{{ $leave->employee?->full_name }}</div><div class="small text-body-secondary">{{ $leave->employee?->employee_code }}</div> @include('partials.tenant-badge', ['tenantId' => $leave->tenant_id])</td>
                        <td>{{ $leave->type_label }}</td>
                        <td class="text-nowrap">{{ $leave->start_date->format('d/m/Y') }} → {{ $leave->end_date->format('d/m/Y') }}
                            @if($leave->start_time)<div class="small text-body-secondary">{{ hora($leave->start_time) }}–{{ hora($leave->end_time) }}</div>@endif
                        </td>
                        <td>{{ rtrim(rtrim((string) $leave->days, '0'), '.') }}</td>
                        <td class="small">{{ $leave->reason }}
                            @if($leave->attachment_path)<div><a href="{{ route('approvals.leave.attachment', $leave->id) }}" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> Ver sustento</a></div>@endif
                        </td>
                        <td>
                            <x-status-badge type="leave_status" :value="$leave->status" />
                            @if($leave->reviewer)<div class="small text-body-secondary">{{ $leave->reviewer->full_name }} · {{ $leave->reviewed_at?->format('d/m h:i a') }}</div>@endif
                            @if($leave->review_notes)<div class="small text-body-secondary">{{ $leave->review_notes }}</div>@endif
                        </td>
                        <td>
                            @if($leave->isPending())
                                <form method="POST" action="{{ route('approvals.leave.review', $leave->id) }}">
                                    @csrf
                                    <input name="notes" class="form-control form-control-sm mb-2" placeholder="Comentario (obligatorio si rechazas)" aria-label="Comentario">
                                    <div class="d-flex gap-2">
                                        <button name="decision" value="approved" class="btn btn-sm btn-success flex-fill">Aprobar</button>
                                        <button name="decision" value="rejected" class="btn btn-sm btn-outline-danger flex-fill">Rechazar</button>
                                    </div>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-check2-all"></i>No hay solicitudes {{ strtolower($statusLabels[$status]) }}.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif($tab === 'overtime')
        @php $hours = fn ($h) => rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.') . ' h'; @endphp
        @if($status === 'pending' && $items->isNotEmpty())
            <form method="POST" action="{{ route('approvals.overtime.bulk') }}" id="overtimeBulk" class="d-flex flex-wrap align-items-center gap-2 p-3 border-bottom">
                @csrf
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="overtimeAll" onclick="document.querySelectorAll('[data-overtime-pick]').forEach((c) => c.checked = this.checked)">
                    <label class="form-check-label small" for="overtimeAll">Marcar todas</label>
                </div>
                <button class="btn btn-sm btn-success ms-auto"><i class="bi bi-check2-all me-1"></i>Aprobar las marcadas</button>
            </form>
        @endif
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>@if($status === 'pending')<th style="width: 2.5rem;"><span class="visually-hidden">Marcar</span></th>@endif<th>Colaborador</th><th>Fecha</th><th>Jornada</th><th class="text-end">Horas extra</th><th>Estado</th><th style="min-width: 280px;">Acción</th></tr>
            </thead>
            <tbody>
                @forelse($items as $att)
                    @php $own = (int) $att->user_id === (int) auth()->id(); @endphp
                    <tr>
                        @if($status === 'pending')
                            <td>@unless($own)<input class="form-check-input" type="checkbox" name="ids[]" value="{{ $att->id }}" form="overtimeBulk" data-overtime-pick aria-label="Marcar a {{ $att->user?->full_name }}">@endunless</td>
                        @endif
                        <td><div class="fw-semibold">{{ $att->user?->full_name }}</div><div class="small text-body-secondary">{{ $att->user?->employee_code }}</div> @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])</td>
                        <td class="text-nowrap"><a href="{{ route('attendance.show', $att->id) }}">{{ $att->work_date->format('d/m/Y') }}</a><div class="small text-body-secondary">{{ $att->branch?->name }}</div></td>
                        <td class="small text-nowrap">{{ $att->clock_in_at?->format('h:i a') }} – {{ $att->clock_out_at?->format('h:i a') }}
                            <div class="text-body-secondary">{{ $hours($att->worked_hours) }} trabajadas{{ $att->shift ? ' · turno de ' . $hours($att->shift->daily_hours) : '' }}</div>
                        </td>
                        <td class="text-end fw-semibold">{{ $hours($att->overtime_hours) }}
                            @if($att->overtime_status === 'approved' && (float) $att->overtime_approved_hours < (float) $att->overtime_hours)
                                <div class="small text-body-secondary fw-normal">{{ $hours($att->overtime_approved_hours) }} aprobadas</div>
                            @endif
                        </td>
                        <td>
                            <x-status-badge type="overtime_status" :value="$att->overtime_status" />
                            @if($att->overtimeReviewer)<div class="small text-body-secondary">{{ $att->overtimeReviewer->full_name }} · {{ $att->overtime_reviewed_at?->format('d/m h:i a') }}</div>@endif
                            @if($att->overtime_review_notes)<div class="small text-body-secondary">{{ $att->overtime_review_notes }}</div>@endif
                        </td>
                        <td>
                            @if($att->overtime_status === 'pending' && $own)
                                <span class="small text-body-secondary">Las revisa tu jefatura o RRHH.</span>
                            @elseif($att->overtime_status === 'pending')
                                <form method="POST" action="{{ route('approvals.overtime.review', $att->id) }}">
                                    @csrf
                                    <div class="input-group input-group-sm mb-2">
                                        <span class="input-group-text">Aprobar</span>
                                        <input type="number" name="hours" value="{{ (float) $att->overtime_hours }}" min="0.25" max="{{ (float) $att->overtime_hours }}" step="0.25" class="form-control" aria-label="Horas a aprobar">
                                        <span class="input-group-text">h</span>
                                    </div>
                                    <input name="notes" class="form-control form-control-sm mb-2" placeholder="Comentario (obligatorio si rechazas)" aria-label="Comentario" maxlength="500">
                                    <div class="d-flex gap-2">
                                        <button name="decision" value="approved" class="btn btn-sm btn-success flex-fill">Aprobar</button>
                                        <button name="decision" value="rejected" class="btn btn-sm btn-outline-danger flex-fill">Rechazar</button>
                                    </div>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-check2-all"></i>No hay horas extra {{ strtolower($statusLabels[$status]) }}.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Colaborador</th><th>Fecha</th><th>Sucursal</th><th>Asistencia</th><th>Justificación</th><th style="min-width: 260px;">Acción</th></tr>
            </thead>
            <tbody>
                @forelse($items as $att)
                    <tr>
                        <td><div class="fw-semibold">{{ $att->user?->full_name }}</div><div class="small text-body-secondary">{{ $att->user?->employee_code }}</div> @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])</td>
                        <td><a href="{{ route('attendance.show', $att->id) }}">{{ $att->work_date->format('d/m/Y') }}</a></td>
                        <td>{{ $att->branch?->name }}</td>
                        <td>@include('partials.attendance-status', ['status' => $att->status]) @if($att->late_minutes)<span class="small text-body-secondary">({{ $att->late_minutes }} min)</span>@endif</td>
                        <td class="small">{{ \Illuminate\Support\Str::limit($att->notes, 140) }}
                            @if($att->justification_file)<div><a href="{{ route('attendance.justification-file', $att->id) }}" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> Ver sustento</a></div>@endif
                        </td>
                        <td>
                            @if($att->justification_status === 'pending')
                                <form method="POST" action="{{ route('attendance.review', $att->id) }}">
                                    @csrf
                                    <input name="review_notes" class="form-control form-control-sm mb-2" placeholder="Comentario" aria-label="Comentario">
                                    <div class="d-flex gap-2">
                                        <button name="justification_status" value="approved" class="btn btn-sm btn-success flex-fill">Aprobar</button>
                                        <button name="justification_status" value="rejected" class="btn btn-sm btn-outline-danger flex-fill">Rechazar</button>
                                    </div>
                                </form>
                            @else
                                <x-status-badge type="justification_status" :value="$att->justification_status" />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-check2-all"></i>No hay justificaciones {{ strtolower($statusLabels[$status]) }}.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
    </div>
    @if($items->hasPages())
        <div class="card-footer bg-transparent">{{ $items->links() }}</div>
    @endif
</div>
@endsection
