<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel') — TuneSA SaaS</title>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Leaflet Map CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>

    <!-- Panel Design System -->
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}">

    @yield('styles')
</head>
<body>
    <div class="app-layout">
        <!-- SIDEBAR NAVEGACIÓN -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="{{ route('dashboard') }}" class="brand-logo" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 12px;">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); display: flex; align-items: center; justify-content: center; color: #fff; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            <circle cx="12" cy="11" r="3"/>
                        </svg>
                    </div>
                    <div>
                        <div style="font-size: 1.15rem; font-weight: 800; letter-spacing: -0.5px; color: #fff;">TuneSA</div>
                        <div style="font-size: 0.68rem; text-transform: uppercase; color: #818cf8; font-weight: 600; letter-spacing: 0.5px;">Control Geocercas</div>
                    </div>
                </a>
            </div>

            <!-- Menú Principal -->
            <nav class="sidebar-nav">
                <div class="nav-section-title">MONITOREO & ASISTENCIA</div>

                <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard*') ? 'active' : '' }}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    <span>Dashboard en Vivo</span>
                </a>

                <a href="{{ route('attendance.index') }}" class="nav-item {{ request()->routeIs('attendance*') ? 'active' : '' }}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Marcaciones & GPS</span>
                </a>

                <div class="nav-section-title">ADMINISTRACIÓN & RRHH</div>

                <a href="{{ route('employees.index') }}" class="nav-item {{ request()->routeIs('employees*') ? 'active' : '' }}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Colaboradores</span>
                </a>

                <a href="{{ route('branches.index') }}" class="nav-item {{ request()->routeIs('branches*') ? 'active' : '' }}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span>Sucursales & Geocercas</span>
                </a>

                <a href="{{ route('payslips.index') }}" class="nav-item {{ request()->routeIs('payslips*') ? 'active' : '' }}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    <span>Boletas de Pago</span>
                </a>

                <a href="{{ route('reports.index') }}" class="nav-item {{ request()->routeIs('reports*') ? 'active' : '' }}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>
                    <span>Reportes & Nómina</span>
                </a>
            </nav>

            <!-- Tenant & Perfil Info -->
            <div class="sidebar-footer" style="padding: 1.25rem; border-top: 1px solid var(--border); background: rgba(0,0,0,0.2);">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 0.75rem;">
                    <div style="width: 36px; height: 36px; border-radius: 50%; background: #3730a3; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #c7d2fe; font-size: 0.85rem;">
                        {{ strtoupper(substr(Auth::user()->first_name ?? 'U', 0, 1)) }}{{ strtoupper(substr(Auth::user()->last_name ?? 'A', 0, 1)) }}
                    </div>
                    <div style="overflow: hidden;">
                        <div style="font-size: 0.85rem; font-weight: 600; color: #fff; white-space: nowrap; text-overflow: ellipsis; overflow: hidden;">
                            {{ Auth::user()->first_name ?? 'Usuario' }} {{ Auth::user()->last_name ?? '' }}
                        </div>
                        <div style="font-size: 0.72rem; color: #818cf8; text-transform: uppercase;">
                            {{ str_replace('_', ' ', Auth::user()->role ?? 'Admin') }}
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" style="width: 100%; padding: 0.5rem; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 8px; color: #f87171; font-size: 0.8rem; font-weight: 500; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all 0.2s;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                        Cerrar Sesión
                    </button>
                </form>
            </div>
        </aside>

        <!-- CONTENIDO PRINCIPAL -->
        <main class="main-wrapper">
            <!-- Topbar -->
            <header class="topbar" style="height: var(--header-h); border-bottom: 1px solid var(--border); background: rgba(17, 18, 24, 0.8); backdrop-filter: blur(12px); display: flex; align-items: center; justify-content: space-between; padding: 0 2rem; position: sticky; top: 0; z-index: 100;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(99, 102, 241, 0.15); color: #818cf8; padding: 4px 10px; border-radius: 6px; border: 1px solid rgba(99, 102, 241, 0.25); font-weight: 600;">
                        Tenant: {{ Auth::user()->tenant->name ?? 'Empresa Activa' }}
                    </span>
                    <span style="color: var(--text-muted);">•</span>
                    <span style="font-size: 0.85rem; color: var(--text-secondary);" id="liveClock">
                        {{ date('d M, Y') }}
                    </span>
                </div>

                <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: #10b981;">
                        <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 10px #10b981; display: inline-block;"></span>
                        GPS Engine Online
                    </div>
                </div>
            </header>

            <!-- Alertas Flash -->
            <div style="padding: 1.5rem 2rem 0 2rem;">
                @if (session('success'))
                    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 12px; padding: 1rem 1.25rem; color: #6ee7b7; font-size: 0.9rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 10px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <span>{{ session('success') }}</span>
                    </div>
                @endif

                @if (session('error'))
                    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 12px; padding: 1rem 1.25rem; color: #fca5a5; font-size: 0.9rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 10px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/></svg>
                        <span>{{ session('error') }}</span>
                    </div>
                @endif
            </div>

            <!-- Contenido de la vista -->
            <div class="content-container" style="padding: 1.5rem 2rem 3rem 2rem;">
                @yield('content')
            </div>
        </main>
    </div>

    <!-- MODAL GLOBAL DE AUDITORÍA DE SELFIE / FOTO -->
    <div id="photoModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 2rem;">
        <div style="position: relative; max-width: 600px; width: 100%; background: #111218; border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; overflow: hidden; box-shadow: 0 25px 60px rgba(0,0,0,0.8);">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border);">
                <div style="font-weight: 700; color: #fff; font-size: 1rem;" id="photoModalTitle">Evidencia Fotográfica de Marcación</div>
                <button onclick="closePhotoModal()" style="background: transparent; border: none; color: #94a3b8; cursor: pointer; font-size: 1.5rem; line-height: 1;">&times;</button>
            </div>
            <div style="padding: 1.5rem; text-align: center; background: #07080b;">
                <img id="photoModalImg" src="" alt="Selfie de asistencia" style="max-height: 480px; max-width: 100%; border-radius: 12px; object-fit: contain; box-shadow: 0 10px 30px rgba(0,0,0,0.6);">
            </div>
            <div style="padding: 1rem 1.5rem; font-size: 0.85rem; color: #94a3b8; border-top: 1px solid var(--border); display: flex; justify-content: space-between;" id="photoModalMeta">
                <!-- Metadatos dinámicos -->
            </div>
        </div>
    </div>

    <!-- Scripts Leaflet y Chart.js -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        function openPhotoModal(imgUrl, title, meta) {
            document.getElementById('photoModalImg').src = imgUrl;
            document.getElementById('photoModalTitle').innerText = title || 'Evidencia de Marcación';
            document.getElementById('photoModalMeta').innerHTML = meta || '';
            document.getElementById('photoModal').style.display = 'flex';
        }
        function closePhotoModal() {
            document.getElementById('photoModal').style.display = 'none';
        }
        // Cerrar con Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closePhotoModal();
        });
    </script>

    @yield('scripts')
</body>
</html>
