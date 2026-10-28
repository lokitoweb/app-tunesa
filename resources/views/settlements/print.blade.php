@extends('print.layout')

@section('title', $pageTitle . ' · ' . $s->user->full_name)

@section('pages')
    @foreach($docs as $doc)
        <section class="page">
            @include('print.header')
            @if($doc === 'liquidation')
                @include('settlements.partials.liquidation')
            @else
                <div class="letter">
                    @if($doc !== 'cts_release_letter')<h1>{{ $texts[$doc]['title'] }}</h1>@endif
                    {{ $texts[$doc]['html'] }}
                </div>
                @include('print.signatures', ['sig' => $s->data['signatory'] ?? null, 'person' => null])
            @endif
        </section>
    @endforeach
@endsection
