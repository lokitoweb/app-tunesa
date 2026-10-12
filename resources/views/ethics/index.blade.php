@extends('layouts.app')

@section('title', 'Canal ético')

@section('content')
<div class="page-header">
    <div>
        <h1>Canal ético</h1>
        <p>Denuncias de los colaboradores, anónimas para todos; las enviadas desde la app guardan la identidad de forma reservada, visible solo para RR. HH.. Respóndeles por aquí y lo verán con su código.</p>
    </div>
</div>

@php($ethicsTenant = app(\App\Modules\Core\Services\TenantContext::class)->resolved() ? app(\App\Modules\Core\Services\TenantContext::class)->get() : null)
@if($ethicsTenant)
    <div class="alert alert-light border small d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-globe2"></i> También se puede denunciar desde la web, sin iniciar sesión:
        <a href="{{ route('ethics.public', $ethicsTenant->slug) }}" target="_blank" rel="noopener" class="font-monospace text-break">{{ route('ethics.public', $ethicsTenant->slug) }}</a>
    </div>
@endif

<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto" role="tablist">
    @foreach(['open' => 'Abiertas', 'received' => 'Recibidas', 'in_review' => 'En investigación', 'needs_info' => 'Esperan información', 'closed' => 'Cerradas', 'all' => 'Todas'] as $key => $text)
        <li class="nav-item">
            <a class="nav-link text-nowrap {{ $status === $key ? 'active' : '' }}" href="{{ route('ethics.index', ['status' => $key] + request()->only('category', 'code')) }}">
                {{ $text }}
                @if($key !== 'all')
                    <span class="badge rounded-pill {{ $status === $key ? 'text-bg-light' : 'bg-secondary-subtle text-secondary-emphasis' }} ms-1">{{ $counts[$key] ?? 0 }}</span>
                @endif
            </a>
        </li>
    @endforeach
</ul>

<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('ethics.index') }}" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="col-12 col-md-4">
                <label for="code" class="form-label small">Código</label>
                <input type="search" id="code" name="code" value="{{ request('code') }}" placeholder="ET-..." class="form-control">
            </div>
            <div class="col-12 col-md-4">
                <label for="category" class="form-label small">Tipo</label>
                <select id="category" name="category" class="form-select">
                    <option value="">Todos</option>
                    @foreach(\App\Modules\Ethics\Models\EthicsCase::CATEGORIES as $key => $text)
                        <option value="{{ $key }}" @selected(request('category') === $key)>{{ $text }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Buscar</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Recibida</th>
                    <th>A cargo</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($cases as $c)
                    <tr>
                        <td>
                            <span class="fw-semibold font-monospace">{{ $c->public_code }}</span>
                            @if($c->investigator_unread)
                                <span class="badge rounded-pill text-bg-danger ms-1">Nuevo</span>
                            @endif
                        </td>
                        <td>{{ $c->categoryLabel() }}</td>
                        <td><x-status-badge type="ethics_status" :value="$c->status" /></td>
                        <td class="small text-nowrap">{{ $c->reported_on->format('d/m/Y') }}</td>
                        <td class="small">{{ $c->assignee ? $c->assignee->first_name . ' ' . $c->assignee->last_name : 'Sin asignar' }}</td>
                        <td class="text-end">
                            <a href="{{ route('ethics.show', $c->id) }}" class="btn btn-sm {{ $c->investigator_unread ? 'btn-primary' : 'btn-outline-secondary' }}">Abrir</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-shield-check"></i>No hay denuncias en esta bandeja.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($cases->hasPages())
        <div class="card-footer bg-transparent">{{ $cases->links() }}</div>
    @endif
</div>
@endsection
