@extends('layouts.app')

@section('title', 'Aprobaciones')

@section('content')
@php
    $statusLabels = ['pending' => 'Pendientes', 'approved' => 'Aprobadas', 'rejected' => 'Rechazadas', 'cancelled' => 'Canceladas'];
    $singular = ['pending' => 'Pendiente', 'approved' => 'Aprobada', 'rejected' => 'Rechazada', 'cancelled' => 'Cancelada'];
    $tone = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];
@endphp

<div class="page-header">
    <div>
        <h1>Aprobaciones</h1>
        <p>Vacaciones, permisos, licencias médicas y justificaciones de asistencia.</p>
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
    </ul>
    <div class="btn-group btn-group-sm" role="group" aria-label="Filtrar por estado">
        @foreach($statusLabels as $key => $label)
            @continue($tab === 'justifications' && $key === 'cancelled')
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
                        <td><div class="fw-semibold">{{ $leave->employee?->full_name }}</div><div class="small text-body-secondary">{{ $leave->employee?->employee_code }}</div></td>
                        <td>{{ $leave->type_label }}</td>
                        <td class="text-nowrap">{{ $leave->start_date->format('d/m/Y') }} → {{ $leave->end_date->format('d/m/Y') }}
                            @if($leave->start_time)<div class="small text-body-secondary">{{ substr($leave->start_time, 0, 5) }}–{{ substr($leave->end_time, 0, 5) }}</div>@endif
                        </td>
                        <td>{{ rtrim(rtrim((string) $leave->days, '0'), '.') }}</td>
                        <td class="small">{{ $leave->reason }}
                            @if($leave->attachment_path)<div><a href="{{ route('approvals.leave.attachment', $leave->id) }}" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> Ver sustento</a></div>@endif
                        </td>
                        <td>
                            @include('partials.badge', ['tone' => $tone[$leave->status] ?? 'secondary', 'label' => $singular[$leave->status] ?? $leave->status])
                            @if($leave->reviewer)<div class="small text-body-secondary">{{ $leave->reviewer->full_name }} · {{ $leave->reviewed_at?->format('d/m H:i') }}</div>@endif
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
    @else
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Colaborador</th><th>Fecha</th><th>Sucursal</th><th>Asistencia</th><th>Justificación</th><th style="min-width: 260px;">Acción</th></tr>
            </thead>
            <tbody>
                @forelse($items as $att)
                    <tr>
                        <td><div class="fw-semibold">{{ $att->user?->full_name }}</div><div class="small text-body-secondary">{{ $att->user?->employee_code }}</div></td>
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
                                @include('partials.badge', ['tone' => $tone[$att->justification_status] ?? 'secondary', 'label' => $singular[$att->justification_status] ?? $att->justification_status])
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
