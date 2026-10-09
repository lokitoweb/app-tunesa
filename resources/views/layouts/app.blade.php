<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel') — TuneSA</title>

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}">

    @php
        // Empresa activa (la del usuario, o la que eligió el Super Admin)
        $brand = app(\App\Services\TenantContext::class)->getOrNull() ?? Auth::user()?->tenant;
        $brandColor = $brand && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $brand->primary_color) ? $brand->primary_color : '#1E40AF';
        [$br, $bg, $bb] = sscanf($brandColor, '#%02x%02x%02x');
    @endphp
    <style>
        /* Marca blanca: color corporativo de la empresa */
        :root { --brand: {{ $brandColor }}; --brand-rgb: {{ $br }}, {{ $bg }}, {{ $bb }}; }
    </style>

    @yield('styles')
</head>
<body>
@php
    $navUser = Auth::user();
    // Sin empresa activa (Super Admin en el consolidado) no hay contadores por empresa
    $hasTenant = app(\App\Services\TenantContext::class)->resolved();
    $pendingApprovals = ! $hasTenant ? 0 : ($navUser->can('leave.approve_all')
        ? \App\Models\LeaveRequest::where('status', 'pending')->count()
            + ($navUser->can('attendance.review') ? \App\Models\Attendance::where('justification_status', 'pending')->count() : 0)
        : ($navUser->can('leave.approve_team')
            ? \App\Models\LeaveRequest::where('status', 'pending')->whereHas('employee.branches', fn ($q) => $q->where('branches.supervisor_id', $navUser->id))->count()
            : 0));
    $pendingDocs = $hasTenant && $navUser->can('documents.review')
        ? \App\Models\EmployeeDocument::where('status', 'pending')->count()
        : 0;
    $nav = function (string $route, array $params, string $icon, string $label, bool $active, int $badge = 0) {
        return view('layouts.partials.nav-link', compact('route', 'params', 'icon', 'label', 'active', 'badge'));
    };
@endphp

