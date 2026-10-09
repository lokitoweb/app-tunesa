@extends('layouts.app')

@section('title', 'Sucursales y geocercas')

@section('content')
<div class="page-header">
    <div>
        <h1>Sucursales y geocercas</h1>
        <p>Ubicación de cada sede u obra y el radio permitido para validar el GPS de la app.</p>
    </div>
    <a href="{{ route('branches.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nueva sucursal</a>
</div>

<div class="row g-3">
    @forelse($branches as $b)
        <div class="col-12 col-md-6 col-xxl-4">
            <div class="card border-0 h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h2 class="h6 fw-semibold mb-0">{{ $b->name }}</h2>
                            @if($b->code)<span class="small text-body-secondary">{{ $b->code }}</span>@endif
                        </div>
                        @include('partials.badge', ['tone' => $b->is_active ? 'success' : 'secondary', 'label' => $b->is_active ? 'Activa' : 'Inactiva'])
                    </div>
                    <p class="small text-body-secondary mb-3">
                        <i class="bi bi-geo-alt"></i> {{ $b->address }}
                        @if($b->city)<br>{{ $b->city }}, {{ $b->country ?? 'Perú' }}@endif
                    </p>
                    <dl class="kv small mb-0">
                        <dt>Radio permitido</dt><dd class="fw-semibold text-success">{{ $b->allowed_radius_meters }} m</dd>
                        <dt>Colaboradores</dt><dd>{{ $b->employees_count }}</dd>
                        <dt>Supervisor</dt><dd>{{ $b->supervisor ? $b->supervisor->first_name . ' ' . $b->supervisor->last_name : 'Sin asignar' }}</dd>
                    </dl>
                </div>
                <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                    <span class="small text-body-secondary font-monospace">{{ number_format((float) $b->latitude, 4) }}, {{ number_format((float) $b->longitude, 4) }}</span>
                    <a href="{{ route('branches.edit', $b->id) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Editar geocerca</a>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="card border-0"><div class="empty-state"><i class="bi bi-pin-map"></i>No hay sucursales. Crea la primera para definir su geocerca.</div></div>
        </div>
    @endforelse
</div>
@endsection
