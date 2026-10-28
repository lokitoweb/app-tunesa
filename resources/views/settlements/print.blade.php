@php
    $d = $s->data;
    $u = $s->user;
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, '.', ',');
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Liquidación N.° {{ $s->id }} · {{ $u->full_name }}</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #1f2937; background: #fff; margin: 0; padding: 24px 16px; font-size: 13px; }
        .sheet { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; text-align: left; vertical-align: top; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: 700; border-top: 2px solid #1f2937; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 4px 24px; margin: 12px 0; }
        .signs { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; margin-top: 72px; text-align: center; }
        .signs div { border-top: 1px solid #1f2937; padding-top: 6px; }
        .actions { text-align: right; margin-bottom: 12px; }
        .actions button { padding: 6px 14px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="actions"><button type="button" onclick="window.print()">Imprimir</button></div>
    <h1>Liquidación de beneficios sociales N.° {{ $s->id }}</h1>
    <div class="muted">{{ $s->tenant?->company_name }}@if($s->tenant?->ruc) · RUC {{ $s->tenant->ruc }}@endif</div>

    <div class="grid">
        <div><strong>Colaborador:</strong> {{ $u->full_name }}</div>
        <div><strong>Documento:</strong> {{ strtoupper((string) $u->document_type) }} {{ $u->document_number }}</div>
        <div><strong>Cargo:</strong> {{ $u->position ?: '—' }}</div>
        <div><strong>Régimen:</strong> {{ $d['regime']['name'] ?? 'Sin régimen' }}</div>
        <div><strong>Ingreso:</strong> {{ $u->hire_date?->format('d/m/Y') ?? '—' }}</div>
        <div><strong>Cese:</strong> {{ $s->termination_date->format('d/m/Y') }}</div>
        <div><strong>Motivo:</strong> {{ $reasons[$s->reason] ?? $s->reason }}</div>
        <div><strong>Tiempo de servicio:</strong> {{ $d['service'] ?? '—' }}</div>
        <div><strong>Remuneración computable:</strong> {{ $money($d['input']['salary'] ?? null) }}</div>
    </div>

    <table>
        <thead><tr><th>Concepto</th><th>Cálculo</th><th class="num">Monto</th></tr></thead>
        <tbody>
        @foreach($d['lines'] ?? [] as $line)
            <tr><td>{{ $line['label'] }}</td><td class="muted">{{ $line['basis'] }}</td><td class="num">{{ $money($line['amount']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="2">Total bruto</td><td class="num">{{ $money($s->total) }}</td></tr></tfoot>
    </table>
    <p class="muted">Montos brutos, sin descuentos de pensión ni renta de quinta categoría.</p>
    @if($s->notes)<p><strong>Observaciones:</strong> {{ $s->notes }}</p>@endif
    <p class="muted">Registrado el {{ $s->created_at->format('d/m/Y H:i') }} por {{ $s->creator?->full_name ?? '—' }}.</p>

    <div class="signs">
        <div>{{ $s->tenant?->company_name }}<br><span class="muted">Empleador</span></div>
        <div>{{ $u->full_name }}<br><span class="muted">Colaborador</span></div>
    </div>
</div>
</body>
</html>
