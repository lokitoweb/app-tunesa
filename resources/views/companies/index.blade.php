@extends('layouts.app')

@section('title', 'Empresas')

@section('content')
@php use App\Http\Controllers\Web\CompanyWebController as C; @endphp
<div class="page-header">
    <div>
        <h1>Empresas</h1>
        <p>Empresas clientes de la plataforma, su plan, estado y marca blanca.</p>
    </div>
    <a href="{{ route('companies.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nueva empresa</a>
</div>

<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('companies.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label for="search" class="form-label small">Buscar</label>
                <input type="search" id="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Nombre, subdominio o RUC">
            </div>
            <div class="col-6 col-md-3">
                <label for="status" class="form-label small">Estado</label>
                <select id="status" name="status" class="form-select">
                    <option value="">Todos</option>
                    @foreach(C::STATUSES + ['cancelled' => 'Dada de baja'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 d-grid">
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
                    <th>Empresa</th>
                    <th>Subdominio / dominio</th>
                    <th>Plan</th>
                    <th class="text-end">Usuarios</th>
                    <th>Estado</th>
                    <th>Alta</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($companies as $c)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                @if($c->logo_path)
                                    <img src="{{ asset('storage/' . $c->logo_path) }}" alt="" class="brand-logo">
                                @else
                                    <span class="brand-mark" style="background: {{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c->primary_color) ? $c->primary_color : '#1E40AF' }};"><i class="bi bi-buildings"></i></span>
                                @endif
                                <div>
                                    <div class="fw-semibold">{{ $c->company_name }}</div>
                                    <div class="small text-body-secondary">{{ $c->ruc ? 'RUC ' . $c->ruc . ' · ' : '' }}{{ $c->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="small">
                            <span class="font-monospace">{{ $c->slug }}.{{ config('app.domain', 'tunesa.app') }}</span>
                            @if($c->custom_domain)<div class="text-body-secondary font-monospace">{{ $c->custom_domain }}</div>@endif
                        </td>
                        <td>{{ C::PLANS[$c->plan] ?? $c->plan }}</td>
                        <td class="text-end">{{ $c->users_count }}</td>
                        <td>
                            @include('partials.badge', [
                                'tone'  => match ($c->status) { 'active' => 'success', 'suspended' => 'warning', default => 'secondary' },
                                'label' => C::STATUSES[$c->status] ?? 'Dada de baja',
                            ])
                        </td>
                        <td class="small text-body-secondary text-nowrap">{{ $c->created_at?->format('d/m/Y') }}</td>
                        <td class="text-end">
                            <a href="{{ route('companies.edit', $c->id) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Editar</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-buildings"></i>No hay empresas{{ request()->hasAny(['search', 'status']) ? ' con esos filtros' : '' }}.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($companies->hasPages())
        <div class="card-footer bg-transparent">{{ $companies->links() }}</div>
    @endif
</div>
@endsection
