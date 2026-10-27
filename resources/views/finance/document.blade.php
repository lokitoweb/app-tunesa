<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
    .head { border-bottom: 3px solid {{ $color }}; padding-bottom: 8px; margin-bottom: 14px; }
    .head td { vertical-align: top; }
    h1 { font-size: 17px; margin: 0; color: {{ $color }}; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .grid td { padding: 4px 6px; border: 1px solid #e5e7eb; }
    .grid td.k { width: 28%; background: #f9fafb; font-weight: bold; }
    .items th { background: {{ $color }}; color: #fff; padding: 5px; text-align: left; }
    .items td { padding: 5px; border-bottom: 1px solid #e5e7eb; }
    .total { text-align: right; font-size: 14px; font-weight: bold; margin-top: 10px; }
    .sign { margin-top: 50px; width: 260px; text-align: center; }
    .sign img { max-height: 70px; max-width: 220px; }
    .sign .line { border-top: 1px solid #111827; padding-top: 4px; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <strong style="font-size: 14px;">{{ $company }}</strong><br>
                <span class="muted">RUC {{ $tenant?->ruc ?? '—' }} · {{ $tenant?->address }}</span>
            </td>
            <td style="text-align: right;">
                <h1>{{ $title }}</h1>
                <strong>N.º {{ $number }}</strong><br>
                <span class="muted">Fecha: {{ $date ?? '—' }}</span>
            </td>
        </tr>
    </table>

    <table class="grid" style="margin-bottom: 12px;">
        <tr><td class="k">Proveedor</td><td>{{ $supplier?->name ?? '—' }}</td></tr>
        <tr><td class="k">RUC</td><td>{{ $supplier?->ruc ?? '—' }}</td></tr>
        @foreach ($rows as [$label, $value])
            @if (filled($value))
                <tr><td class="k">{{ $label }}</td><td>{{ $value }}</td></tr>
            @endif
        @endforeach
    </table>

    @php($priced = ($breakdown ?? null) !== null)
    @if ($items)
        <table class="items">
            <thead><tr>
                <th>Producto / servicio</th><th>Unidad</th><th style="text-align: right;">Cantidad</th>
                @if ($priced)
                    <th style="text-align: right;">P. unitario</th><th style="text-align: right;">Dscto.</th><th style="text-align: right;">Importe</th>
                @endif
            </tr></thead>
            <tbody>
                @foreach ($items as $line)
                    <tr>
                        <td>{{ $line[0] }}</td><td>{{ $line[1] }}</td>
                        <td style="text-align: right;">{{ rtrim(rtrim(number_format($line[2], 3, '.', ','), '0'), '.') }}</td>
                        @if ($priced)
                            <td style="text-align: right;">{{ number_format($line[3] ?? 0, 2, '.', ',') }}</td>
                            <td style="text-align: right;">{{ ($line[4] ?? 0) > 0 ? rtrim(rtrim(number_format($line[4], 2, '.', ''), '0'), '.') . ' %' : '—' }}</td>
                            <td style="text-align: right;">{{ number_format($line[5] ?? 0, 2, '.', ',') }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($priced)
        @php($pct = rtrim(rtrim(number_format($breakdown['rate'] * 100, 2, '.', ''), '0'), '.'))
        <table style="width: 260px; margin: 10px 0 0 auto;">
            <tr><td class="muted">Valor venta</td><td style="text-align: right;">{{ \App\Modules\Finance\Services\FinanceDocumentPdf::money($currency, $breakdown['taxable']) }}</td></tr>
            <tr><td class="muted">IGV ({{ $pct }} %)</td><td style="text-align: right;">{{ \App\Modules\Finance\Services\FinanceDocumentPdf::money($currency, $breakdown['igv']) }}</td></tr>
            <tr><td style="font-size: 14px; font-weight: bold; padding-top: 4px;">Total</td><td style="text-align: right; font-size: 14px; font-weight: bold; padding-top: 4px;">{{ \App\Modules\Finance\Services\FinanceDocumentPdf::money($currency, $amount) }}</td></tr>
        </table>
        <div class="muted" style="text-align: right; font-size: 9px;">Precios {{ $breakdown['includes_igv'] ? 'con IGV incluido' : 'sin IGV (el IGV se suma)' }}</div>
    @elseif ($amount !== null)
        <div class="total">Total: {{ \App\Modules\Finance\Services\FinanceDocumentPdf::money($currency, $amount) }}</div>
    @else
        {{-- Solicitud de cotización: sin montos; el proveedor responde con su cotización --}}
        <p class="muted" style="margin-top: 14px;">Agradeceremos enviar su cotización con precios unitarios, plazo de entrega y validez de la oferta.</p>
    @endif

    @if ($signature)
        <div class="sign">
            @if ($signature['image'])
                <img src="{{ $signature['image'] }}" alt="Firma">
            @else
                <div style="height: 70px;"></div>
            @endif
            <div class="line">
                {{ $signature['label'] }}: <strong>{{ $signature['name'] }}</strong>
                @if ($signature['position'])<br><span class="muted">{{ $signature['position'] }}</span>@endif
            </div>
        </div>
    @endif
</body>
</html>
