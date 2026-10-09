<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Asistencias y Horas — {{ $tenant->name }}</title>
    <style>
        @page {
            margin: 20mm 15mm 20mm 15mm;
            size: A4 portrait;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #1e293b;
            line-height: 1.4;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #1e3a8a;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #334155;
            margin-top: 4px;
        }
        .meta-text {
            font-size: 10px;
            color: #64748b;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .data-table th {
            background-color: #f1f5f9;
            color: #1e293b;
            font-weight: bold;
            text-align: left;
            padding: 7px 6px;
            border: 1px solid #cbd5e1;
            font-size: 10px;
        }
        .data-table td {
            padding: 6px 6px;
            border: 1px solid #e2e8f0;
            font-size: 10px;
        }
        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .summary-box {
            margin-top: 20px;
            display: flex;
            gap: 15px;
        }
        .summary-item {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 8px 12px;
            display: inline-block;
            margin-right: 12px;
            background: #f8fafc;
        }
        .signatures {
            margin-top: 50px;
            width: 100%;
        }
        .signature-line {
            width: 200px;
            border-top: 1px solid #64748b;
            text-align: center;
            padding-top: 5px;
            font-size: 10px;
            color: #475569;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="vertical-align: top;">
                <div class="company-name">{{ $tenant->name }}</div>
                <div class="report-title">REPORTE EJECUTIVO DE ASISTENCIA Y JORNADAS</div>
                <div class="meta-text">Periodo auditado: {{ $startDate }} al {{ $endDate }}</div>
            </td>
            <td style="text-align: right; vertical-align: top;">
                <div class="meta-text">TuneSA Multi-Tenant GPS Platform</div>
                <div class="meta-text">Fecha de Emisión: {{ date('d/m/Y H:i') }}</div>
                <div class="meta-text">Total Registros: {{ $attendances->count() }}</div>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Código</th>
                <th>Colaborador</th>
                <th>Sucursal</th>
                <th>Entrada</th>
                <th>Salida</th>
                <th>Tardanza</th>
                <th>Horas Trab.</th>
                <th>H. Extras</th>
                <th>Geocerca</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse($attendances as $att)
                <tr>
                    <td>{{ $att->work_date ? $att->work_date->format('d/m/Y') : '' }}</td>
                    <td>{{ $att->user->employee_code ?? 'EMP' }}</td>
                    <td><strong>{{ $att->user->first_name ?? '' }} {{ $att->user->last_name ?? '' }}</strong></td>
                    <td>{{ $att->branch->name ?? '' }}</td>
                    <td>{{ $att->clock_in_at ? $att->clock_in_at->format('H:i') : '--' }}</td>
                    <td>{{ $att->clock_out_at ? $att->clock_out_at->format('H:i') : '--' }}</td>
                    <td>{{ $att->late_minutes ? $att->late_minutes . ' min' : '-' }}</td>
                    <td style="text-align: right;">{{ number_format((float) ($att->worked_hours ?? 0), 2) }}</td>
                    <td style="text-align: right;">{{ number_format((float) ($att->overtime_hours ?? 0), 2) }}</td>
                    <td>{{ $att->clock_in_within_geofence ? 'Válida' : 'Fuera' }}</td>
                    <td>{{ ucfirst($att->status) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" style="text-align: center; padding: 20px;">Sin registros para el rango indicado.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 15px;">
        <div class="summary-item">
            <strong>Total Horas Regulares:</strong> {{ number_format((float) $attendances->sum('worked_hours'), 2) }} hrs
        </div>
        <div class="summary-item">
            <strong>Total Horas Extras:</strong> {{ number_format((float) $attendances->sum('overtime_hours'), 2) }} hrs
        </div>
        <div class="summary-item">
            <strong>Total Retardos:</strong> {{ $attendances->where('late_minutes', '>', 0)->count() }} ocurrencias
        </div>
    </div>

    <table class="signatures">
        <tr>
            <td style="width: 50%; text-align: center;">
                <div class="signature-line" style="margin: 0 auto;">
                    Firma Responsable RRHH<br>
                    {{ $tenant->name }}
                </div>
            </td>
            <td style="width: 50%; text-align: center;">
                <div class="signature-line" style="margin: 0 auto;">
                    Firma Gerencia General / Auditoría<br>
                    Validado satelitalmente con TuneSA
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
