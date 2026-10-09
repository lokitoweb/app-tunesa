@extends('layouts.app')

@section('title', 'Aprobaciones')

@section('content')
@php
    $statusLabels = ['pending' => 'Pendientes', 'approved' => 'Aprobadas', 'rejected' => 'Rechazadas', 'cancelled' => 'Canceladas'];
    $badge = ['pending' => 'badge-pending', 'approved' => 'badge-present', 'rejected' => 'badge-absent', 'cancelled' => 'badge-draft'];
@endphp

<div class="page-head">
    <div>
        <h1>Aprobaciones</h1>
        <p>Vacaciones, permisos, licencias médicas y justificaciones. Flujo: pendiente → aprobado o rechazado.</p>
    </div>
</div>

@if ($errors->any())
    <div class="card" style="margin-bottom: 1rem; border-color: rgba(239,68,68,0.3);">
        <div class="card-body" style="color: #fca5a5;">{{ $errors->first() }}</div>
    </div>
@endif

<div class="tabs">
    <a href="{{ route('approvals.index', ['tab' => 'leave', 'status' => $status]) }}" class="tab {{ $tab === 'leave' ? 'active' : '' }}">
        Solicitudes @if($counts['leave'])({{ $counts['leave'] }})@endif
    </a>
    @can('attendance.review')
    <a href="{{ route('approvals.index', ['tab' => 'justifications', 'status' => $status === 'cancelled' ? 'pending' : $status]) }}" class="tab {{ $tab === 'justifications' ? 'active' : '' }}">
        Justificaciones @if($counts['justifications'])({{ $counts['justifications'] }})@endif
    </a>
    @endcan
    <span style="flex: 1"></span>
    @foreach($statusLabels as $key => $label)
        @continue($tab === 'justifications' && $key === 'cancelled')
        <a href="{{ route('approvals.index', ['tab' => $tab, 'status' => $key]) }}" class="tab {{ $status === $key ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="card">
    @if($tab === 'leave')
        <table class="attendance-live-table">
            <thead>
                <tr><th>Colaborador</th><th>Tipo</th><th>Fechas</th><th>Días</th><th>Motivo</th><th>Estado</th><th style="min-width: 260px;">Acción</th></tr>
            </thead>
            <tbody>
                @forelse($items as $leave)
                    <tr>
                        <td><strong style="color:#fff">{{ $leave->employee?->full_name }}</strong><br><span class="text-muted">{{ $leave->employee?->employee_code }}</span></td>
                        <td>{{ $leave->type_label }}</td>
                        <td>{{ $leave->start_date->format('d/m/Y') }} → {{ $leave->end_date->format('d/m/Y') }}
                            @if($leave->start_time)<br><span class="text-muted">{{ substr($leave->start_time, 0, 5) }}–{{ substr($leave->end_time, 0, 5) }}</span>@endif
                        </td>
                        <td>{{ rtrim(rtrim((string) $leave->days, '0'), '.') }}</td>
                        <td>{{ $leave->reason }}
                            @if($leave->attachment_path)<br><a href="{{ route('approvals.leave.attachment', $leave->id) }}" target="_blank" rel="noopener">Ver sustento</a>@endif
                        </td>
                        <td><span class="badge {{ $badge[$leave->status] ?? '' }}">{{ $statusLabels[$leave->status] ?? $leave->status }}</span>
                            @if($leave->reviewer)<br><span class="text-muted" style="font-size: 12px;">{{ $leave->reviewer->full_name }} · {{ $leave->reviewed_at?->format('d/m H:i') }}</span>@endif
                            @if($leave->review_notes)<br><span class="text-muted" style="font-size: 12px;">{{ $leave->review_notes }}</span>@endif
                        </td>
                        <td>
                            @if($leave->isPending())
                                <form method="POST" action="{{ route('approvals.leave.review', $leave->id) }}" style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    @csrf
                                    <input name="notes" class="form-control form-control-sm" placeholder="Comentario (obligatorio si rechazas)" style="flex: 1 1 100%;">
                                    <button name="decision" value="approved" class="btn btn-success btn-sm">Aprobar</button>
                                    <button name="decision" value="rejected" class="btn btn-danger btn-sm">Rechazar</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">No hay solicitudes {{ strtolower($statusLabels[$status]) }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <table class="attendance-live-table">
            <thead>
                <tr><th>Colaborador</th><th>Fecha</th><th>Sucursal</th><th>Estado asistencia</th><th>Justificación</th><th style="min-width: 260px;">Acción</th></tr>
            </thead>
            <tbody>
                @forelse($items as $att)
                    <tr>
                        <td><strong style="color:#fff">{{ $att->user?->full_name }}</strong><br><span class="text-muted">{{ $att->user?->employee_code }}</span></td>
                        <td><a href="{{ route('attendance.show', $att->id) }}">{{ $att->work_date->format('d/m/Y') }}</a></td>
                        <td>{{ $att->branch?->name }}</td>
                        <td>{{ $att->status }} @if($att->late_minutes)<span class="text-muted">({{ $att->late_minutes }} min)</span>@endif</td>
                        <td>{{ \Illuminate\Support\Str::limit($att->notes, 140) }}
                            @if($att->justification_file)<br><a href="{{ route('attendance.justification-file', $att->id) }}" target="_blank" rel="noopener">Ver sustento</a>@endif
                        </td>
                        <td>
                            @if($att->justification_status === 'pending')
                                <form method="POST" action="{{ route('attendance.review', $att->id) }}" style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    @csrf
                                    <input name="review_notes" class="form-control form-control-sm" placeholder="Comentario" style="flex: 1 1 100%;">
                                    <button name="justification_status" value="approved" class="btn btn-success btn-sm">Aprobar</button>
                                    <button name="justification_status" value="rejected" class="btn btn-danger btn-sm">Rechazar</button>
                                </form>
                            @else
                                <span class="badge {{ $badge[$att->justification_status] ?? '' }}">{{ $statusLabels[$att->justification_status] ?? $att->justification_status }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted">No hay justificaciones {{ strtolower($statusLabels[$status]) }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
    <div class="card-body">{{ $items->links() }}</div>
</div>
@endsection
