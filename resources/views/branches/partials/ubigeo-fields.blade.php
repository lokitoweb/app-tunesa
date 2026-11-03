{{-- Departamento (city) > Provincia > Distrito de la sede; los llena BranchUbigeo (ubigeo-script) --}}
@php($style = $style ?? null)
<div class="{{ $col ?? 'col-12 col-md-4' }}">
    <label for="{{ $prefix }}city" class="{{ $labelClass ?? 'form-label' }}">Departamento</label>
    @include('branches.partials.region-select', ['id' => $prefix . 'city', 'value' => $city ?? null, 'style' => $style])
</div>
<div class="{{ $col ?? 'col-12 col-md-4' }}">
    <label for="{{ $prefix }}province" class="{{ $labelClass ?? 'form-label' }}">Provincia</label>
    <select id="{{ $prefix }}province" name="province" class="form-select" @if($style) style="{{ $style }}" @endif disabled>
        <option value="">Elige el departamento</option>
    </select>
</div>
<div class="{{ $col ?? 'col-12 col-md-4' }}">
    <label for="{{ $prefix }}district" class="{{ $labelClass ?? 'form-label' }}">Distrito</label>
    <select id="{{ $prefix }}district" name="district" class="form-select" @if($style) style="{{ $style }}" @endif disabled>
        <option value="">Elige la provincia</option>
    </select>
</div>
