@php
    $d = $s->data;
    $u = $s->user;
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, '.', ',');
    $lines = collect($d['lines'] ?? []);
    $section = fn (array $keys) => $lines->filter(fn ($l) => in_array($l['key'], $keys, true));
    $others = $lines->filter(fn ($l) => str_starts_with($l['key'], 'other_income_') || $l['key'] === 'indemnity');
    $law = collect($d['deductions'] ?? [])->reject(fn ($x) => str_starts_with($x['key'], 'other_discount_'));
    $otherDiscounts = collect($d['deductions'] ?? [])->filter(fn ($x) => str_starts_with($x['key'], 'other_discount_'));
@endphp
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

{{ $texts['settlement_intro']['html'] }}

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
<table><tr class="total"><td>Total liquidación de beneficios sociales</td><td class="num">{{ $money($d['gross'] ?? $s->total) }}</td></tr></table>

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
{{ $texts['settlement_closing']['html'] }}

@include('print.signatures', ['sig' => $d['signatory'] ?? null, 'person' => $u])
<p class="muted" style="margin-top: 24px">Registrado el {{ $s->created_at->timezone(tenant_timezone())->format('d/m/Y H:i') }} por {{ $s->creator?->full_name ?? '—' }}.</p>
