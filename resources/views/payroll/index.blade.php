@extends('layouts.app')

@section('title', 'Cruce con planilla')

@section('content')
@php
    $months = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
@endphp

<div class="page-head">
    <div>
        <h1>Cruce con planilla</h1>
        <p>Consolidado de {{ $months[$month] }} {{ $year }}: horas, tardanzas, faltas, licencias, cuenta de abono y régimen previsional. En pantalla las cuentas van enmascaradas; el CSV las trae completas y la descarga queda registrada.</p>
    </div>
    <a href="{{ route('payroll.export', array_filter(['year' => $year, 'month' => $month, 'branch_id' => $branchId])) }}" class="btn btn-primary">⬇ Exportar para planilla (CSV)</a>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <select name="month" class="form-control" style="max-width: 160px;">
                @for ($m = 1; $m <= 12; $m++)<option value="{{ $m }}" @selected($m === $month)>{{ $months[$m] }}</option>@endfor
            </select>
            <input type="number" name="year" class="form-control" value="{{ $year }}" min="2020" max="2100" style="max-width: 110px;">
            <select name="branch_id" class="form-control" style="max-width: 240px;">
                <option value="">Todas las sucursales</option>
                @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}</option>@endforeach
            </select>
            <button class="btn btn-ghost">Ver periodo</button>
        </form>
    </div>
</div>

<div class="stats-grid" style="margin-bottom: 1.5rem;">
    <div class="stat-card"><div class="stat-card-label">Colaboradores</div><div class="stat-card-value">{{ $totals['employees'] }}</div></div>
    <div class="stat-card"><div class="stat-card-label">Horas trabajadas</div><div class="stat-card-value">{{ number_format($totals['worked_hours'], 1) }}</div></div>
    <div class="stat-card"><div class="stat-card-label">Min. de tardanza</div><div class="stat-card-value">{{ $totals['late_minutes'] }}</div></div>
    <div class="stat-card"><div class="stat-card-label">Faltas injustificadas</div><div class="stat-card-value">{{ $totals['absences'] }}</div></div>
    <div class="stat-card">
        <div class="stat-card-label">Sin cuenta / sin verificar</div>
        <div class="stat-card-value" style="color: {{ $totals['missing_bank'] || $totals['not_verified'] ? 'var(--warning)' : 'var(--success)' }}">{{ $totals['missing_bank'] }} / {{ $totals['not_verified'] }}</div>
        <div class="stat-card-sub">Revisa antes de enviar al banco</div>
    </div>
</div>

<div class="card" style="overflow-x: auto;">
    <table class="attendance-live-table" style="min-width: 1200px;">
        <thead>
            <tr>
                <th>Colaborador</th><th>Régimen</th><th>Días</th><th>Horas</th><th>Extra</th><th>Tardanzas</th>
                <th>Faltas</th><th>Licencias</th><th>Banco / cuenta</th><th>Previsión</th><th>Estado pago</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td><strong style="color: #fff;">{{ $r['full_name'] }}</strong><div class="text-muted" style="font-size: 0.75rem;">{{ $r['employee_code'] }} · {{ $r['document_number'] }}</div></td>
                <td>{{ $r['labor_regime'] ?? '—' }}</td>
                <td>{{ $r['days_worked'] }}</td>
                <td>{{ number_format($r['worked_hours'], 2) }}</td>
                <td>{{ number_format($r['overtime_hours'], 2) }}</td>
                <td>{{ $r['late_count'] }} <span class="text-muted">({{ $r['late_minutes'] }} min)</span></td>
                <td>{{ $r['absences'] }} @if ($r['excused'])<span class="text-muted">+{{ $r['excused'] }} just.</span>@endif</td>
                <td class="text-muted">V {{ $r['vacation_days'] }} · M {{ $r['medical_leave_days'] }} · P {{ $r['permission_days'] }}</td>
                <td>{{ $r['bank_name'] ?? '—' }} <div class="mono text-muted" style="font-size: 0.75rem;">{{ $r['account_number'] }}</div></td>
                <td>{{ $r['pension_system'] ?? '—' }} <span class="text-muted">{{ $r['pension_fund'] }}</span></td>
                <td><span class="badge {{ $r['payment_status'] === 'Verificado' ? 'badge-present' : ($r['payment_status'] === 'Por verificar' ? 'badge-late' : 'badge-absent') }}">{{ $r['payment_status'] }}</span></td>
            </tr>
        @empty
            <tr><td colspan="11" class="text-muted">Sin colaboradores en el periodo.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
