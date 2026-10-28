{{-- Región de la sede (config/regions.php). Un valor anterior que no está en la lista se conserva. --}}
@php
    $regions = config('regions.countries.' . config('regions.default_country'), []);
    $current = $value ?? null;
@endphp
<select id="{{ $id }}" name="city" class="{{ $class ?? 'form-select' }}" @isset($style) style="{{ $style }}" @endisset>
    <option value="">Sin región</option>
    @if($current && ! in_array($current, $regions, true))
        <option value="{{ $current }}" selected>{{ $current }} (actual)</option>
    @endif
    @foreach($regions as $region)
        <option value="{{ $region }}" @selected($current === $region)>{{ $region }}</option>
    @endforeach
</select>
