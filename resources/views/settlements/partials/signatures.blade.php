@php $sig = $s->data['signatory'] ?? null; @endphp
<div class="signs" @unless($employeeSigns) style="grid-template-columns: 1fr; max-width: 320px; margin-left: auto; margin-right: auto;" @endunless>
    @if($employeeSigns)
        <div>
            <div style="height: 64px"></div>
            <div class="line">{{ $s->user->full_name }}<br><span class="muted">{{ strtoupper((string) $s->user->document_type) }} {{ $s->user->document_number }}</span></div>
        </div>
    @endif
    <div>
        @if($signature)<img src="{{ $signature }}" alt="Firma">@else<div style="height: 64px"></div>@endif
        <div class="line">{{ $sig['name'] ?? $company['name'] }}<br><span class="muted">{{ $sig['position'] ?? 'Empleador' }}</span></div>
    </div>
</div>
