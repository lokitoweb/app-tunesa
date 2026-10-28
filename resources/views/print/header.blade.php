<header>
    <div>
        <strong>{{ $company['name'] }}</strong><br>
        <span class="muted">{{ implode(' · ', array_filter([$company['ruc'] ? "RUC {$company['ruc']}" : null, $company['address']])) }}</span>
    </div>
    @if($company['logo'])<img src="{{ $company['logo'] }}" alt="{{ $company['name'] }}">@endif
</header>
