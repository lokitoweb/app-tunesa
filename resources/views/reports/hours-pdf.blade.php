<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte de horas y faltas</title>
<style>
    @page { margin: 78px 28px 44px 28px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 8.5px; color: #1f2937; }
    header { position: fixed; top: -60px; left: 0; right: 0; height: 50px; border-bottom: 2px solid #1E40AF; }
    header h1 { font-size: 14px; margin: 0 0 3px; color: #1E40AF; }
    header .meta { font-size: 8.5px; color: #4b5563; }
    footer { position: fixed; bottom: -28px; left: 0; right: 0; height: 18px; font-size: 7.5px; color: #6b7280; border-top: 1px solid #d1d5db; padding-top: 4px; }
    table { width: 100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th { background: #1E40AF; color: #fff; font-weight: bold; padding: 4px 3px; text-align: left; font-size: 7.5px; }
    td { padding: 3px; border-bottom: 1px solid #e5e7eb; }
    tbody tr:nth-child(even) td { background: #f3f4f6; }
    .num { text-align: right; }
    .bad { color: #b91c1c; font-weight: bold; }
    tfoot td { font-weight: bold; border-top: 2px solid #1E40AF; background: #eef2ff; }
</style>
</head>
<body>
<header>
    <h1>Reporte de horas y faltas</h1>
    <div class="meta">
        {{ $tenant?->company_name ?? 'Todas las empresas' }} ·
        Periodo: {{ \Carbon\Carbon::parse($filters->from)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($filters->to)->format('d/m/Y') }} ·
        Horas nocturnas: 22:00–06:00 · HE = horas extra sobre la jornada del turno
    </div>
</header>
<footer>Generado el {{ $generatedAt->format('d/m/Y h:i a') }} · {{ brand_name() }}</footer>

<table>
    <thead>
        <tr>
            @if($withCompany)<th>Empresa</th>@endif
            <th>Código</th>
            <th>Colaborador</th>
            <th>Sucursal</th>
            <th class="num">Días prog.</th>
            <th class="num">Días trab.</th>
            <th class="num">Horas trab.</th>
            <th class="num">Tardanza (min)</th>
            <th class="num">Salida antic. (min)</th>
            <th class="num">HE diurnas</th>
            <th class="num">HE nocturnas</th>
            <th class="num">Horas noct.</th>
            <th class="num">Faltas injust.</th>
            <th class="num">Faltas just.</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $r)
            @php($s = $r['summary'])
            <tr>
                @if($withCompany)<td>{{ $r['company'] }}</td>@endif
                <td>{{ $r['employee_code'] ?? '—' }}</td>
                <td>{{ $r['name'] }}</td>
                <td>{{ $r['branch'] ?? '—' }}</td>
                <td class="num">{{ $s['scheduled_days'] }}</td>
                <td class="num">{{ $s['days_worked'] }}</td>
                <td class="num">{{ number_format($s['worked_hours'], 2) }}</td>
                <td class="num">{{ $s['late_minutes'] }}</td>
                <td class="num">{{ $s['early_minutes'] }}</td>
                <td class="num">{{ number_format($s['overtime_day_hours'], 2) }}</td>
                <td class="num">{{ number_format($s['overtime_night_hours'], 2) }}</td>
                <td class="num">{{ number_format($s['night_hours'], 2) }}</td>
                <td class="num {{ $s['absences_unjustified'] > 0 ? 'bad' : '' }}">{{ $s['absences_unjustified'] }}</td>
                <td class="num">{{ $s['absences_justified'] }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $withCompany ? 14 : 13 }}">Sin colaboradores para los filtros elegidos.</td></tr>
        @endforelse
    </tbody>
    @if(count($rows))
        <tfoot>
            <tr>
                <td colspan="{{ $withCompany ? 4 : 3 }}">Totales ({{ count($rows) }} colaboradores)</td>
                <td class="num">{{ $totals['scheduled_days'] }}</td>
                <td class="num">{{ $totals['days_worked'] }}</td>
                <td class="num">{{ number_format($totals['worked_hours'], 2) }}</td>
                <td class="num">{{ $totals['late_minutes'] }}</td>
                <td class="num">{{ $totals['early_minutes'] }}</td>
                <td class="num">{{ number_format($totals['overtime_day_hours'], 2) }}</td>
                <td class="num">{{ number_format($totals['overtime_night_hours'], 2) }}</td>
                <td class="num">{{ number_format($totals['night_hours'], 2) }}</td>
                <td class="num">{{ $totals['absences_unjustified'] }}</td>
                <td class="num">{{ $totals['absences_justified'] }}</td>
            </tr>
        </tfoot>
    @endif
</table>
</body>
</html>
