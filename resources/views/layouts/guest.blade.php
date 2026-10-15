<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        // Login por subdominio o dominio propio de una empresa: su marca; si no, la de la plataforma
        $brandTenant = isset($tenant) && $tenant instanceof \App\Modules\Core\Models\Tenant ? $tenant : \App\Modules\Core\Models\Tenant::fromHost(request()->getHost());
        $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($brandTenant);
        // Logo sobre el panel azul: versión blanca (logo por defecto), el oscuro subido, o el claro sobre una placa blanca
        $heroLogo = $brandSettings->usesDefaultLogo() ? asset('img/grupo-negreiros-logo-white.png') : ($brandSettings->logoDarkUrl() ?: $brandSettings->displayLogoUrl());
        $heroOnPlate = ! $brandSettings->usesDefaultLogo() && ! $brandSettings->logoDarkUrl();
    @endphp
    <title>@yield('title', 'Acceso') — {{ $brandSettings->tradeName() }}</title>

    @include('layouts.partials.theme-boot')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v=3">
    @include('layouts.partials.brand-head', ['brandSettings' => $brandSettings])
</head>
<body class="gn-auth-body">
    <div class="gn-auth {{ View::hasSection('wide') ? 'is-wide' : '' }}">
        <aside class="gn-hero" aria-hidden="true">
            <div class="gn-hero-top">
                <img src="{{ $heroLogo }}" alt="" class="gn-hero-logo {{ $heroOnPlate ? 'on-plate' : '' }}">
            </div>
            <div class="gn-hero-body">
                <span class="gn-hero-tag">ERP empresarial</span>
                <h2>Toda la gestión de tu empresa en un solo sistema</h2>
                <p>Personas, asistencia, planilla y reportes conectados, en la web y en el celular.</p>
                <ul>
                    <li><i class="bi bi-people"></i><span>Recursos humanos: legajo digital, solicitudes, vacaciones y capacitaciones</span></li>
                    <li><i class="bi bi-geo-alt"></i><span>Asistencia con GPS y selfie, validada en cada sede</span></li>
                    <li><i class="bi bi-cash-stack"></i><span>Boletas de pago, horas extra y cruce con planilla</span></li>
                    <li><i class="bi bi-bar-chart-line"></i><span>Paneles por rol: gerencia, contabilidad y RR. HH., con reportes al día</span></li>
                </ul>
            </div>
            <small class="gn-hero-foot">© {{ date('Y') }} {{ $brandSettings->tradeName() }}</small>
        </aside>

        <main class="gn-main">
            <button type="button" class="gn-theme" id="authThemeToggle" aria-label="Cambiar entre tema claro y oscuro" title="Tema claro / oscuro">
                <i class="bi bi-moon-stars"></i>
            </button>
            <div class="gn-form-wrap">
                @yield('content')
            </div>
            <small class="gn-main-foot">© {{ date('Y') }} {{ $brandSettings->tradeName() }}</small>
        </main>
    </div>
    <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script>
        (function () {
            var toggle = document.getElementById('authThemeToggle');
            var root = document.documentElement;
            function paint() { toggle.firstElementChild.className = root.getAttribute('data-bs-theme') === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'; }
            paint();
            toggle.addEventListener('click', function () {
                var t = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-bs-theme', t);
                try { localStorage.setItem('panel-theme', t); } catch (e) {}
                paint();
            });
        })();
    </script>
</body>
</html>
