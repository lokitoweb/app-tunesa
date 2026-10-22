@php
    try {
        $errBrand = app(\App\Modules\Core\Services\TenantContext::class)->getOrNull() ?? Auth::user()?->tenant;
    } catch (\Throwable) {
        $errBrand = null; // sin base de datos: la página de error igual se muestra
    }
    $errBrandSettings = \App\Modules\Core\Models\TenantSetting::forTenantOrDefaults($errBrand);

    // Sesión iniciada (sin romper la página si la base de datos no responde)
    try {
        $errAuthed = Auth::check();
    } catch (\Throwable) {
        $errAuthed = false;
    }
    $errHomeUrl   = $errAuthed ? route('dashboard') : route('login');
    $errHomeLabel = $errAuthed ? 'Ir al inicio' : 'Iniciar sesión';
    $errHomeIcon  = $errAuthed ? 'bi-house-door-fill' : 'bi-box-arrow-in-right';

    // Código de referencia real: el mismo que queda en storage/logs (RequestReference)
    $errRef = request()->attributes->get('ref') ?? strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
@endphp
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Error') — {{ $errBrandSettings->tradeName() }}</title>

    {{-- Detección instantánea de tema guardado (light / dark) sin parpadeos --}}
    @include('layouts.partials.theme-boot')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">

    @include('layouts.partials.brand-head', ['brandSettings' => $errBrandSettings])

    <style>
        :root,
        [data-bs-theme="light"] {
            --err-bg: #f8fafc;
            --err-card-bg: rgba(255, 255, 255, 0.92);
            --err-card-border: rgba(226, 232, 240, 0.9);
            --err-text-title: #0f172a;
            --err-text-body: #475569;
            --err-text-muted: #94a3b8;
            --err-surface-subtle: #f1f5f9;
            --err-btn-primary: #0284c7;
            --err-btn-primary-hover: #0369a1;
            --err-shadow: 0 20px 45px -10px rgba(15, 23, 42, 0.08), 0 0 0 1px rgba(15, 23, 42, 0.04);
            --err-ambient-glow: radial-gradient(circle at 50% 30%, rgba(2, 132, 199, 0.09) 0%, rgba(248, 250, 252, 0) 70%);
        }

        [data-bs-theme="dark"] {
            --err-bg: #090d16;
            --err-card-bg: rgba(17, 24, 39, 0.85);
            --err-card-border: rgba(30, 41, 59, 0.85);
            --err-text-title: #f8fafc;
            --err-text-body: #94a3b8;
            --err-text-muted: #64748b;
            --err-surface-subtle: #141c2e;
            --err-btn-primary: #0284c7;
            --err-btn-primary-hover: #0369a1;
            --err-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.55), 0 0 0 1px rgba(255, 255, 255, 0.06);
            --err-ambient-glow: radial-gradient(circle at 50% 30%, rgba(56, 189, 248, 0.12) 0%, rgba(9, 13, 22, 0) 70%);
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: var(--err-bg);
            background-image: var(--err-ambient-glow);
            background-attachment: fixed;
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: var(--err-text-body);
            transition: background-color 0.2s ease, color 0.2s ease;
            position: relative;
            overflow-x: hidden;
        }

        /* Barra de Encabezado Superior */
        .err-topbar {
            width: 100%;
            padding: 1.25rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 10;
        }

        .err-brand {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            transition: opacity 0.15s ease;
        }
        .err-brand:hover {
            opacity: 0.9;
        }

        .err-brand-logo {
            height: 38px;
            max-width: 175px;
            object-fit: contain;
            display: block;
        }

        .err-theme-toggle {
            background: var(--err-surface-subtle);
            border: 1px solid var(--err-card-border);
            color: var(--err-text-body);
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            font-size: 0.95rem;
        }
        .err-theme-toggle:hover {
            color: var(--err-text-title);
            transform: scale(1.05);
        }

        /* Contenedor Principal */
        .err-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.25rem;
            position: relative;
            z-index: 5;
        }

        /* Tarjeta de Error Glassmorphism */
        .err-card {
            width: 100%;
            max-width: 580px;
            background: var(--err-card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--err-card-border);
            border-radius: 20px;
            padding: 2.75rem 2.5rem;
            text-align: center;
            box-shadow: var(--err-shadow);
            position: relative;
            animation: errFadeIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes errFadeIn {
            from {
                opacity: 0;
                transform: translateY(18px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Badge Superior de Código de Estado */
        .err-badge-wrap {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.775rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 1.5rem;
            background: var(--err-badge-bg, rgba(2, 132, 199, 0.12));
            color: var(--err-badge-color, #0284c7);
            border: 1px solid var(--err-badge-border, rgba(2, 132, 199, 0.25));
        }

        /* Ícono Heroico Central */
        .err-icon-circle {
            width: 88px;
            height: 88px;
            border-radius: 24px;
            margin: 0 auto 1.5rem auto;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.75rem;
            position: relative;
            background: var(--err-icon-bg, rgba(2, 132, 199, 0.1));
            color: var(--err-icon-color, #0284c7);
            border: 1px solid var(--err-icon-border, rgba(2, 132, 199, 0.25));
            box-shadow: 0 10px 25px -5px var(--err-icon-shadow, rgba(2, 132, 199, 0.2));
            transition: transform 0.25s ease;
        }
        .err-icon-circle:hover {
            transform: scale(1.06) rotate(-2deg);
        }

        /* Tipografía del Error */
        .err-title {
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--err-text-title);
            letter-spacing: -0.02em;
            line-height: 1.25;
            margin-bottom: 0.75rem;
        }

        .err-desc {
            font-size: 0.9rem;
            line-height: 1.55;
            color: var(--err-text-body);
            margin: 0 auto 1.75rem auto;
            max-width: 460px;
        }

        /* Grupo de Acciones */
        .err-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1.75rem;
        }

        .err-btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.35rem;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 9px;
            background: var(--err-btn-primary);
            border: 1px solid rgba(56, 189, 248, 0.3);
            color: #ffffff !important;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
            transition: all 0.15s ease;
            cursor: pointer;
        }
        .err-btn-primary:hover {
            background: var(--err-btn-primary-hover);
            border-color: rgba(56, 189, 248, 0.5);
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35);
        }

        .err-btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.25rem;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 9px;
            background: var(--err-surface-subtle);
            border: 1px solid var(--err-card-border);
            color: var(--err-text-body) !important;
            text-decoration: none;
            transition: all 0.15s ease;
            cursor: pointer;
        }
        .err-btn-secondary:hover {
            background: var(--err-card-border);
            color: var(--err-text-title) !important;
            transform: translateY(-1px);
        }

        /* Barra de Telemetría y Referencia */
        .err-telemetry {
            background: var(--err-surface-subtle);
            border: 1px solid var(--err-card-border);
            border-radius: 10px;
            padding: 0.65rem 1rem;
            font-size: 0.75rem;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            color: var(--err-text-muted);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        .err-telemetry-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .err-copy {
            background: none;
            border: 0;
            padding: 0 0.15rem;
            color: inherit;
            cursor: pointer;
            opacity: 0.75;
        }
        .err-copy:hover { opacity: 1; }
        .err-hint {
            margin: 0.85rem 0 0;
            font-size: 0.78rem;
            color: var(--err-text-muted);
        }

        @media (max-width: 575.98px) {
            .err-topbar, .err-footer { padding: 1rem; }
            .err-card { padding: 2rem 1.25rem; border-radius: 16px; }
            .err-title { font-size: 1.25rem; }
            .err-actions > * { flex: 1 1 100%; justify-content: center; }
            .err-telemetry { justify-content: center; }
        }

        @media (prefers-reduced-motion: reduce) {
            .err-card { animation: none; }
        }

        /* Footer Inferior */
        .err-footer {
            width: 100%;
            padding: 1.25rem 2rem;
            text-align: center;
            font-size: 0.775rem;
            color: var(--err-text-muted);
            z-index: 10;
        }
    </style>

    @yield('styles')
</head>
<body>
    {{-- 1. Barra Superior --}}
    <header class="err-topbar">
        <a href="{{ $errHomeUrl }}" class="err-brand" title="{{ $errBrandSettings->tradeName() }}">
            @if($errBrandSettings->usesDefaultLogo())
                <img src="{{ asset('img/grupo-negreiros-logo.png') }}" alt="{{ $errBrandSettings->tradeName() }}" class="err-brand-logo brand-logo-light">
                <img src="{{ asset('img/grupo-negreiros-logo-white.png') }}" alt="{{ $errBrandSettings->tradeName() }}" class="err-brand-logo brand-logo-dark">
            @else
                <img src="{{ $errBrandSettings->displayLogoUrl() }}" alt="{{ $errBrandSettings->tradeName() }}" class="err-brand-logo brand-logo-light {{ $errBrandSettings->logoDarkUrl() ? 'has-dark' : '' }}">
                @if($darkLogo = $errBrandSettings->logoDarkUrl())
                    <img src="{{ $darkLogo }}" alt="{{ $errBrandSettings->tradeName() }}" class="err-brand-logo brand-logo-dark">
                @endif
            @endif
        </a>

        <button type="button" class="err-theme-toggle" id="errThemeToggle" title="Alternar Modo Oscuro / Claro" aria-label="Cambiar tema visual">
            <i class="bi bi-moon-stars-fill" id="errThemeIcon"></i>
        </button>
    </header>

    {{-- 2. Área Central con Card de Error --}}
    <main class="err-main">
        <div class="err-card">
            {{-- Badge de Código --}}
            <div class="err-badge-wrap">
                <i class="bi bi-exclamation-octagon-fill"></i>
                <span>Código HTTP @yield('code')</span>
            </div>

            {{-- Ícono Principal --}}
            <div class="err-icon-circle">
                @yield('icon')
            </div>

            {{-- Título y Mensaje --}}
            <h1 class="err-title">@yield('headline')</h1>
            <p class="err-desc">
                @yield('message')
            </p>

            {{-- Botones de Acción --}}
            <div class="err-actions">
                @section('actions')
                    <a href="{{ $errHomeUrl }}" class="err-btn-primary">
                        <i class="bi {{ $errHomeIcon }}"></i>
                        <span>{{ $errHomeLabel }}</span>
                    </a>
                    <button type="button" onclick="history.length > 1 ? history.back() : (location.href = @js($errHomeUrl))" class="err-btn-secondary">
                        <i class="bi bi-arrow-left"></i>
                        <span>Regresar</span>
                    </button>
                @show
            </div>

            {{-- Telemetría de Diagnóstico --}}
            <div class="err-telemetry">
                <span class="err-telemetry-tag">
                    <i class="bi bi-fingerprint"></i>
                    <span>Ref: <strong id="errRef">{{ $errRef }}</strong></span>
                    <button type="button" class="err-copy" id="errCopyRef" title="Copiar código de referencia" aria-label="Copiar código de referencia">
                        <i class="bi bi-copy"></i>
                    </button>
                </span>
                <span class="err-telemetry-tag">
                    <i class="bi bi-clock"></i>
                    <span>{{ now()->format('d/m/Y H:i:s') }}</span>
                </span>
                <span class="err-telemetry-tag">
                    <i class="bi bi-link-45deg"></i>
                    <span>/{{ \Illuminate\Support\Str::limit(ltrim(request()->path(), '/'), 25) }}</span>
                </span>
            </div>
            <p class="err-hint">@yield('hint', 'Si el problema continúa, envía este código de referencia a soporte: nos permite encontrar exactamente qué pasó.')</p>
        </div>
    </main>

    {{-- 3. Pie de Página --}}
    <footer class="err-footer">
        <div>&copy; {{ date('Y') }} {{ $errBrandSettings->tradeName() }}. Sistema de Gestión y Asistencia Empresarial.</div>
    </footer>

    {{-- Script de Alternancia de Tema --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toggleBtn = document.getElementById('errThemeToggle');
            const icon = document.getElementById('errThemeIcon');

            function syncThemeIcon() {
                const current = document.documentElement.getAttribute('data-bs-theme') || 'light';
                if (icon) {
                    icon.className = current === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
                }
            }

            syncThemeIcon();

            const copyBtn = document.getElementById('errCopyRef');
            if (copyBtn && navigator.clipboard) {
                copyBtn.addEventListener('click', function () {
                    navigator.clipboard.writeText(document.getElementById('errRef').textContent.trim()).then(function () {
                        copyBtn.innerHTML = '<i class="bi bi-check2"></i>';
                        setTimeout(function () { copyBtn.innerHTML = '<i class="bi bi-copy"></i>'; }, 1800);
                    }).catch(function () {});
                });
            }

            if (toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    const current = document.documentElement.getAttribute('data-bs-theme') || 'light';
                    const next = current === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-bs-theme', next);
                    try {
                        localStorage.setItem('panel-theme', next);
                    } catch (e) {}
                    syncThemeIcon();
                });
            }
        });
    </script>
</body>
</html>
