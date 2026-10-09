@extends('layouts.app')

@section('title', 'Dotación y tallas')

@section('content')
@php use App\Models\UniformDelivery; @endphp

<div class="page-head">
    <div>
        <h1>Dotación y tallas</h1>
        <p>Tallas que elige el personal desde la app y entregas de uniforme. Exporta el consolidado para logística.</p>
    </div>
    <a href="{{ route('uniforms.export', array_filter(['branch_id' => $branchId])) }}" class="btn btn-primary">⬇ Exportar tallas (CSV)</a>
</div>

@if ($errors->any())
    <div class="card" style="margin-bottom: 1rem; border-color: rgba(239,68,68,0.3);"><div class="card-body" style="color: #fca5a5;">{{ $errors->first() }}</div></div>
@endif

<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
            <select name="branch_id" class="form-control" style="max-width: 260px;" onchange="this.form.submit()">
                <option value="">Todas las sucursales</option>
                @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}</option>@endforeach
            </select>
            <span class="text-muted">{{ $summary['total'] }} colaboradores · <strong style="color: {{ $summary['missing'] ? 'var(--warning)' : 'var(--success)' }}">{{ $summary['missing'] }}</strong> sin tallas completas</span>
        </form>
    </div>
</div>

<div class="grid-3" style="margin-bottom: 1.5rem;">
    @foreach (['shirt' => 'Polo / camisa', 'pants' => 'Pantalón', 'shoes' => 'Calzado'] as $key => $label)
        <div class="card">
            <div class="card-header"><div class="card-title">{{ $label }}</div></div>
            <div class="card-body">
                <div class="size-chips">
                    @forelse ($summary[$key] as $size => $qty)
                        <div class="size-chip"><strong>{{ $qty }}</strong><span>talla {{ $size }}</span></div>
                    @empty
                        <span class="text-muted">Sin datos</span>
                    @endforelse
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="grid-2" style="align-items: start;">
    <div class="card">
        <table class="attendance-live-table">
            <thead><tr><th>Colaborador</th><th>Polo</th><th>Pantalón</th><th>Calzado</th><th>Última entrega</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>
                        <a href="{{ route('employees.file', $r['id']) }}" style="color: #fff; text-decoration: none; font-weight: 600;">{{ $r['full_name'] }}</a>
                        <div class="text-muted" style="font-size: 0.75rem;">{{ $r['employee_code'] }} · {{ $r['branch'] ?: 'Sin sucursal' }}</div>
                    </td>
                    <td>{{ $r['shirt_size'] ?? '—' }}</td>
                    <td>{{ $r['pants_size'] ?? '—' }}</td>
                    <td>{{ $r['shoe_size'] ?? '—' }} <span class="text-muted" style="font-size: 0.75rem;">{{ $r['shoe_type'] }}</span></td>
                    <td class="text-muted">{{ $r['last_delivery'] ? \Carbon\Carbon::parse($r['last_delivery'])->format('d/m/Y') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">No hay colaboradores activos.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div>
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header"><div class="card-title">Registrar entrega</div></div>
            <div class="card-body">
                <form method="POST" action="{{ route('uniforms.deliveries.store') }}" style="display: grid; gap: 10px;">
                    @csrf
                    <select name="user_id" class="form-control" required>
                        <option value="">Colaborador…</option>
                        @foreach ($rows as $r)<option value="{{ $r['id'] }}">{{ $r['full_name'] }} ({{ $r['shirt_size'] ?? '?' }}/{{ $r['pants_size'] ?? '?' }}/{{ $r['shoe_size'] ?? '?' }})</option>@endforeach
                    </select>
                    <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 8px;">
                        <select name="item" class="form-control">@foreach (UniformDelivery::ITEMS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                        <input name="size" class="form-control" placeholder="Talla" maxlength="10">
                        <input type="number" name="quantity" class="form-control" value="1" min="1" max="50">
                    </div>
                    <input type="date" name="delivered_at" class="form-control" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}">
                    <input name="notes" class="form-control" placeholder="Observaciones (opcional)" maxlength="255">
                    <button class="btn btn-primary">Registrar</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title">Últimas entregas</div></div>
            <div class="card-body">
                @forelse ($recent as $d)
                    <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 0.86rem;">
                        <span style="color: #fff;">{{ $d->user?->full_name }}: {{ $d->quantity }}× {{ UniformDelivery::ITEMS[$d->item] }} {{ $d->size }}</span>
                        <span class="text-muted">{{ $d->delivered_at->format('d/m/Y') }}</span>
                    </div>
                @empty
                    <p class="text-muted">Aún no hay entregas.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
