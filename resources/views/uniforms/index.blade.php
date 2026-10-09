@extends('layouts.app')

@section('title', 'Dotación y tallas')

@section('content')
@php use App\Models\UniformDelivery; @endphp

<div class="page-header">
    <div>
        <h1>Dotación y tallas</h1>
        <p>Tallas que elige el personal desde la app y entregas de uniforme. Exporta el consolidado para logística.</p>
    </div>
    <a href="{{ route('uniforms.export', array_filter(['branch_id' => $branchId])) }}" class="btn btn-primary"><i class="bi bi-download me-1"></i>Exportar tallas (CSV)</a>
</div>

<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-sm-6 col-lg-4">
                <label for="branch_id" class="visually-hidden">Sucursal</label>
                <select id="branch_id" name="branch_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Todas las sucursales</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-8 small text-body-secondary">
                {{ $summary['total'] }} colaboradores ·
                <strong class="{{ $summary['missing'] ? 'text-warning-emphasis' : 'text-success' }}">{{ $summary['missing'] }}</strong> sin tallas completas
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    @foreach(['shirt' => 'Polo / camisa', 'pants' => 'Pantalón', 'shoes' => 'Calzado'] as $key => $label)
        <div class="col-12 col-md-4">
            <div class="card border-0 h-100">
                <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">{{ $label }}</h2></div>
                <div class="card-body pt-0">
                    <div class="d-flex flex-wrap gap-2">
                        @forelse($summary[$key] as $size => $qty)
                            <div class="border rounded px-3 py-2 text-center">
                                <div class="fw-bold">{{ $qty }}</div>
                                <div class="small text-body-secondary">talla {{ $size }}</div>
                            </div>
                        @empty
                            <span class="small text-body-secondary">Sin datos</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="card border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Colaborador</th><th>Polo</th><th>Pantalón</th><th>Calzado</th><th>Última entrega</th></tr></thead>
                    <tbody>
                    @forelse($rows as $r)
                        <tr>
                            <td>
                                <a href="{{ route('employees.file', $r['id']) }}" class="fw-semibold text-decoration-none">{{ $r['full_name'] }}</a>
                                @include('partials.tenant-badge', ['tenantId' => $r['tenant_id']])
                                <div class="small text-body-secondary">{{ $r['employee_code'] }} · {{ $r['branch'] ?: 'Sin sucursal' }}</div>
                            </td>
                            <td>{{ $r['shirt_size'] ?? '—' }}</td>
                            <td>{{ $r['pants_size'] ?? '—' }}</td>
                            <td>{{ $r['shoe_size'] ?? '—' }} <span class="small text-body-secondary">{{ $r['shoe_type'] }}</span></td>
                            <td class="small text-body-secondary">{{ $r['last_delivery'] ? \Carbon\Carbon::parse($r['last_delivery'])->format('d/m/Y') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="empty-state">No hay colaboradores activos.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card border-0 mb-3">
            <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Registrar entrega</h2></div>
            <div class="card-body">
                <form method="POST" action="{{ route('uniforms.deliveries.store') }}" class="row g-2">
                    @csrf
                    <div class="col-12">
                        <label for="user_id" class="form-label small">Colaborador</label>
                        <select id="user_id" name="user_id" class="form-select" required>
                            <option value="">Elige un colaborador</option>
                            @foreach($rows as $r)<option value="{{ $r['id'] }}">{{ $r['full_name'] }} ({{ $r['shirt_size'] ?? '?' }}/{{ $r['pants_size'] ?? '?' }}/{{ $r['shoe_size'] ?? '?' }})</option>@endforeach
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label for="item" class="form-label small">Prenda</label>
                        <select id="item" name="item" class="form-select">@foreach(UniformDelivery::ITEMS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                    </div>
                    <div class="col-6 col-sm-3">
                        <label for="size" class="form-label small">Talla</label>
                        <input id="size" name="size" class="form-control" maxlength="10">
                    </div>
                    <div class="col-6 col-sm-3">
                        <label for="quantity" class="form-label small">Cantidad</label>
                        <input type="number" id="quantity" name="quantity" class="form-control" value="1" min="1" max="50">
                    </div>
                    <div class="col-sm-6">
                        <label for="delivered_at" class="form-label small">Fecha</label>
                        <input type="date" id="delivered_at" name="delivered_at" class="form-control" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}">
                    </div>
                    <div class="col-sm-6">
                        <label for="notes" class="form-label small">Observaciones</label>
                        <input id="notes" name="notes" class="form-control" maxlength="255" placeholder="Opcional">
                    </div>
                    <div class="col-12 d-grid mt-3">
                        <button class="btn btn-primary">Registrar entrega</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card border-0">
            <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Últimas entregas</h2></div>
            <ul class="list-group list-group-flush">
                @forelse($recent as $d)
                    <li class="list-group-item d-flex justify-content-between small">
                        <span>{{ $d->user?->full_name }}: {{ $d->quantity }} × {{ UniformDelivery::ITEMS[$d->item] }} {{ $d->size }} @include('partials.tenant-badge', ['tenantId' => $d->tenant_id])</span>
                        <span class="text-body-secondary">{{ $d->delivered_at->format('d/m/Y') }}</span>
                    </li>
                @empty
                    <li class="list-group-item small text-body-secondary">Aún no hay entregas.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
