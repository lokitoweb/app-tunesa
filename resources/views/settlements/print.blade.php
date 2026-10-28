<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }} · {{ $s->user->full_name }}</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #1f2937; background: #fff; margin: 0; padding: 24px 16px; font-size: 12.5px; }
        .sheet { max-width: 780px; margin: 0 auto; }
        .page + .page { break-before: page; margin-top: 48px; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; border-bottom: 2px solid #1f2937; padding-bottom: 8px; }
        header img { max-height: 48px; max-width: 180px; }
        h1 { font-size: 16px; text-align: center; margin: 16px 0 8px; letter-spacing: .02em; }
        h2 { font-size: 12.5px; margin: 14px 0 4px; text-transform: uppercase; }
        .muted { color: #6b7280; }
        .data { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 2px 24px; margin: 8px 0; }
        .data div span { color: #6b7280; }
        .letter p { font-size: 13.5px; line-height: 1.6; text-align: justify; }
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
        @media print { .actions { display: none; } body { padding: 0; } .page + .page { margin-top: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="actions"><button type="button" onclick="window.print()">Imprimir</button></div>
    @foreach($docs as $doc)
        <section class="page">
            <header>
                <div>
                    <strong>{{ $company['name'] }}</strong><br>
                    <span class="muted">{{ implode(' · ', array_filter([$company['ruc'] ? "RUC {$company['ruc']}" : null, $company['address']])) }}</span>
                </div>
                @if($company['logo'])<img src="{{ $company['logo'] }}" alt="{{ $company['name'] }}">@endif
            </header>
            @if($doc === 'liquidation')
                @include('settlements.partials.liquidation')
            @else
                <div class="letter">
                    @if($doc !== 'cts_release_letter')<h1>{{ $texts[$doc]['title'] }}</h1>@endif
                    {{ $texts[$doc]['html'] }}
                </div>
                @include('settlements.partials.signatures', ['employeeSigns' => false])
            @endif
        </section>
    @endforeach
</div>
</body>
</html>
