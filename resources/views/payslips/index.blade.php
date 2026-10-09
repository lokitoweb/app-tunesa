@extends('layouts.app')

@section('title', 'Boletas de Pago & Nómina')

@section('content')
<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Boletas de Pago & Emisión de Nómina</h1>
        <p style="color: var(--text-secondary); font-size: 0.9rem;">Carga de comprobantes PDF firmados y distribución segura hacia la app móvil de cada colaborador.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="{{ route('payslips.create') }}" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: #fff; text-decoration: none; padding: 0.65rem 1.25rem; border-radius: 10px; font-weight: 600; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 15px rgba(99,102,241,0.35);">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
            Subir Boleta PDF
        </a>
    </div>
</div>

<!-- RESUMEN DEL PERIODO -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem;">
        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">TOTAL EMITIDAS</div>
        <div style="font-size: 1.75rem; font-weight: 800; color: #fff;">{{ $summary['total_emitted'] }}</div>
        <div style="font-size: 0.75rem; color: #64748b;">Periodo {{ $currentMonth }}/{{ $currentYear }}</div>
    </div>

    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem;">
        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">PUBLICADAS (EN MÓVIL)</div>
        <div style="font-size: 1.75rem; font-weight: 800; color: #10b981;">{{ $summary['published'] }}</div>
        <div style="font-size: 0.75rem; color: #64748b;">Disponibles para descarga</div>
    </div>

    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem;">
        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">LEÍDAS / CONFIRMADAS</div>
        <div style="font-size: 1.75rem; font-weight: 800; color: #60a5fa;">{{ $summary['read_count'] }}</div>
        <div style="font-size: 0.75rem; color: #64748b;">Vistas por colaboradores</div>
    </div>

    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem;">
        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">MONTO NETO TOTAL</div>
        <div style="font-size: 1.75rem; font-weight: 800; color: #c084fc;">S/ {{ number_format($summary['total_payroll'], 2) }}</div>
        <div style="font-size: 0.75rem; color: #64748b;">Planilla del mes</div>
    </div>
</div>

<!-- FILTROS Y PUBLICACIÓN MASIVA -->
<div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <form method="GET" action="{{ route('payslips.index') }}" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <select name="year" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.55rem; border-radius: 8px;">
            @for($y = date('Y'); $y >= 2022; $y--)
                <option value="{{ $y }}" {{ $currentYear == $y ? 'selected' : '' }}>Año {{ $y }}</option>
            @endfor
        </select>

        <select name="month" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.55rem; border-radius: 8px;">
            @foreach(range(1, 12) as $m)
                @php $monthName = \Carbon\Carbon::create(null, $m, 1)->isoFormat('MMMM'); @endphp
                <option value="{{ $m }}" {{ $currentMonth == $m ? 'selected' : '' }}>{{ ucfirst($monthName) }}</option>
            @endforeach
        </select>

        <select name="user_id" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.55rem; border-radius: 8px;">
            <option value="">Todos los colaboradores</option>
            @foreach($employees as $e)
                <option value="{{ $e->id }}" {{ request('user_id') == $e->id ? 'selected' : '' }}>{{ $e->first_name }} {{ $e->last_name }}</option>
            @endforeach
        </select>

        <button type="submit" style="background: #6366f1; border: none; color: #fff; padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 600; cursor: pointer;">
            Filtrar
        </button>
    </form>

    <form method="POST" action="{{ route('payslips.bulk-publish') }}">
        @csrf
        <input type="hidden" name="year" value="{{ $currentYear }}">
        <input type="hidden" name="month" value="{{ $currentMonth }}">
        <button type="submit" onclick="return confirm('¿Publicar todas las boletas en borrador de este periodo para la app móvil?')" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; padding: 0.6rem 1.1rem; border-radius: 8px; font-weight: 600; cursor: pointer;">
            ✓ Publicar Todas en Móvil
        </button>
    </form>
</div>

<!-- TABLA DE BOLETAS -->
<div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden;">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border); background: rgba(0,0,0,0.2); color: #94a3b8;">
                    <th style="padding: 1rem 1.25rem;">Colaborador</th>
                    <th style="padding: 1rem;">Título de Boleta</th>
                    <th style="padding: 1rem;">Sueldo Bruto</th>
                    <th style="padding: 1rem;">Deducciones</th>
                    <th style="padding: 1rem;">Neto a Pagar</th>
                    <th style="padding: 1rem;">Estado Publicación</th>
                    <th style="padding: 1rem;">Visto por Empleado</th>
                    <th style="padding: 1rem; text-align: center;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payslips as $p)
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.2s;">
                        <td style="padding: 1rem 1.25rem;">
                            <div style="font-weight: 600; color: #fff;">{{ $p->user->first_name }} {{ $p->user->last_name }}</div>
                            <div style="font-size: 0.75rem; color: #94a3b8;">{{ $p->user->employee_code }} • DNI: {{ $p->user->document_number }}</div>
                        </td>
                        <td style="padding: 1rem; color: #cbd5e1;">
                            {{ $p->title }}
                        </td>
                        <td style="padding: 1rem; color: #cbd5e1;">
                            S/ {{ number_format((float)$p->gross_salary, 2) }}
                        </td>
                        <td style="padding: 1rem; color: #f87171;">
                            - S/ {{ number_format((float)$p->deductions, 2) }}
                        </td>
                        <td style="padding: 1rem; color: #10b981; font-weight: 700;">
                            S/ {{ number_format((float)$p->net_salary, 2) }}
                        </td>
                        <td style="padding: 1rem;">
                            <span style="padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; {{ $p->status === 'published' ? 'background: rgba(16,185,129,0.15); color: #10b981;' : 'background: rgba(245,158,11,0.15); color: #f59e0b;' }}">
                                {{ $p->status === 'published' ? 'Publicada' : 'Borrador' }}
                            </span>
                        </td>
                        <td style="padding: 1rem; color: #94a3b8; font-size: 0.8rem;">
                            @if($p->read_at)
                                <span style="color: #60a5fa;">✓ Leído el {{ $p->read_at->format('d/m/Y H:i') }}</span>
                            @else
                                <span style="color: #64748b;">No leído aún</span>
                            @endif
                        </td>
                        <td style="padding: 1rem; text-align: center;">
                            <div style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                                @if($p->status === 'draft')
                                    <form method="POST" action="{{ route('payslips.publish', $p->id) }}">
                                        @csrf
                                        <button type="submit" style="padding: 6px 10px; background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7; border-radius: 6px; font-size: 0.75rem; cursor: pointer; font-weight: 600;">
                                            Publicar
                                        </button>
                                    </form>
                                @endif

                                <a href="{{ route('payslips.download', $p->id) }}" style="padding: 6px 10px; background: rgba(99,102,241,0.15); border: 1px solid rgba(99,102,241,0.3); color: #818cf8; border-radius: 6px; font-size: 0.75rem; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                                    PDF
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="padding: 3rem; text-align: center; color: #64748b;">
                            No se encontraron boletas de pago para este periodo.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($payslips->hasPages())
        <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border);">
            {{ $payslips->links() }}
        </div>
    @endif
</div>
@endsection
