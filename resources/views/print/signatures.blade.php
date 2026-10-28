{{-- $sig: quien firma por la empresa (snapshot); $person: el colaborador si también firma; $signature: imagen de la firma --}}
<div class="signs @unless($person) single @endunless">
    @if($person)
        <div>
            <div style="height: 64px"></div>
            <div class="line">{{ $person->full_name }}<br><span class="muted">{{ strtoupper((string) $person->document_type) }} {{ $person->document_number }}</span></div>
        </div>
    @endif
    <div>
        @if($signature)<img src="{{ $signature }}" alt="Firma">@else<div style="height: 64px"></div>@endif
        <div class="line">{{ $sig['name'] ?? $company['name'] }}<br><span class="muted">{{ $sig['position'] ?? 'Empleador' }}</span></div>
    </div>
</div>
