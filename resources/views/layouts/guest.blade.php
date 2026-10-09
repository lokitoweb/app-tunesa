<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        // Login por subdominio o dominio propio de una empresa: su marca; si no, la de la plataforma
        $brandTenant = isset($tenant) && $tenant instanceof \App\Models\Tenant ? $tenant : \App\Models\Tenant::fromHost(request()->getHost());
        $brandSettings = \App\Models\TenantSetting::forTenant($brandTenant);
    @endphp
    <title>@yield('title', 'Acceso') — {{ $brandSettings->tradeName() }}</title>

    <script>
        (function () {
            try {
                var t = localStorage.getItem('panel-theme');
                if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-bs-theme', t);
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}">
    @include('layouts.partials.brand-head', ['brandSettings' => $brandSettings])
</head>
<body>
    <main class="auth-shell">
        @yield('content')
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
