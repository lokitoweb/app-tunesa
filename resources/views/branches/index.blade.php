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
                            @include('partials.tenant-badge', ['tenantId' => $b->tenant_id])
                        </div>
                        <x-status-badge type="branch_status" :value="$b->is_active" />
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
                    <div class="d-flex gap-1">
                        <a href="{{ route('branches.edit', $b->id) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Editar geocerca</a>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteBranchModal"
                                data-action="{{ route('branches.destroy', $b->id) }}" data-name="{{ $b->name }}" data-employees="{{ $b->employees_count }}" aria-label="Eliminar {{ $b->name }}">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="card border-0"><div class="empty-state"><i class="bi bi-pin-map"></i>No hay sucursales. Crea la primera para definir su geocerca.</div></div>
        </div>
    @endforelse
</div>

{{-- Confirmación de borrado --}}
<div class="modal fade" id="deleteBranchModal" tabindex="-1" aria-labelledby="deleteBranchTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content" id="deleteBranchForm">
            @csrf @method('DELETE')
            <div class="modal-header">
                <h2 class="modal-title fs-6" id="deleteBranchTitle">Eliminar sucursal</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">¿Eliminar <strong id="deleteBranchName"></strong>? Su geocerca dejará de usarse para marcar.</p>
                <div class="alert alert-warning small mb-0 d-none" id="deleteBranchWarning">
                    <i class="bi bi-exclamation-triangle me-1"></i>Tiene colaboradores activos asignados: reasígnalos antes de eliminarla.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Eliminar</button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.getElementById('deleteBranchModal').addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        document.getElementById('deleteBranchForm').action = btn.getAttribute('data-action');
        document.getElementById('deleteBranchName').textContent = btn.getAttribute('data-name');
        document.getElementById('deleteBranchWarning').classList.toggle('d-none', btn.getAttribute('data-employees') === '0');
    });
</script>
@endsection
