{{--
    Marca blanca dinámica (panel y pantallas de acceso).
    Recibe $brandSettings = TenantSetting::forTenant($empresa|null): empresa > plataforma > fábrica.
--}}
@php
    $bsPrimary   = $brandSettings->primaryColor();
    $bsSecondary = $brandSettings->secondaryColor();
    $bsAccent    = $brandSettings->accentColor();
    $bsNavbar    = $brandSettings->navbarColor();
    $bsNavText   = \App\Modules\Core\Models\TenantSetting::contrastText($bsNavbar);
@endphp
@if($favicon = $brandSettings->faviconUrl())
    <link rel="icon" href="{{ $favicon }}">
@else
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
@endif
<meta name="theme-color" content="{{ $bsPrimary }}">
<style>
    /* Marca blanca: colores de la empresa (o de la plataforma) */
    :root {
        --brand: {{ $bsPrimary }};
        --brand-rgb: {{ \App\Modules\Core\Models\TenantSetting::rgbList($bsPrimary) }};
        --brand-text: {{ \App\Modules\Core\Models\TenantSetting::contrastText($bsPrimary) }};
        --brand-secondary: {{ $bsSecondary }};
        --brand-secondary-rgb: {{ \App\Modules\Core\Models\TenantSetting::rgbList($bsSecondary) }};
        --brand-accent: {{ $bsAccent }};
        --brand-accent-rgb: {{ \App\Modules\Core\Models\TenantSetting::rgbList($bsAccent) }};
        --brand-navbar: {{ $bsNavbar }};
        --brand-navbar-text: {{ $bsNavText }};
    }
    /* Texto legible sobre el color primario (p. ej. un amarillo pide texto oscuro) */
    .btn-primary { --bs-btn-color: var(--brand-text); --bs-btn-hover-color: var(--brand-text); --bs-btn-active-color: var(--brand-text); --bs-btn-disabled-color: var(--brand-text); }
    /* Logo claro u oscuro según el tema */
    .brand-logo-dark { display: none !important; }
    [data-bs-theme="dark"] .brand-logo-light.has-dark { display: none !important; }
    [data-bs-theme="dark"] .brand-logo-dark { display: inline-block !important; }
</style>
