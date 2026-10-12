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
    @endphp
    <title>@yield('title', 'Acceso') — {{ $brandSettings->tradeName() }}</title>

    @include('layouts.partials.theme-boot')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}">
    @include('layouts.partials.brand-head', ['brandSettings' => $brandSettings])
</head>
<body>
    <button type="button" class="btn auth-theme" id="authThemeToggle" aria-label="Cambiar entre tema claro y oscuro" title="Tema claro / oscuro">
        <i class="bi bi-moon-stars"></i>
    </button>
    <main class="auth-shell">
        <section class="auth-panel">
            @yield('content')
        </section>
        <small class="auth-foot">© {{ date('Y') }} {{ $brandSettings->tradeName() }}</small>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
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
