<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte de horas y faltas</title>
<style>
    @page { margin: 92px 28px 52px 28px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 8px; color: #1f2937; }
    header { position: fixed; top: -74px; left: 0; right: 0; height: 64px; border-bottom: 2px solid #1E40AF; }
    header h1 { font-size: 14px; margin: 0 0 3px; color: #1E40AF; }
    header .meta { font-size: 8px; color: #4b5563; }
    footer { position: fixed; bottom: -36px; left: 0; right: 0; height: 26px; font-size: 7.5px; color: #6b7280; border-top: 1px solid #d1d5db; padding-top: 4px; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { background: #1E40AF; color: #fff; font-weight: bold; padding: 4px 3px; text-align: left; font-size: 7px; }
    td { padding: 3px; border-bottom: 1px solid #e5e7eb; }
    tbody tr:nth-child(even) td { background: #f3f4f6; }
    .num { text-align: right; }
    .bad { color: #b91c1c; font-weight: bold; }
    tfoot td { font-weight: bold; border-top: 2px solid #1E40AF; background: #eef2ff; }
</style>
</head>
<body>
@php($rules = app(\App\Modules\Attendance\Services\Reports\AttendanceReportService::class)->rules($filters->tenantId))
@php($hm = fn (int $minutes) => \App\Modules\Attendance\Services\Reports\AttendanceReportService::hm($minutes))
<header>
    <h1>Reporte final de asistencias · horas y faltas</h1>
    <div class="meta">
        {{ $tenant?->company_name ?? 'Todas las empresas' }} ·
        Periodo: {{ \Carbon\Carbon::parse($filters->from)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($filters->to)->format('d/m/Y') }} ·
        Horas en H:MM · Horas nocturnas: 22:00–06:00 · HE = horas extra sobre la jornada del turno
    </div>
    <div class="meta">{{ $rules['tolerance'] }}</div>
</header>
<footer>{{ $rules['formula'] }}<br>Generado el {{ $generatedAt->format('d/m/Y h:i a') }} · {{ brand_name() }}</footer>

<table>
    <thead>
        <tr>
            @if($withCompany)<th>Empresa</th>@endif
            <th>Colaborador</th>
            <th>Cargo</th>
            <th>Documento</th>
            <th>Agencia</th>
            <th class="num">Días prog.</th>
            <th class="num">Días trab.</th>
            <th class="num">Horas trab.</th>
            <th class="num">Tardanza</th>
            <th class="num">Sal. antic.</th>
            <th class="num">HE diurnas</th>
            <th class="num">HE noct.</th>
            <th class="num">Faltas</th>
            <th class="num">Faltas just.</th>
            <th class="num">Descanso</th>
            <th class="num">Vacaciones</th>
            <th class="num">Permiso</th>
            <th class="num">D. médico</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $r)
            @php($s = $r['summary'])
            <tr>
                @if($withCompany)<td>{{ $r['company'] }}</td>@endif
                <td>{{ $r['name'] }}<br><span style="color:#6b7280">{{ $r['employee_code'] ?? '' }}</span></td>
                <td>{{ $r['position'] ?? '—' }}</td>
                <td>{{ $r['document_number'] ? strtoupper((string) $r['document_type']) . ' ' . $r['document_number'] : '—' }}</td>
                <td>{{ $r['branch'] ?? '—' }}</td>
                <td class="num">{{ $s['scheduled_days'] }}</td>
                <td class="num">{{ $s['days_worked'] }}</td>
                <td class="num">{{ $hm($s['worked_minutes']) }}</td>
                <td class="num">{{ $hm($s['late_minutes']) }}</td>
                <td class="num">{{ $hm($s['early_minutes']) }}</td>
                <td class="num">{{ $hm($s['overtime_day_minutes']) }}</td>
                <td class="num">{{ $hm($s['overtime_night_minutes']) }}</td>
                <td class="num {{ $s['absences_unjustified'] > 0 ? 'bad' : '' }}">{{ $s['absences_unjustified'] }}</td>
                <td class="num">{{ $s['absences_justified'] }}</td>
                <td class="num">{{ $s['rest_days'] }}</td>
                <td class="num">{{ $s['vacation_days'] }}</td>
                <td class="num">{{ $s['permission_days'] }}</td>
                <td class="num">{{ $s['medical_leave_days'] }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $withCompany ? 18 : 17 }}">Sin colaboradores para los filtros elegidos.</td></tr>
        @endforelse
    </tbody>
    @if(count($rows))
        <tfoot>
            <tr>
                <td colspan="{{ $withCompany ? 5 : 4 }}">Totales ({{ count($rows) }} colaboradores)</td>
                <td class="num">{{ $totals['scheduled_days'] }}</td>
                <td class="num">{{ $totals['days_worked'] }}</td>
                <td class="num">{{ $hm($totals['worked_minutes']) }}</td>
                <td class="num">{{ $hm($totals['late_minutes']) }}</td>
                <td class="num">{{ $hm($totals['early_minutes']) }}</td>
                <td class="num">{{ $hm($totals['overtime_day_minutes']) }}</td>
                <td class="num">{{ $hm($totals['overtime_night_minutes']) }}</td>
                <td class="num">{{ $totals['absences_unjustified'] }}</td>
                <td class="num">{{ $totals['absences_justified'] }}</td>
                <td class="num">{{ $totals['rest_days'] }}</td>
                <td class="num">{{ $totals['vacation_days'] }}</td>
                <td class="num">{{ $totals['permission_days'] }}</td>
                <td class="num">{{ $totals['medical_leave_days'] }}</td>
            </tr>
        </tfoot>
    @endif
</table>
</body>
</html>
