{{-- Logo de la empresa (marca blanca) o de la plataforma, arriba del título de la tarjeta ($brandSettings lo arma layouts.guest) --}}
    @php
        $authBrand = $brandSettings ?? \App\Modules\Core\Models\TenantSetting::forTenant(isset($tenant) && $tenant ? $tenant : null);
        $authLogo = $authBrand->displayLogoUrl();
        $authLogoDark = $authBrand->logoDarkUrl();
        // Solo se oculta el logo claro en oscuro si de verdad hay uno oscuro distinto
        $authHasDark = $authLogoDark && $authLogoDark !== $authLogo;
    @endphp
    <div class="auth-brand">
        <img src="{{ $authLogo }}" alt="{{ $authBrand->tradeName() }}" class="brand-logo-light {{ $authHasDark ? 'has-dark' : '' }} {{ $authBrand->usesDefaultLogo() ? 'is-default' : '' }}">
        @if($authHasDark)
            <img src="{{ $authLogoDark }}" alt="{{ $authBrand->tradeName() }}" class="brand-logo-dark">
        @endif
    </div>
