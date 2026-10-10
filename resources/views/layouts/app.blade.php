<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        // Empresa activa (la del usuario, o la que eligió el Super Admin); sin empresa = plataforma
        $brand = app(\App\Modules\Core\Services\TenantContext::class)->getOrNull() ?? Auth::user()?->tenant;
        $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($brand);
    @endphp
    <title>@yield('title', 'Panel') — {{ $brandSettings->tradeName() }}</title>

    {{-- Aplica el tema guardado antes de pintar para evitar parpadeo --}}
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}">

    @include('layouts.partials.brand-head', ['brandSettings' => $brandSettings])

    @yield('styles')
</head>
<body>
@php
    $navUser = Auth::user();
@endphp

<div class="panel-shell">
    {{-- SIDEBAR: fijo en escritorio (lg+), offcanvas en móvil --}}
    <aside id="sidebar" class="offcanvas-lg offcanvas-start panel-sidebar" tabindex="-1" aria-labelledby="sidebarLabel">
        <div class="sidebar-brand">
            <a href="{{ route('dashboard') }}" class="sidebar-logo" aria-label="{{ $brandSettings->tradeName() }}: ir al inicio">
                @php $logoUrl = $brandSettings->displayLogoUrl(); $logoDarkUrl = $brandSettings->logoDarkUrl(); @endphp
                <img src="{{ $logoUrl }}" alt="{{ $brandSettings->tradeName() }}" class="brand-logo brand-logo-light {{ $logoDarkUrl && $logoDarkUrl !== $logoUrl ? 'has-dark' : '' }} {{ $brandSettings->usesDefaultLogo() ? 'is-default' : '' }}">
                @if($logoDarkUrl && $logoDarkUrl !== $logoUrl)
                    <img src="{{ $logoDarkUrl }}" alt="{{ $brandSettings->tradeName() }}" class="brand-logo brand-logo-dark">
                @endif
                <span id="sidebarLabel" class="visually-hidden">{{ $brandSettings->tradeName() }}</span>
            </a>
            <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Cerrar menú"></button>
        </div>

        <nav class="sidebar-nav" aria-label="Menú principal">
            @foreach(\App\Modules\Core\Support\PanelNavigation::for($navUser, request()) as $section)
                <div class="nav-heading">{{ $section['heading'] }}</div>
                @foreach($section['items'] as $item)
                    @include('layouts.partials.nav-link', $item)
                @endforeach
            @endforeach
        </nav>

    </aside>

    {{-- CONTENIDO --}}
    <div class="panel-main">
        <header class="panel-topbar">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <button class="btn btn-light border d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Abrir menú">
                    <i class="bi bi-list"></i>
                </button>
                <span class="badge text-bg-light border text-truncate d-none d-sm-inline-block">{{ $brand->company_name ?? 'Plataforma' }}</span>
                @if($navUser->isSuperAdmin())
                    <form method="POST" action="{{ route('admin.tenant.switch') }}" class="m-0">
                        @csrf
                        <select name="tenant_id" class="form-select form-select-sm tenant-switch" onchange="this.form.submit()" aria-label="Empresa a administrar">
                            <option value="">Vista global · todas las empresas</option>
                            @foreach(\App\Modules\Core\Models\Tenant::orderBy('company_name')->get(['id', 'company_name']) as $t)
                                <option value="{{ $t->id }}" @selected($brand?->id === $t->id)>{{ $t->company_name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="small text-body-secondary d-none d-md-inline">{{ now()->isoFormat('dddd D [de] MMMM') }}</span>
                @include('layouts.partials.notifications-bell')
                <button type="button" class="btn btn-light border btn-sm" id="themeToggle" aria-label="Cambiar tema claro u oscuro" title="Tema claro / oscuro">
                    <i class="bi bi-moon-stars"></i>
                </button>
                <div class="dropdown">
                    <button type="button" class="user-menu" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Tu cuenta">
                        <span class="avatar avatar-sm">{{ strtoupper(mb_substr($navUser->first_name ?? 'U', 0, 1) . mb_substr($navUser->last_name ?? '', 0, 1)) }}</span>
                        <span class="d-none d-md-block text-start lh-sm">
                            <span class="d-block fw-semibold small">{{ $navUser->first_name }} {{ $navUser->last_name }}</span>
                            <span class="d-block user-role">{{ label('user_role', $navUser->role) }}</span>
                        </span>
                        <i class="bi bi-chevron-down small text-body-secondary d-none d-md-inline"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end user-dropdown">
                        <div class="px-3 py-2">
                            <div class="fw-semibold">{{ $navUser->first_name }} {{ $navUser->last_name }}</div>
                            <div class="small text-body-secondary text-truncate">{{ $navUser->email }}</div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="{{ route('notifications.index') }}"><i class="bi bi-bell me-2"></i>Notificaciones</a>
                        <form method="POST" action="{{ route('logout') }}" class="m-0">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main id="main-content" class="panel-content">
            {{-- En el dashboard el aviso "Plataforma" ya lo dice --}}
            @if(($globalView ?? false) && ! request()->routeIs('dashboard'))
                <div class="alert alert-primary d-flex align-items-start gap-2 global-view-banner" role="status">
                    <i class="bi bi-globe2"></i>
                    <div>Vista global: mostrando registros de todas las empresas. Para crear o editar, elige una empresa en el selector superior.</div>
                </div>
            @endif

            {{-- Avisos flotantes (toasts): arriba a la derecha, no empujan el contenido --}}
            <div class="toast-stack" aria-live="polite">
            @foreach(['success' => 'success', 'info' => 'info', 'error' => 'danger'] as $key => $variant)
                @if(session($key))
                    <div class="alert alert-{{ $variant }} alert-dismissible" role="alert">
                        <i class="bi {{ $variant === 'success' ? 'bi-check-circle' : ($variant === 'danger' ? 'bi-exclamation-octagon' : 'bi-info-circle') }}"></i>
                        <div>
                            <strong class="toast-title">{{ ['success' => 'Listo', 'info' => 'Aviso', 'error' => 'No se pudo completar'][$key] }}</strong>
                            <span class="toast-text">{{ session($key) }}</span>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                    </div>
                @endif
            @endforeach

            @if($errors->any())
                <div class="alert alert-danger alert-dismissible" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <div>
                        <strong class="toast-title">Revisa los datos del formulario</strong>
                        <ul class="mb-0 toast-text">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                </div>
            @endif
            </div>

            @yield('content')
        </main>
    </div>
</div>

{{-- Modal global para ver una selfie ampliada --}}
<div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6" id="photoModalTitle">Evidencia de marcación</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center bg-body-tertiary">
                <img id="photoModalImg" src="" alt="Selfie de marcación" class="img-fluid rounded" style="max-height: 70vh;">
            </div>
            <div class="modal-footer justify-content-start small text-body-secondary" id="photoModalMeta"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="{{ asset('js/panel.js') }}"></script>

@yield('scripts')
</body>
</html>
