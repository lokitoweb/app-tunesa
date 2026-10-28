@extends('print.layout')

@section('title', 'Constancias de depósito de CTS')

@section('pages')
    @foreach($pages as $page)
        @php $calc = $page['deposit']->data['calc'] ?? []; @endphp
        <section class="page">
            @include('print.header')
            <div class="letter">
                <h1>{{ $page['text']['title'] }}</h1>
                {{ $page['text']['html'] }}
            </div>

            <h2>1. Periodo que se liquida</h2>
            <p>Del {{ \App\Modules\HR\Models\DocumentTemplate::longDate($page['deposit']->period_start) }} al {{ \App\Modules\HR\Models\DocumentTemplate::longDate($page['deposit']->period_end) }}
                @if(($calc['from'] ?? null) && $calc['from'] !== $page['deposit']->period_start->toDateString())
                    <span class="muted">(computable desde el {{ \Carbon\Carbon::parse($calc['from'])->format('d/m/Y') }})</span>
                @endif
            </p>

            <h2>2. Remuneración computable</h2>
            <table>
                @foreach($calc['components'] ?? [] as $c)
                    @if((float) $c['amount'] > 0)<tr><td>{{ $c['label'] }}</td><td class="num">S/ {{ number_format($c['amount'], 2) }}</td></tr>@endif
                @endforeach
                <tr class="total"><td>Total</td><td class="num">S/ {{ number_format($calc['base'] ?? 0, 2) }}</td></tr>
            </table>

            <h2>3. Cálculo</h2>
            <table>
                <tr><td>Por {{ $calc['months'] ?? 0 }} mes(es) y {{ $calc['days'] ?? 0 }} día(s)</td><td class="muted">{{ $calc['basis'] ?? '' }}</td></tr>
                <tr class="total"><td>Monto depositado</td><td class="num">S/ {{ number_format($page['deposit']->amount, 2) }}</td></tr>
            </table>

            @include('print.signatures', ['sig' => $page['deposit']->data['signatory'] ?? null, 'person' => $page['deposit']->user, 'signature' => $page['signature']])
        </section>
    @endforeach
@endsection
