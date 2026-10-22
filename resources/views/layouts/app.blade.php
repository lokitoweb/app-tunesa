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

    @include('layouts.partials.theme-boot')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}?v={{ filemtime(public_path('css/panel.css')) }}">

    @include('layouts.partials.brand-head', ['brandSettings' => $brandSettings])

    @yield('styles')
</head>
<body>
@php
    $navUser = Auth::user();
@endphp

<div class="panel-shell">
    {{-- SIDEBAR: Dock colapsable con auto-expansión al pasar el mouse o al hacer clic --}}
    <aside id="sidebar" class="panel-sidebar" tabindex="-1" aria-label="Menú principal">
        <div class="sidebar-header">
            <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" title="Expandir / Contraer menú" aria-label="Expandir o contraer menú">
                <i class="bi bi-layout-sidebar"></i>
            </button>
            <a href="{{ route('dashboard') }}" class="sidebar-logo-link" title="{{ $brandSettings->tradeName() }}">
                <img src="{{ $brandSettings->displayLogoUrl() }}" alt="{{ $brandSettings->tradeName() }}" class="sidebar-logo-img">
            </a>
        </div>

        <nav class="sidebar-nav" aria-label="Navegación del ERP">
            @foreach(\App\Modules\Core\Support\PanelNavigation::for($navUser, request()) as $section)
                <div class="nav-heading">{{ $section['heading'] }}</div>
                @foreach($section['items'] as $item)
                    @include('layouts.partials.nav-link', $item)
                @endforeach
            @endforeach
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-shield-btn" title="ERP Verificado · Conexión Cifrada SSL">
                <i class="bi bi-shield-check"></i>
            </div>
        </div>
    </aside>

    {{-- CONTENIDO --}}
    <div class="panel-main">
        <header class="panel-topbar">
            <div class="topbar-left">
                <button class="sidebar-toggle-btn d-lg-none" type="button" id="sidebarMobileToggle" aria-label="Abrir menú">
                    <i class="bi bi-list"></i>
                </button>
                @if($navUser->isSuperAdmin())
                    @if(!request()->routeIs('dashboard*'))
                        <form method="POST" action="{{ route('admin.tenant.switch') }}" class="m-0">
                            @csrf
                            <div class="topbar-tenant-selector" title="Filtrar vista por empresa">
                                <span class="tenant-selector-icon">
                                    <i class="bi bi-buildings"></i>
                                </span>
                                <select name="tenant_id" class="tenant-selector-select" onchange="this.form.submit()" aria-label="Empresa a administrar">
                                    <option value="" @selected(!$brand)>Todas las empresas</option>
                                    @foreach(\App\Modules\Core\Models\Tenant::orderBy('company_name')->get(['id', 'company_name']) as $t)
                                        <option value="{{ $t->id }}" @selected($brand?->id === $t->id)>{{ $t->company_name }}</option>
                                    @endforeach
                                </select>
                                <i class="bi bi-chevron-down tenant-selector-arrow"></i>
                            </div>
                        </form>
                    @endif
                @else
                    <span class="topbar-company text-truncate">
                        <i class="bi bi-building text-primary me-1"></i> {{ $brand->company_name ?? '' }}
                    </span>
                @endif
            </div>

            <div class="topbar-right">
                {{-- Herramientas Rápidas del ERP --}}
                <button type="button" class="topbar-icon-btn d-none d-sm-inline-flex" id="fullscreenToggle" title="Pantalla completa" aria-label="Pantalla completa">
                    <i class="bi bi-fullscreen"></i>
                </button>

                {{-- Buscador Compacto (justo después de pantalla completa, tamaño reducido a la mitad) --}}
                <div class="topbar-search-container d-none d-md-block">
                    <button type="button" class="topbar-search-box" onclick="window.openCommandPalette()" title="Buscar en cualquier módulo, colaborador o reporte (Ctrl+K)">
                        <i class="bi bi-search text-secondary"></i>
                        <span class="search-box-text">Buscar...</span>
                        <kbd class="topbar-search-kbd">Ctrl+K</kbd>
                    </button>
                </div>

                <button type="button" class="topbar-icon-btn" id="themeToggle" title="Tema claro / oscuro" aria-label="Cambiar tema">
                    <i class="bi bi-sun"></i>
                </button>

                @php
                    $unreadCount = $navUser->unreadNotifications()->count();
                @endphp
                <div class="dropdown">
                    <a href="{{ route('notifications.index') }}" class="topbar-icon-btn" title="Notificaciones del ERP" aria-label="Notificaciones">
                        <i class="bi bi-bell"></i>
                        @if($unreadCount > 0)
                            <span class="badge-count badge-count-red">{{ $unreadCount }}</span>
                        @endif
                    </a>
                </div>

                {{-- Menú de Usuario del ERP (Modo Claro Profesional) --}}
                <div class="dropdown">
                    <button type="button" class="topbar-user" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menú de usuario">
                        <div class="topbar-avatar">
                            {{ strtoupper(mb_substr($navUser->first_name ?? 'U', 0, 1) . mb_substr($navUser->last_name ?? '', 0, 1)) }}
                        </div>
                        <div class="topbar-user-info d-none d-md-block text-start">
                            <span class="topbar-user-name">{{ $navUser->first_name }} {{ $navUser->last_name }}</span>
                            <span class="topbar-user-role">{{ label('user_role', $navUser->role) }}</span>
                        </div>
                        <i class="bi bi-chevron-down ms-1 text-secondary" style="font-size: 0.7rem;"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow-lg user-profile-dropdown">
                        <div class="px-3 py-2 border-bottom user-profile-header">
                            <div class="fw-bold user-profile-name small">{{ $navUser->first_name }} {{ $navUser->last_name }}</div>
                            <div class="user-profile-email small text-truncate">{{ $navUser->email }}</div>
                        </div>
                        <a class="dropdown-item py-2 d-flex align-items-center gap-2 user-profile-item" href="{{ route('account.edit') }}">
                            <i class="bi bi-person-gear"></i> <span>Mi cuenta</span>
                        </a>
                        <a class="dropdown-item py-2 d-flex align-items-center gap-2 user-profile-item" href="{{ route('notifications.index') }}">
                            <i class="bi bi-bell"></i> <span>Notificaciones</span>
                            @if($unreadCount > 0)
                                <span class="badge bg-danger ms-auto">{{ $unreadCount }}</span>
                            @endif
                        </a>
                        <div class="dropdown-divider user-profile-divider"></div>
                        <form method="POST" action="{{ route('logout') }}" class="m-0">
                            @csrf
                            <button type="submit" class="dropdown-item py-2 d-flex align-items-center gap-2 text-danger user-profile-logout">
                                <i class="bi bi-box-arrow-right"></i> <span>Cerrar sesión</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main id="main-content" class="panel-content">
            @if(($globalView ?? false) && !request()->routeIs('dashboard*') && !request()->routeIs('attendance.attempts*') && !request()->routeIs('approvals*') && !request()->routeIs('onboarding*') && !request()->routeIs('documents*') && !request()->routeIs('settings*'))
                <div class="alert alert-primary d-flex align-items-center gap-2 global-view-banner mb-4" role="status" style="border-radius: 10px;">
                    <i class="bi bi-globe2 fs-5 global-view-icon"></i>
                    <div>Vista global: mostrando registros de todas las empresas. Para crear o editar, elige una empresa en el selector superior.</div>
                </div>
            @endif

            {{-- Avisos flotantes (toasts): arriba a la derecha, no empujan el contenido --}}
            <div class="toast-stack" aria-live="polite">
            @foreach(['success' => 'success', 'info' => 'info', 'error' => 'danger'] as $key => $variant)
                @if(session($key))
                    <div class="alert alert-{{ $variant }} toast-item toast-{{ $variant }}" role="alert">
                        <div class="toast-icon-badge toast-badge-{{ $variant }}">
                            <i class="bi {{ $variant === 'success' ? 'bi-check-lg' : ($variant === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill') }}"></i>
                        </div>
                        <div class="toast-content">
                            <strong class="toast-title">{{ ['success' => 'Listo', 'info' => 'Aviso', 'error' => 'Atención'][$key] }}</strong>
                            <span class="toast-text">{{ session($key) }}</span>
                        </div>
                        <button type="button" class="toast-close-btn" data-bs-dismiss="alert" aria-label="Cerrar">
                            <i class="bi bi-x-lg" style="font-size: 0.75rem;"></i>
                        </button>
                    </div>
                @endif
            @endforeach

            @if($errors->any())
                <div class="alert alert-danger toast-item toast-danger" role="alert">
                    <div class="toast-icon-badge toast-badge-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>
                    <div class="toast-content">
                        <strong class="toast-title">Revisa los datos</strong>
                        <span class="toast-text">{{ $errors->first() }}</span>
                    </div>
                    <button type="button" class="toast-close-btn" data-bs-dismiss="alert" aria-label="Cerrar">
                        <i class="bi bi-x-lg" style="font-size: 0.75rem;"></i>
                    </button>
                </div>
            @endif
            </div>

            @yield('content')
        </main>
    </div>
