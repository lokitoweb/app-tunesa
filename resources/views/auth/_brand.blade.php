{{-- Logo arriba del formulario (en celular, donde no se ve el panel azul) --}}
@php
    $authBrand = $brandSettings ?? \App\Modules\Core\Models\TenantSetting::forTenant(isset($tenant) && $tenant ? $tenant : null);
    $authLogo = $authBrand->displayLogoUrl();
    $authLogoDark = $authBrand->usesDefaultLogo() ? asset('img/grupo-negreiros-logo-white.png') : $authBrand->logoDarkUrl();
    $authHasDark = $authLogoDark && $authLogoDark !== $authLogo;
@endphp
<div class="gn-brand">
    <img src="{{ $authLogo }}" alt="{{ $authBrand->tradeName() }}" class="brand-logo-light {{ $authHasDark ? 'has-dark' : '' }}">
    @if($authHasDark)
        <img src="{{ $authLogoDark }}" alt="{{ $authBrand->tradeName() }}" class="brand-logo-dark">
    @endif
</div>
