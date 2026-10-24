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

    @if ($items)
        <table class="items">
            <thead><tr><th>Producto / servicio</th><th>Unidad</th><th style="text-align: right;">Cantidad</th></tr></thead>
            <tbody>
                @foreach ($items as [$product, $unit, $qty])
                    <tr><td>{{ $product }}</td><td>{{ $unit }}</td><td style="text-align: right;">{{ rtrim(rtrim(number_format($qty, 3, '.', ','), '0'), '.') }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="total">Total: {{ \App\Modules\Finance\Services\FinanceDocumentPdf::money($currency, $amount) }}</div>

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