<div class="panel-shell">
    {{-- SIDEBAR: fijo en escritorio (lg+), offcanvas en móvil --}}
    <aside id="sidebar" class="offcanvas-lg offcanvas-start panel-sidebar" tabindex="-1" aria-labelledby="sidebarLabel">
        <div class="sidebar-brand">
            <a href="{{ route('dashboard') }}" class="d-flex align-items-center gap-2 text-decoration-none">
                @if($brand?->logo_path)
                    <img src="{{ asset('storage/' . $brand->logo_path) }}" alt="" class="brand-logo">
                @else
                    <span class="brand-mark"><i class="bi bi-geo-alt-fill"></i></span>
                @endif
                <span class="lh-sm">
                    <span id="sidebarLabel" class="d-block fw-semibold text-body text-truncate" style="max-width: 160px;">{{ $brand->company_name ?? 'TuneSA' }}</span>
                    <small class="text-body-secondary">Asistencia y RRHH</small>
                </span>
            </a>
            <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Cerrar menú"></button>
        </div>

        <nav class="sidebar-nav" aria-label="Menú principal">
            <div class="nav-heading">Monitoreo</div>
            {!! $nav('dashboard', [], 'speedometer2', 'Dashboard', request()->routeIs('dashboard*')) !!}

            @can('attendance.view_all')
                {!! $nav('attendance.index', [], 'clock-history', 'Marcaciones y GPS', request()->routeIs('attendance*') && request('status') !== 'absent') !!}
                {!! $nav('attendance.index', ['status' => 'absent', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->toDateString()], 'person-x', 'Control de faltas', request()->routeIs('attendance*') && request('status') === 'absent') !!}
            @endcan

            @can('leave.approve_team')
                {!! $nav('approvals.index', [], 'check2-square', 'Aprobaciones', request()->routeIs('approvals*'), $pendingApprovals) !!}
            @endcan

            @if($navUser->can('users.manage') || $navUser->can('shifts.manage') || $navUser->can('branches.manage') || $navUser->can('payslips.manage'))
                <div class="nav-heading">Administración</div>
            @endif
            @can('users.manage')
                {!! $nav('employees.index', [], 'people', 'Colaboradores', request()->routeIs('employees.index', 'employees.create', 'employees.edit')) !!}
            @endcan
            @can('shifts.manage')
                {!! $nav('shifts.index', [], 'calendar-week', 'Turnos y horarios', request()->routeIs('shifts*')) !!}
            @endcan
            @can('branches.manage')
                {!! $nav('branches.index', [], 'pin-map', 'Sucursales y geocercas', request()->routeIs('branches*')) !!}
            @endcan
            @can('payslips.manage')
                {!! $nav('payslips.index', [], 'file-earmark-text', 'Boletas de pago', request()->routeIs('payslips*')) !!}
            @endcan

            @if($navUser->can('documents.review') || $navUser->can('uniforms.manage') || $navUser->can('payroll.export') || $navUser->can('company.configure') || $navUser->can('reports.view'))
                <div class="nav-heading">Legajo y planilla</div>
            @endif
            @can('documents.review')
                {!! $nav('documents.index', [], 'folder-check', 'Legajos digitales', request()->routeIs('documents*', 'employees.file*'), $pendingDocs) !!}
            @endcan
            @can('uniforms.manage')
                {!! $nav('uniforms.index', [], 'bag', 'Dotación y tallas', request()->routeIs('uniforms*')) !!}
            @endcan
            @can('payroll.export')
                {!! $nav('payroll.index', [], 'cash-stack', 'Cruce con planilla', request()->routeIs('payroll*')) !!}
            @endcan
            @can('reports.view')
                {!! $nav('reports.index', [], 'bar-chart-line', 'Reportes', request()->routeIs('reports*')) !!}
            @endcan
            @can('company.configure')
                {!! $nav('settings.mobile', [], 'phone', 'Configuración de la app', request()->routeIs('settings.mobile*')) !!}
            @endcan
        </nav>

        <div class="sidebar-footer">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="avatar avatar-sm">{{ strtoupper(mb_substr($navUser->first_name ?? 'U', 0, 1) . mb_substr($navUser->last_name ?? '', 0, 1)) }}</span>
                <div class="overflow-hidden">
                    <div class="small fw-semibold text-truncate">{{ $navUser->first_name }} {{ $navUser->last_name }}</div>
                    <div class="small text-body-secondary text-capitalize">{{ str_replace('_', ' ', $navUser->role) }}</div>
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i> Cerrar sesión</button>
            </form>
        </div>
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
                        <select name="tenant_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Empresa a administrar" style="max-width: 240px;">
                            <option value="">Consolidado de la plataforma</option>
                            @foreach(\App\Models\Tenant::orderBy('company_name')->get(['id', 'company_name']) as $t)
                                <option value="{{ $t->id }}" @selected($brand?->id === $t->id)>{{ $t->company_name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="small text-body-secondary d-none d-md-inline">{{ now()->isoFormat('dddd D [de] MMMM') }}</span>
                <button type="button" class="btn btn-light border btn-sm" id="themeToggle" aria-label="Cambiar tema claro u oscuro" title="Tema claro / oscuro">
                    <i class="bi bi-moon-stars"></i>
                </button>
            </div>
        </header>

        <main id="main-content" class="panel-content">
            @foreach(['success' => 'success', 'info' => 'info', 'error' => 'danger'] as $key => $variant)
                @if(session($key))
                    <div class="alert alert-{{ $variant }} alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
                        <i class="bi {{ $variant === 'success' ? 'bi-check-circle' : ($variant === 'danger' ? 'bi-exclamation-octagon' : 'bi-info-circle') }}"></i>
                        <div>{{ session($key) }}</div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                    </div>
                @endif
            @endforeach

            @if($errors->any())
                <div class="alert alert-danger" role="alert">
                    <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Revisa los datos del formulario</div>
                    <ul class="mb-0 small">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

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
