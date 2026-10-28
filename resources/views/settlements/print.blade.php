@php
    $d = $s->data;
    $u = $s->user;
    $in = $d['input'] ?? [];
    $sig = $d['signatory'] ?? null;
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, '.', ',');
    $lines = collect($d['lines'] ?? []);
    $section = fn (array $keys) => $lines->filter(fn ($l) => in_array($l['key'], $keys, true));
    $others = $lines->filter(fn ($l) => str_starts_with($l['key'], 'other_income_') || $l['key'] === 'indemnity');
    $law = collect($d['deductions'] ?? [])->reject(fn ($x) => str_starts_with($x['key'], 'other_discount_'));
    $otherDiscounts = collect($d['deductions'] ?? [])->filter(fn ($x) => str_starts_with($x['key'], 'other_discount_'));
    $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];
    $longDate = fn ($date) => $date->day . ' de ' . $months[$date->month] . ' de ' . $date->year;
    $gross = $d['gross'] ?? $s->total;
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Liquidación N.° {{ $s->id }} · {{ $u->full_name }}</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #1f2937; background: #fff; margin: 0; padding: 24px 16px; font-size: 12.5px; }
        .sheet { max-width: 780px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; border-bottom: 2px solid #1f2937; padding-bottom: 8px; }
        header img { max-height: 48px; max-width: 180px; }
        h1 { font-size: 16px; text-align: center; margin: 16px 0 8px; letter-spacing: .02em; }
        h2 { font-size: 12.5px; margin: 14px 0 4px; text-transform: uppercase; }
        .muted { color: #6b7280; }
        .data { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 2px 24px; margin: 8px 0; }
        .data div span { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 4px; text-align: left; vertical-align: top; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .total td { font-weight: 700; border-top: 2px solid #1f2937; border-bottom: none; }
        .bases { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px; margin: 8px 0; }
        .bases > div { border: 1px solid #e5e7eb; border-radius: 4px; padding: 6px; }
        .bases .row { display: flex; justify-content: space-between; }
        .net { display: flex; justify-content: space-between; font-size: 14px; font-weight: 700; border: 2px solid #1f2937; padding: 8px; margin-top: 12px; }
        .signs { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; margin-top: 56px; text-align: center; }
        .signs img { max-height: 60px; display: block; margin: 0 auto 4px; }
        .signs .line { border-top: 1px solid #1f2937; padding-top: 6px; }
        .actions { text-align: right; margin-bottom: 12px; }
        .actions button { padding: 6px 14px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="actions"><button type="button" onclick="window.print()">Imprimir</button></div>
    <header>
        <div>
            <strong>{{ $company['name'] }}</strong><br>
            <span class="muted">{{ implode(' · ', array_filter([$company['ruc'] ? "RUC {$company['ruc']}" : null, $company['address']])) }}</span>
        </div>
        @if($company['logo'])<img src="{{ $company['logo'] }}" alt="{{ $company['name'] }}">@endif
    </header>

    <h1>LIQUIDACIÓN DE BENEFICIOS SOCIALES N.° {{ $s->id }}</h1>

    <div class="data">
        <div><span>Colaborador:</span> {{ $u->full_name }}</div>
        <div><span>Documento:</span> {{ strtoupper((string) $u->document_type) }} {{ $u->document_number }}</div>
        <div><span>Cargo:</span> {{ $u->position ?: '—' }}</div>
        <div><span>Sede:</span> {{ $d['employee']['branch'] ?? '—' }}</div>
        <div><span>Fecha de ingreso:</span> {{ $u->hire_date?->format('d/m/Y') ?? '—' }}</div>
        <div><span>Fecha de cese:</span> {{ $s->termination_date->format('d/m/Y') }}</div>
        <div><span>Motivo de cese:</span> {{ $reasons[$s->reason] ?? $s->reason }}</div>
        <div><span>Tiempo de servicios:</span> {{ $d['service'] ?? '—' }}</div>
        <div><span>Régimen laboral:</span> {{ $d['regime']['name'] ?? 'Sin régimen' }}</div>
        <div><span>Pensión:</span> {{ $d['pension']['name'] ?? 'Sin aporte' }}</div>
    </div>

    <p>{{ implode(', ', array_filter([
        $company['name'],
        $company['ruc'] ? "con RUC N.° {$company['ruc']}" : null,
        $company['address'] ? "con domicilio en {$company['address']}" : null,
        $sig ? 'representada por ' . $sig['name'] . ($sig['position'] ? " ({$sig['position']})" : '') : null,
    ])) }}, da cumplimiento a las obligaciones laborales que asumió como empleador con el colaborador antes mencionado.</p>

    @if(! empty($d['bases']))
        <h2>Remuneración computable</h2>
        <div class="bases">
            @foreach($d['bases'] as $base)
                <div>
                    <strong>{{ $base['label'] }}</strong>
                    @foreach($base['components'] as $c)
                        @if((float) $c['amount'] > 0)<div class="row muted"><span>{{ $c['label'] }}</span><span>{{ $money($c['amount']) }}</span></div>@endif
                    @endforeach
                    <div class="row"><strong>Total</strong><strong>{{ $money($base['total']) }}</strong></div>
                </div>
            @endforeach
        </div>
    @endif

    @foreach([['I. Compensación por tiempo de servicios trunca', $section(['cts'])], ['II. Vacaciones', $section(['vacation_pending', 'vacation_truncated'])],
        ['III. Gratificación trunca', $section(['gratification', 'gratification_bonus'])], ['IV. Otros ingresos', $others]] as [$title, $rows])
        <h2>{{ $title }}</h2>
        <table>
            @forelse($rows as $line)
                <tr><td style="width: 30%">{{ $line['label'] }}</td><td class="muted">{{ $line['basis'] }}</td><td class="num">{{ $money($line['amount']) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">No corresponde.</td></tr>
            @endforelse
        </table>
    @endforeach
    <table><tr class="total"><td>Total liquidación de beneficios sociales</td><td class="num">{{ $money($gross) }}</td></tr></table>

    @foreach([['V. Descuentos de ley', $law], ['VI. Otros descuentos', $otherDiscounts]] as [$title, $rows])
        <h2>{{ $title }}</h2>
        <table>
            @forelse($rows as $x)
                <tr><td style="width: 30%">{{ $x['label'] }}</td><td class="muted">{{ $x['basis'] }}</td><td class="num">{{ $money($x['amount']) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">No corresponde.</td></tr>
            @endforelse
        </table>
    @endforeach

    <div class="net"><span>TOTAL A PAGAR</span><span>S/ {{ $money($s->total) }}</span></div>

    @if($s->notes)<p><strong>Observaciones:</strong> {{ $s->notes }}</p>@endif
    <p>
        Se expide el presente documento como constancia del cálculo, determinación y pago de los beneficios sociales. Las partes lo suscriben y
        declaran su conformidad con el periodo de servicios reconocido, el cálculo de las remuneraciones y beneficios sociales y el monto pagado.
    </p>
    <p>Suscrito el {{ $longDate($s->termination_date) }}.</p>

    <div class="signs">
        <div>
            <div style="height: 64px"></div>
            <div class="line">{{ $u->full_name }}<br><span class="muted">{{ strtoupper((string) $u->document_type) }} {{ $u->document_number }}</span></div>
        </div>
        <div>
            @if($signature)<img src="{{ $signature }}" alt="Firma">@else<div style="height: 64px"></div>@endif
            <div class="line">{{ $sig['name'] ?? $company['name'] }}<br><span class="muted">{{ $sig['position'] ?? 'Empleador' }}</span></div>
        </div>
    </div>
    <p class="muted" style="margin-top: 24px">Registrado el {{ $s->created_at->timezone(tenant_timezone())->format('d/m/Y H:i') }} por {{ $s->creator?->full_name ?? '—' }}.</p>
</div>
</body>
</html>
