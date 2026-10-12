@extends('layouts.app')

@section('title', 'Incorporación')

@section('content')
<div class="page-header">
    <div>
        <h1>Incorporación</h1>
        <p>Nadie marca asistencia hasta que apruebes su incorporación: datos, tallas, documentos e inducción.</p>
    </div>
    @can('onboarding.manage')
        <a href="{{ route('onboarding.settings') }}" class="btn btn-outline-secondary"><i class="bi bi-sliders me-1"></i>Configurar etapas</a>
    @endcan
</div>

<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto" role="tablist">
    @foreach(['submitted' => 'Por revisar', 'pending' => 'En proceso', 'rejected' => 'Observadas', 'approved' => 'Aprobadas', 'all' => 'Todas'] as $key => $text)
        <li class="nav-item">
            <a class="nav-link text-nowrap {{ $status === $key ? 'active' : '' }}" href="{{ route('onboarding.index', ['status' => $key] + request()->only('search')) }}">
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
        <form method="GET" action="{{ route('onboarding.index') }}" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="col-12 col-md-6">
                <label for="search" class="form-label small">Buscar</label>
                <input type="search" id="search" name="search" value="{{ request('search') }}" placeholder="Nombre o DNI" class="form-control">
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
                    <th>Colaborador</th>
                    <th>Etapas</th>
                    <th>Estado</th>
                    <th>Enviada</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($processes as $p)
                    @php $evaluated = collect($stages[$p->id] ?? [])->where('status', '!=', 'not_applicable'); @endphp
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $p->user->full_name }}</div>
                            <div class="small text-body-secondary">{{ label('id_document_type', $p->user->document_type) }} {{ $p->user->document_number }} · {{ $p->user->branches->firstWhere('pivot.is_primary', true)?->name ?? 'Sin sucursal' }}</div>
                            @include('partials.tenant-badge', ['tenantId' => $p->tenant_id])
                        </td>
                        <td>
                            @if($p->isApproved())
                                <span class="small text-body-secondary">Completas</span>
                            @else
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach($evaluated as $s)
                                        <span class="badge rounded-pill {{ $s['status'] === 'completed' ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis' }}" title="{{ $s['status'] === 'completed' ? 'Completa' : 'Falta: ' . implode(', ', $s['missing']) }}">
                                            <i class="bi {{ $s['status'] === 'completed' ? 'bi-check-circle' : 'bi-hourglass-split' }} me-1"></i>{{ $s['title'] }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td><x-status-badge type="onboarding_status" :value="$p->status" /></td>
                        <td class="small text-nowrap">{{ $p->submitted_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="text-end">
                            <a href="{{ route('onboarding.show', $p->user_id) }}" class="btn btn-sm {{ $p->status === 'submitted' ? 'btn-primary' : 'btn-outline-secondary' }}">
                                {{ $p->status === 'submitted' ? 'Revisar' : 'Ver' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><i class="bi bi-person-check"></i>No hay incorporaciones en esta bandeja.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($processes->hasPages())
        <div class="card-footer bg-transparent">{{ $processes->links() }}</div>
    @endif
</div>
@endsection
