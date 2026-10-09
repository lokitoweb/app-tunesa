@extends('layouts.app')

@section('title', 'Sucursales & Geocercas')

@section('content')
<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Sucursales, Obras & Geocercas</h1>
        <p style="color: var(--text-secondary); font-size: 0.9rem;">Configura las ubicaciones físicas y el radio métrico permitido para validar el GPS de la app móvil.</p>
    </div>
    <div>
        <a href="{{ route('branches.create') }}" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: #fff; text-decoration: none; padding: 0.65rem 1.25rem; border-radius: 10px; font-weight: 600; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 15px rgba(99,102,241,0.35);">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Nueva Sucursal / Obra
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem;">
    @forelse($branches as $b)
        <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem; position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">
                    <div>
                        <h3 style="color: #fff; font-size: 1.15rem; font-weight: 700;">{{ $b->name }}</h3>
                        <span style="font-size: 0.75rem; color: #818cf8; font-weight: 600;">{{ $b->code }}</span>
                        @include('partials.tenant-badge', ['tenantId' => $b->tenant_id])
                    </div>
                    <span style="padding: 3px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 600; {{ $b->is_active ? 'background: rgba(16,185,129,0.15); color: #10b981;' : 'background: rgba(239,68,68,0.15); color: #ef4444;' }}">
                        {{ $b->is_active ? 'Activa' : 'Inactiva' }}
                    </span>
                </div>

                <div style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 1rem; line-height: 1.5;">
                    📍 {{ $b->address }}<br>
                    @if($b->city) <span style="font-size: 0.8rem; color: #64748b;">{{ $b->city }}, {{ $b->country ?? 'Perú' }}</span> @endif
                </div>

                <!-- DETALLES DE GEOCERCA -->
                <div style="background: #1a1c26; border-radius: 10px; padding: 0.85rem; margin-bottom: 1.25rem; font-size: 0.82rem; border: 1px solid rgba(255,255,255,0.04);">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span style="color: #94a3b8;">Radio Permitido:</span>
                        <strong style="color: #10b981; font-size: 0.9rem;">{{ $b->allowed_radius_meters }} metros</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span style="color: #94a3b8;">Colaboradores:</span>
                        <strong style="color: #fff;">{{ $b->employees_count }} asignados</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #94a3b8;">Supervisor:</span>
                        <span style="color: #cbd5e1;">{{ $b->supervisor ? $b->supervisor->first_name . ' ' . $b->supervisor->last_name : 'Sin asignar' }}</span>
                    </div>
                </div>
            </div>

            <!-- ACCIONES -->
            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); padding-top: 1rem;">
                <span style="font-size: 0.75rem; color: #64748b; font-family: monospace;">
                    {{ number_format((float)$b->latitude, 4) }}, {{ number_format((float)$b->longitude, 4) }}
                </span>
                <div style="display: flex; gap: 8px;">
                    <a href="{{ route('branches.edit', $b->id) }}" style="padding: 6px 12px; background: rgba(99,102,241,0.15); color: #818cf8; text-decoration: none; border-radius: 6px; font-size: 0.8rem; font-weight: 600;">
                        Editar Geocerca
                    </a>
                </div>
            </div>
        </div>
    @empty
        <div style="grid-column: 1 / -1; padding: 4rem; text-align: center; color: #64748b; background: var(--bg-surface); border-radius: var(--radius-lg); border: 1px solid var(--border);">
            No hay sucursales creadas. Haz clic en <strong>Nueva Sucursal</strong> para definir tu primera geocerca GPS.
        </div>
    @endforelse
</div>
@endsection
