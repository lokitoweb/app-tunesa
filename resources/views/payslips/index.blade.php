@extends('layouts.app')

@section('title', 'Boletas de pago')

@section('content')
<div class="page-header">
    <div>
        <h1>Boletas de pago</h1>
        <p>Carga de boletas en PDF y publicación segura en la app de cada colaborador.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('payslips.bulk') }}" class="btn btn-outline-primary"><i class="bi bi-files me-1"></i>Carga masiva</a>
        <a href="{{ route('payslips.create') }}" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Subir boleta</a>
    </div>
</div>

<div class="row g-3 mb-4">
    @foreach([
        ['Emitidas', $summary['total_emitted'], "Periodo {$currentMonth}/{$currentYear}", 'file-earmark-text', 'primary'],
        ['Publicadas', $summary['published'], 'Disponibles en la app', 'phone', 'success'],
        ['Leídas', $summary['read_count'], 'Abiertas por el colaborador', 'eye', 'info'],
        ['Neto total', 'S/ ' . number_format($summary['total_payroll'], 2), 'Planilla del mes', 'cash-stack', 'warning'],
    ] as [$label, $value, $hint, $icon, $t])
        <div class="col-6 col-xl-3">
            <div class="card border-0 h-100 kpi-card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <span class="kpi-label">{{ $label }}</span>
                    <span class="kpi-icon bg-{{ $t }}-subtle text-{{ $t }}-emphasis"><i class="bi bi-{{ $icon }}"></i></span>
                </div>
                <div class="fs-4 fw-bold">{{ $value }}</div>
                <div class="small text-body-secondary">{{ $hint }}</div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="card border-0 mb-4">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-end gap-3">
        <form method="GET" action="{{ route('payslips.index') }}" class="row g-2 align-items-end flex-grow-1">
            <div class="col-6 col-md-2">
                <label for="year" class="form-label small">Año</label>
                <select id="year" name="year" class="form-select">
                    @for($y = date('Y'); $y >= 2022; $y--)
                        <option value="{{ $y }}" @selected($currentYear == $y)>{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="month" class="form-label small">Mes</label>
                <select id="month" name="month" class="form-select">
                    @foreach(range(1, 12) as $m)
                        <option value="{{ $m }}" @selected($currentMonth == $m)>{{ ucfirst(\Carbon\Carbon::create(null, $m, 1)->isoFormat('MMMM')) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label for="user_id" class="form-label small">Colaborador</label>
                <select id="user_id" name="user_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach($employees as $e)
                        <option value="{{ $e->id }}" @selected(request('user_id') == $e->id)>{{ $e->first_name }} {{ $e->last_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            </div>
        </form>
        <form method="POST" action="{{ route('payslips.bulk-publish') }}">
            @csrf
            <input type="hidden" name="year" value="{{ $currentYear }}">
            <input type="hidden" name="month" value="{{ $currentMonth }}">
            <button type="submit" class="btn btn-success" onclick="return confirm('¿Publicar en la app todas las boletas en borrador de este periodo?')">
                <i class="bi bi-send-check me-1"></i>Publicar todas
            </button>
        </form>
    </div>
</div>

<div class="card border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Boleta</th>
                    <th class="text-end">Bruto</th>
                    <th class="text-end">Descuentos</th>
                    <th class="text-end">Neto</th>
                    <th>Estado</th>
                    <th>Lectura</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payslips as $p)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $p->employee?->first_name }} {{ $p->employee?->last_name }}</div>
                            <div class="small text-body-secondary">{{ $p->employee?->employee_code }} · DNI {{ $p->employee?->document_number }}</div>
                         @include('partials.tenant-badge', ['tenantId' => $p->tenant_id])</td>
                        <td>{{ $p->title }}</td>
                        <td class="text-end text-nowrap">S/ {{ number_format((float) $p->gross_salary, 2) }}</td>
                        <td class="text-end text-nowrap text-danger">− S/ {{ number_format((float) $p->deductions, 2) }}</td>
                        <td class="text-end text-nowrap fw-semibold">S/ {{ number_format((float) $p->net_salary, 2) }}</td>
                        <td><x-status-badge type="payslip_status" :value="$p->status ?? 'draft'" /></td>
                        <td class="small">
                            @if($p->read_at)
                                <span class="text-success"><i class="bi bi-check2-all"></i> {{ $p->read_at->format('d/m/Y h:i a') }}</span>
                            @else
                                <span class="text-body-secondary">No leída</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            @if($p->status === 'draft')
                                <form method="POST" action="{{ route('payslips.publish', $p->id) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success">Publicar</button>
                                </form>
                            @endif
                            <a href="{{ route('payslips.view', $p->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" aria-label="Ver boleta"><i class="bi bi-eye"></i></a>
                            <a href="{{ route('payslips.download', $p->id) }}" class="btn btn-sm btn-outline-secondary" aria-label="Descargar PDF"><i class="bi bi-download"></i></a>
                            @if($p->status === 'published')
                                <form method="POST" action="{{ route('payslips.annul', $p->id) }}" class="d-inline" onsubmit="return confirm('¿Anular esta boleta? El colaborador dejará de verla en la app.')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Anular boleta" title="Anular"><i class="bi bi-x-circle"></i></button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('payslips.destroy', $p->id) }}" class="d-inline" onsubmit="return confirm('¿Eliminar esta boleta y su PDF? Podrás volver a subir la de este periodo.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Eliminar boleta" title="Eliminar"><i class="bi bi-trash"></i></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-file-earmark"></i>No hay boletas para este periodo.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($payslips->hasPages())
        <div class="card-footer bg-transparent">{{ $payslips->links() }}</div>
    @endif
</div>
@endsection
