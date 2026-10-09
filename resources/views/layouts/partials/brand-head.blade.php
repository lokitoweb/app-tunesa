{{--
    Marca blanca dinámica (panel y pantallas de acceso).
    Recibe $brandSettings = TenantSetting::forTenant($empresa|null): empresa > plataforma > fábrica.
--}}
@php
    $bsPrimary   = $brandSettings->primaryColor();
    $bsSecondary = $brandSettings->secondaryColor();
    $bsAccent    = $brandSettings->accentColor();
    $bsNavbar    = $brandSettings->navbarColor();
    $bsNavText   = \App\Models\TenantSetting::contrastText($bsNavbar);
@endphp
@if($favicon = $brandSettings->faviconUrl())
    <link rel="icon" href="{{ $favicon }}">
@endif
<meta name="theme-color" content="{{ $bsPrimary }}">
<style>
    /* Marca blanca: colores de la empresa (o de la plataforma) */
    :root {
        --brand: {{ $bsPrimary }};
        --brand-rgb: {{ \App\Models\TenantSetting::rgbList($bsPrimary) }};
        --brand-text: {{ \App\Models\TenantSetting::contrastText($bsPrimary) }};
        --brand-secondary: {{ $bsSecondary }};
        --brand-secondary-rgb: {{ \App\Models\TenantSetting::rgbList($bsSecondary) }};
        --brand-accent: {{ $bsAccent }};
        --brand-accent-rgb: {{ \App\Models\TenantSetting::rgbList($bsAccent) }};
        --brand-navbar: {{ $bsNavbar }};
        --brand-navbar-text: {{ $bsNavText }};
    }
    /* Texto legible sobre el color primario (p. ej. un amarillo pide texto oscuro) */
    .btn-primary { --bs-btn-color: var(--brand-text); --bs-btn-hover-color: var(--brand-text); --bs-btn-active-color: var(--brand-text); --bs-btn-disabled-color: var(--brand-text); }
    /* Logo claro u oscuro según el tema */
    .brand-logo-dark { display: none !important; }
    [data-bs-theme="dark"] .brand-logo-light.has-dark { display: none !important; }
    [data-bs-theme="dark"] .brand-logo-dark { display: inline-block !important; }
@if($brandSettings->hasCustomNavbar())
    /* Barra superior con el color de navegación elegido */
    .panel-topbar { background: var(--brand-navbar); color: var(--brand-navbar-text); border-bottom-color: transparent; }
    .panel-topbar .text-body-secondary { color: color-mix(in srgb, var(--brand-navbar-text) 75%, transparent) !important; }
    .sidebar-link.active { box-shadow: inset 3px 0 0 var(--brand-navbar); }
    [data-bs-theme="dark"] .sidebar-link.active { box-shadow: inset 3px 0 0 color-mix(in srgb, var(--brand-navbar) 50%, #fff); }
@endif
</style>
