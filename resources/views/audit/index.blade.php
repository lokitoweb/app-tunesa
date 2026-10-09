@extends('layouts.app')

@section('title', 'Bitácora de auditoría')

@section('content')
@php use App\Http\Controllers\Web\AuditLogWebController as A; @endphp
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
                    @foreach($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ A::actionLabel($action) }}</option>
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

<div class="card border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Acción</th>
                    <th>Registro</th>
                    <th>Detalle</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td class="small text-nowrap">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                        <td>
                            @if($log->user)
                                <div class="fw-semibold small">{{ $log->user->first_name }} {{ $log->user->last_name }}</div>
                                <div class="small text-body-secondary text-capitalize">{{ str_replace('_', ' ', $log->user->role) }}</div>
                            @else
                                <span class="small text-body-secondary">Sistema</span>
                            @endif
                            @include('partials.tenant-badge', ['tenantId' => $log->tenant_id])
                        </td>
                        <td>
                            <div class="small fw-medium">{{ A::actionLabel($log->action) }}</div>
                            <code class="small text-body-secondary">{{ $log->action }}</code>
                        </td>
                        <td class="small text-body-secondary">{{ $log->subject_type ? $log->subject_type . ' #' . $log->subject_id : '—' }}</td>
                        <td class="small">
                            @php $pairs = A::metaPairs($log->meta); @endphp
                            @if($pairs)
                                <dl class="kv small mb-0">
                                    @foreach($pairs as $key => $value)
                                        <dt>{{ $key }}</dt><dd>{{ \Illuminate\Support\Str::limit($value, 120) }}</dd>
                                    @endforeach
                                </dl>
                            @else
                                <span class="text-body-secondary">—</span>
                            @endif
                        </td>
                        <td class="small text-body-secondary font-monospace">{{ $log->ip ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-journal-text"></i>No hay registros de auditoría{{ request()->hasAny(['from', 'to', 'action', 'user_id']) ? ' con esos filtros' : '' }}.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())
        <div class="card-footer bg-transparent">{{ $logs->links() }}</div>
    @endif
</div>
@endsection