</div>

{{-- Modal Global de Búsqueda Rápida / Command Palette (Ctrl+K) --}}
<div class="modal fade" id="commandPaletteModal" tabindex="-1" aria-labelledby="commandPaletteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content command-palette-content">
            <div class="command-palette-header">
                <i class="bi bi-search command-palette-search-icon"></i>
                <input type="text" id="commandPaletteInput" class="command-palette-input" placeholder="Buscar módulo, colaborador, sede o reporte..." autocomplete="off">
                <kbd class="command-palette-kbd-esc">ESC</kbd>
            </div>
            <div class="command-palette-body" id="commandPaletteResults">
                <div class="command-palette-section-title">Navegación del Sistema</div>
                <div class="command-palette-list" id="commandPaletteList">
                    @foreach(\App\Modules\Core\Support\PanelNavigation::for($navUser, request()) as $sec)
                        @foreach($sec['items'] as $item)
                            <a href="{{ $item['href'] }}" class="command-palette-item" data-label="{{ strtolower($item['label'] . ' ' . $sec['heading']) }}">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="command-item-icon"><i class="bi bi-{{ $item['icon'] }}"></i></span>
                                    <div>
                                        <div class="command-item-title">{{ $item['label'] }}</div>
                                        <div class="command-item-sub">{{ $sec['heading'] }}</div>
                                    </div>
                                </div>
                                <i class="bi bi-chevron-right command-item-arrow"></i>
                            </a>
                        @endforeach
                    @endforeach
                    <a href="{{ route('account.edit') }}" class="command-palette-item" data-label="mi cuenta perfil usuario credenciales">
                        <div class="d-flex align-items-center gap-3">
                            <span class="command-item-icon"><i class="bi bi-person-gear"></i></span>
                            <div>
                                <div class="command-item-title">Mi cuenta</div>
                                <div class="command-item-sub">Perfil y preferencias del usuario</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right command-item-arrow"></i>
                    </a>
                    <a href="{{ route('notifications.index') }}" class="command-palette-item" data-label="notificaciones avisos bandeja alertas">
                        <div class="d-flex align-items-center gap-3">
                            <span class="command-item-icon"><i class="bi bi-bell"></i></span>
                            <div>
                                <div class="command-item-title">Notificaciones</div>
                                <div class="command-item-sub">Avisos y solicitudes del ERP</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right command-item-arrow"></i>
                    </a>
                </div>
                <div id="commandPaletteEmpty" class="text-center py-4 d-none text-muted small">
                    <i class="bi bi-search fs-3 d-block mb-1 text-secondary"></i>
                    No se encontraron coincidencias para su búsqueda.
                </div>
            </div>
            <div class="command-palette-footer">
                <span><kbd class="command-palette-kbd">↑</kbd> <kbd class="command-palette-kbd">↓</kbd> para navegar</span>
                <span><kbd class="command-palette-kbd">ENTER</kbd> para seleccionar</span>
            </div>
        </div>
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

{{-- Diálogo Guardián de Sesión por Inactividad --}}
@include('layouts.partials.session-expiry-modal')

<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
<script src="{{ asset('js/panel.js') }}"></script>

@yield('scripts')
@stack('scripts')
</body>
</html>
