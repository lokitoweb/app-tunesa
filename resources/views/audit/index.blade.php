@extends('layouts.app')

@section('title', 'Bitácora de auditoría')

@section('styles')
<style>
    .audit-diff td.changed-old { background: var(--bs-danger-bg-subtle); }
    .audit-diff td.changed-new { background: var(--bs-success-bg-subtle); font-weight: 500; }
    .audit-diff td { vertical-align: top; word-break: break-word; }
</style>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1>Bitácora de auditoría</h1>
        <p>Acciones sensibles: documentos abiertos, cuentas bancarias reveladas, planillas exportadas y cambios de configuración.</p>
    </div>
</div>

<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('audit.index') }}" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label for="from" class="form-label small">Desde</label>
                <input type="date" id="from" name="from" value="{{ request('from') }}" class="form-control">
            </div>
            <div class="col-6 col-md-2">
                <label for="to" class="form-label small">Hasta</label>
                <input type="date" id="to" name="to" value="{{ request('to') }}" class="form-control">
            </div>
            <div class="col-12 col-md-3">
                <label for="action" class="form-label small">Acción</label>
                <select id="action" name="action" class="form-select">
                    <option value="">Todas</option>
                    @foreach($actions as $action => $actionLabel)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ $actionLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label for="user_id" class="form-label small">Usuario</label>
                <select id="user_id" name="user_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->first_name }} {{ $u->last_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            </div>
        </form>
    </div>
</div>

@php
    $emptyText = 'No hay registros de auditoría' . (request()->hasAny(['from', 'to', 'action', 'user_id']) ? ' con esos filtros' : '') . '.';
@endphp

{{-- Escritorio: tabla --}}
<div class="card border-0 d-none d-md-block">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Acción</th>
                    <th>Registro</th>
                    <th>Detalle</th>
                    <th>Origen</th>
                </tr>
            </thead>
            <tbody>
                @forelse($presenters as $p)
                    @php $badge = $p->badge(); @endphp
                    <tr>
                        <td class="small text-nowrap">{{ $p->date() }}</td>
                        <td>
                            <div class="fw-semibold small">{{ $p->actorName() }}</div>
                            @if($p->actorRole())<div class="small text-body-secondary">{{ $p->actorRole() }}</div>@endif
                            @include('partials.tenant-badge', ['tenantId' => $p->log->tenant_id])
                        </td>
                        <td>
                            <span class="badge rounded-pill bg-{{ $badge['tone'] }}-subtle text-{{ $badge['tone'] }}-emphasis border border-{{ $badge['tone'] }}-subtle text-wrap text-start">
                                <i class="bi bi-{{ $badge['icon'] }} me-1" aria-hidden="true"></i>{{ $badge['label'] }}</span>
                        </td>
                        <td class="small text-body-secondary">{{ $p->subjectLabel() }}</td>
                        <td class="small" style="min-width: 260px;">
                            @include('audit.partials.detail', ['p' => $p, 'modalId' => 'auditChanges' . $loop->index])
                        </td>
                        <td class="small text-body-secondary">
                            <div>{{ $p->device() }}</div>
                            <div class="font-monospace" title="{{ $p->canSeeIp() ? 'Dirección IP' : 'Dirección IP parcial' }}">{{ $p->ip() }}</div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-journal-text"></i>{{ $emptyText }}</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())
        <div class="card-footer bg-transparent">{{ $logs->links() }}</div>
    @endif
</div>

{{-- Móvil: tarjetas --}}
<div class="d-md-none">
    @forelse($presenters as $p)
        @php $badge = $p->badge(); @endphp
        <div class="card border-0 mb-2">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <span class="badge rounded-pill bg-{{ $badge['tone'] }}-subtle text-{{ $badge['tone'] }}-emphasis border border-{{ $badge['tone'] }}-subtle text-wrap text-start">
                        <i class="bi bi-{{ $badge['icon'] }} me-1" aria-hidden="true"></i>{{ $badge['label'] }}</span>
                    <span class="small text-body-secondary text-nowrap">{{ $p->date() }}</span>
                </div>
                <div class="small mb-2">
                    <span class="fw-semibold">{{ $p->actorName() }}</span>
                    @if($p->actorRole())<span class="text-body-secondary"> · {{ $p->actorRole() }}</span>@endif
                    @include('partials.tenant-badge', ['tenantId' => $p->log->tenant_id])
                </div>
                <div class="small">
                    @include('audit.partials.detail', ['p' => $p, 'modalId' => 'auditChanges' . $loop->index])
                </div>
                <div class="small text-body-secondary mt-2">
                    <i class="bi bi-phone me-1" aria-hidden="true"></i>{{ $p->device() }} · <span class="font-monospace">{{ $p->ip() }}</span>
                </div>
            </div>
        </div>
    @empty
        <div class="card border-0"><div class="card-body"><div class="empty-state"><i class="bi bi-journal-text"></i>{{ $emptyText }}</div></div></div>
    @endforelse
    @if($logs->hasPages())
        <div class="mt-3">{{ $logs->links() }}</div>
    @endif
</div>

@foreach($presenters as $p)
    @if($p->hasChanges())
        @include('audit.partials.changes-modal', ['p' => $p, 'modalId' => 'auditChanges' . $loop->index])
    @endif
@endforeach
@endsection
