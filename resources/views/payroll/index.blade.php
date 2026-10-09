@extends('layouts.app')

@section('title', 'Cruce con planilla')

@section('content')
@php
    $months = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $payTone = ['Verificado' => 'success', 'Por verificar' => 'warning'];
@endphp

<div class="page-header">
    <div>
        <h1>Cruce con planilla</h1>
        <p>{{ $months[$month] }} {{ $year }}: horas, tardanzas, faltas, licencias, cuenta de abono y previsión. En pantalla las cuentas van enmascaradas; el CSV las trae completas y la descarga queda registrada.</p>
    </div>
    <a href="{{ route('payroll.export', array_filter(['year' => $year, 'month' => $month, 'branch_id' => $branchId])) }}" class="btn btn-primary"><i class="bi bi-download me-1"></i>Exportar para planilla (CSV)</a>
</div>

<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-lg-2">
                <label for="month" class="form-label small">Mes</label>
                <select id="month" name="month" class="form-select">
                    @for($m = 1; $m <= 12; $m++)<option value="{{ $m }}" @selected($m === $month)>{{ $months[$m] }}</option>@endfor
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="year" class="form-label small">Año</label>
                <input type="number" id="year" name="year" class="form-control" value="{{ $year }}" min="2020" max="2100">
            </div>
            <div class="col-12 col-md-4">
                <label for="branch_id" class="form-label small">Sucursal</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">Todas las sucursales</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-12 col-md-3 col-lg-2 d-grid">
                <button class="btn btn-outline-primary">Ver periodo</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    @foreach([
        ['Colaboradores', $totals['employees'], 'people', 'primary'],
        ['Horas trabajadas', number_format($totals['worked_hours'], 1), 'clock', 'info'],
        ['Min. de tardanza', $totals['late_minutes'], 'alarm', 'warning'],
        ['Faltas injustificadas', $totals['absences'], 'person-x', 'danger'],
    ] as [$label, $value, $icon, $t])
        <div class="col-6 col-lg">
            <div class="card border-0 h-100 kpi-card"><div class="card-body">
                <div class="d-flex justify-content-between"><span class="kpi-label">{{ $label }}</span><i class="bi bi-{{ $icon }} text-{{ $t }}"></i></div>
                <div class="fs-4 fw-bold">{{ $value }}</div>
            </div></div>
        </div>
    @endforeach
    <div class="col-12 col-lg">
        <div class="card border-0 h-100 kpi-card"><div class="card-body">
            <div class="d-flex justify-content-between"><span class="kpi-label">Sin cuenta / sin verificar</span><i class="bi bi-bank text-warning"></i></div>
            <div class="fs-4 fw-bold {{ $totals['missing_bank'] || $totals['not_verified'] ? 'text-warning-emphasis' : 'text-success' }}">{{ $totals['missing_bank'] }} / {{ $totals['not_verified'] }}</div>
            <div class="small text-body-secondary">Revísalo antes de enviar al banco</div>
        </div></div>
    </div>
</div>

<div class="card border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small" style="min-width: 1100px;">
            <thead>
                <tr>
                    <th>Colaborador</th><th>Régimen</th><th class="text-end">Días</th><th class="text-end">Horas</th><th class="text-end">Extra</th>
                    <th>Tardanzas</th><th>Faltas</th><th>Licencias</th><th>Banco / cuenta</th><th>Previsión</th><th>Pago</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $r)
                <tr>
                    <td><div class="fw-semibold">{{ $r['full_name'] }}</div><div class="text-body-secondary">{{ $r['employee_code'] }} · {{ $r['document_number'] }}</div></td>
                    <td>{{ $r['labor_regime'] ?? '—' }}</td>
                    <td class="text-end">{{ $r['days_worked'] }}</td>
                    <td class="text-end">{{ number_format($r['worked_hours'], 2) }}</td>
                    <td class="text-end">{{ number_format($r['overtime_hours'], 2) }}</td>
                    <td>{{ $r['late_count'] }} <span class="text-body-secondary">({{ $r['late_minutes'] }} min)</span></td>
                    <td>{{ $r['absences'] }} @if($r['excused'])<span class="text-body-secondary">+{{ $r['excused'] }} just.</span>@endif</td>
                    <td class="text-body-secondary text-nowrap">V {{ $r['vacation_days'] }} · M {{ $r['medical_leave_days'] }} · P {{ $r['permission_days'] }}</td>
                    <td>{{ $r['bank_name'] ?? '—' }}<div class="font-monospace text-body-secondary">{{ $r['account_number'] }}</div></td>
                    <td>{{ $r['pension_system'] ?? '—' }} <span class="text-body-secondary">{{ $r['pension_fund'] }}</span></td>
                    <td>@include('partials.badge', ['tone' => $payTone[$r['payment_status']] ?? 'danger', 'label' => $r['payment_status']])</td>
                </tr>
            @empty
                <tr><td colspan="11"><div class="empty-state">Sin colaboradores en el periodo.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
