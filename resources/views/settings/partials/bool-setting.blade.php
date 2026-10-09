{{--
    Opción Sí/No de "Marca y app".
    - Plataforma (fila global): interruptor simple; desmarcado = No.
    - Empresa: selector de tres estados. "Heredar" guarda NULL y sigue el valor
      de la plataforma aunque este cambie después.

    Parámetros: $flag, $label, $desc, $isGlobal, $row (fila guardada),
    $effective (valor efectivo), $inherited (efectivo de la plataforma).
--}}
@php
    $platformValue = (bool) $inherited->{$flag};
@endphp
@if($isGlobal)
    <div class="form-check form-switch form-switch-lg d-flex justify-content-between align-items-center gap-3 mb-0 ps-0 {{ $class ?? '' }}">
        <label class="form-check-label" for="{{ $flag }}">
            <span class="d-block fw-semibold">{{ $label }}</span>
            <span class="d-block small text-body-secondary">{{ $desc }}</span>
        </label>
        <input class="form-check-input flex-shrink-0 ms-0" type="checkbox" role="switch" name="{{ $flag }}" value="1" id="{{ $flag }}" data-bool-setting="{{ $flag }}" @checked(old($flag, $effective->{$flag}))>
    </div>
@else
    @php
        $stored  = $row->{$flag};
        $current = old($flag, $stored === null ? '' : ($stored ? '1' : '0'));
        $current = $current === null ? '' : (string) $current;
    @endphp
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 {{ $class ?? '' }}">
        <label class="form-label mb-0" for="{{ $flag }}">
            <span class="d-block fw-semibold">{{ $label }}</span>
            <span class="d-block small text-body-secondary">{{ $desc }}</span>
        </label>
        <select name="{{ $flag }}" id="{{ $flag }}" class="form-select flex-shrink-0 w-auto @error($flag) is-invalid @enderror"
                data-bool-setting="{{ $flag }}" data-inherited="{{ $platformValue ? '1' : '0' }}">
            <option value="" @selected($current === '')>Heredar de la plataforma (actual: {{ $platformValue ? 'Sí' : 'No' }})</option>
            <option value="1" @selected($current === '1')>Sí</option>
            <option value="0" @selected($current === '0')>No</option>
        </select>
    </div>
@endif
