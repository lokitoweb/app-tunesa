@php
    try {
        $errBrand = app(\App\Modules\Core\Services\TenantContext::class)->getOrNull() ?? Auth::user()?->tenant;
    } catch (\Throwable) {
        $errBrand = null; // sin base de datos: la página de error igual se muestra
    }
    $errBrandSettings = \App\Modules\Core\Models\TenantSetting::forTenantOrDefaults($errBrand);

    // Logo claro y, si existe, uno distinto para el tema oscuro (igual que en el login)
    $errLogo     = $errBrandSettings->displayLogoUrl();
    $errLogoDark = $errBrandSettings->usesDefaultLogo() ? asset('img/grupo-negreiros-logo-white.png') : $errBrandSettings->logoDarkUrl();
    $errHasDark  = $errLogoDark && $errLogoDark !== $errLogo;

    // Sesión iniciada (sin romper la página si la base de datos no responde)
    try {
        $errAuthed = Auth::check();
    } catch (\Throwable) {
        $errAuthed = false;
    }
    $errHomeUrl   = $errAuthed ? route('dashboard') : route('login');
    $errHomeLabel = $errAuthed ? 'Ir al inicio' : 'Iniciar sesión';
    $errHomeIcon  = $errAuthed ? 'bi-house-door' : 'bi-box-arrow-in-right';

    // Código de referencia real: el mismo que queda en storage/logs (RequestReference)
    $errRef = request()->attributes->get('ref') ?? strtoupper(bin2hex(random_bytes(4)));
@endphp
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Error') — {{ $errBrandSettings->tradeName() }}</title>

    {{-- Tema guardado (claro / oscuro) antes de pintar, sin parpadeo --}}
    @include('layouts.partials.theme-boot')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">

    @include('layouts.partials.brand-head', ['brandSettings' => $errBrandSettings])

    <style>
        :root, [data-bs-theme="light"] {
            --e-bg: #f5f6f8;
            --e-surface: #ffffff;
            --e-border: #e4e7ec;
            --e-divider: #eef0f3;
            --e-title: #101828;
            --e-text: #475467;
            --e-muted: #667085;
            --e-subtle: #f9fafb;
            --e-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 12px 32px -12px rgba(16, 24, 40, .12);
            --e-focus: rgba(var(--brand-rgb), .35);
        }
        [data-bs-theme="dark"] {
            --e-bg: #0b0e14;
            --e-surface: #121620;
            --e-border: #222836;
            --e-divider: #1b202b;
            --e-title: #f2f4f7;
            --e-text: #a9b1c1;
            --e-muted: #7c8597;
            --e-subtle: #0f131b;
            --e-shadow: 0 1px 2px rgba(0, 0, 0, .3), 0 16px 40px -16px rgba(0, 0, 0, .6);
        }

        /* Tono por tipo de error (lo elige cada página en su sección «tone») */
        .tone-info    { --t: #2563eb; --t-bg: rgba(37, 99, 235, .08);  --t-bd: rgba(37, 99, 235, .2); }
        .tone-warning { --t: #b45309; --t-bg: rgba(217, 119, 6, .08);  --t-bd: rgba(217, 119, 6, .22); }
        .tone-danger  { --t: #b42318; --t-bg: rgba(217, 45, 32, .07);  --t-bd: rgba(217, 45, 32, .2); }
        .tone-neutral { --t: #475467; --t-bg: rgba(71, 84, 103, .08);  --t-bd: rgba(71, 84, 103, .2); }
        [data-bs-theme="dark"] .tone-info    { --t: #7aa7ff; --t-bg: rgba(122, 167, 255, .1); --t-bd: rgba(122, 167, 255, .25); }
        [data-bs-theme="dark"] .tone-warning { --t: #f5b454; --t-bg: rgba(245, 180, 84, .1);  --t-bd: rgba(245, 180, 84, .25); }
        [data-bs-theme="dark"] .tone-danger  { --t: #f97066; --t-bg: rgba(249, 112, 102, .1); --t-bd: rgba(249, 112, 102, .25); }
        [data-bs-theme="dark"] .tone-neutral { --t: #a9b1c1; --t-bg: rgba(169, 177, 193, .08); --t-bd: rgba(169, 177, 193, .2); }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; }
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 15px;
            line-height: 1.6;
            color: var(--e-text);
            background: var(--e-bg);
            -webkit-font-smoothing: antialiased;
        }
        /* Franja fina con el color de la empresa */
        body::before { content: ""; height: 3px; background: var(--brand); flex: none; }

        .e-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 32px;
        }
        .e-brand { display: inline-flex; align-items: center; }
        .e-brand img { height: 34px; max-width: 180px; object-fit: contain; display: block; }

        .e-icon-btn {
            width: 38px;
            height: 38px;
            display: inline-grid;
            place-items: center;
            border-radius: 10px;
            border: 1px solid var(--e-border);
            background: var(--e-surface);
            color: var(--e-text);
            cursor: pointer;
            transition: color .15s, border-color .15s;
        }
        .e-icon-btn:hover { color: var(--e-title); border-color: var(--e-muted); }

        .e-main {
            flex: 1;
            display: grid;
            place-items: center;
            padding: 24px 20px 40px;
        }
        .e-card {
            width: 100%;
            max-width: 560px;
            background: var(--e-surface);
            border: 1px solid var(--e-border);
            border-radius: 16px;
            box-shadow: var(--e-shadow);
            overflow: hidden;
            animation: e-in .3s ease-out both;
        }
        @keyframes e-in { from { opacity: 0; transform: translateY(8px); } }

        .e-body { padding: 40px 44px 32px; }

        .e-head { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; }
        .e-icon {
            width: 48px;
            height: 48px;
            flex: none;
            display: grid;
            place-items: center;
            border-radius: 12px;
            font-size: 22px;
            color: var(--t);
            background: var(--t-bg);
            border: 1px solid var(--t-bd);
        }
        .e-code {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--t);
        }
        .e-code-sub { display: block; font-size: 13px; font-weight: 500; letter-spacing: 0; text-transform: none; color: var(--e-muted); }

        .e-title {
            margin: 0 0 10px;
            font-size: 24px;
            line-height: 1.3;
            font-weight: 700;
            letter-spacing: -.015em;
            color: var(--e-title);
        }
        .e-text { margin: 0; color: var(--e-text); }

        .e-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 28px; }
        .e-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 42px;
            padding: 0 18px;
            border-radius: 10px;
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background-color .15s, border-color .15s, color .15s, opacity .15s;
        }
        .e-btn:focus-visible { outline: 3px solid var(--e-focus); outline-offset: 2px; }
        .e-btn[disabled] { opacity: .55; cursor: not-allowed; }
        .e-btn-primary { background: var(--brand); color: var(--brand-text); border: 1px solid var(--brand); }
        .e-btn-primary:hover:not([disabled]) { opacity: .92; color: var(--brand-text); }
        .e-btn-ghost { background: var(--e-surface); color: var(--e-title); border: 1px solid var(--e-border); }
        .e-btn-ghost:hover { background: var(--e-subtle); color: var(--e-title); }

        /* Datos para soporte */
        .e-meta {
            margin: 0;
            display: grid;
            grid-template-columns: repeat(3, auto);
            justify-content: space-between;
            gap: 8px 20px;
            padding: 16px 44px;
            background: var(--e-subtle);
            border-top: 1px solid var(--e-divider);
            font-size: 12.5px;
            color: var(--e-muted);
        }
        .e-meta dt { font-weight: 500; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; margin: 0; }
        .e-meta dd { margin: 2px 0 0; font-family: 'JetBrains Mono', ui-monospace, monospace; color: var(--e-title); font-size: 12.5px; word-break: break-all; }
        .e-copy {
            margin-left: 4px;
            padding: 0;
            border: 0;
            background: none;
            color: var(--e-muted);
            cursor: pointer;
            vertical-align: -1px;
        }
        .e-copy:hover { color: var(--e-title); }
        .e-hint { padding: 0 44px 18px; margin: 0; background: var(--e-subtle); font-size: 12.5px; color: var(--e-muted); }

        .e-foot { padding: 18px 32px 24px; text-align: center; font-size: 12.5px; color: var(--e-muted); }

        @media (max-width: 575.98px) {
            .e-top { padding: 14px 16px; }
            .e-brand img { height: 28px; }
            .e-main { place-items: start center; padding: 8px 16px 24px; }
            .e-body { padding: 28px 22px 24px; }
            .e-title { font-size: 21px; }
            .e-actions .e-btn { flex: 1 1 100%; }
            .e-meta { grid-template-columns: 1fr 1fr; padding: 16px 22px; }
            .e-meta > div:last-child { grid-column: 1 / -1; }
            .e-hint { padding: 0 22px 16px; }
            .e-foot { padding: 12px 16px 20px; }
        }
        @media (prefers-reduced-motion: reduce) { .e-card { animation: none; } }
    </style>

    @yield('styles')
</head>
<body>
    <header class="e-top">
        <a href="{{ $errHomeUrl }}" class="e-brand" title="{{ $errBrandSettings->tradeName() }}">
            <img src="{{ $errLogo }}" alt="{{ $errBrandSettings->tradeName() }}" class="brand-logo-light {{ $errHasDark ? 'has-dark' : '' }}">
            @if($errHasDark)
                <img src="{{ $errLogoDark }}" alt="" aria-hidden="true" class="brand-logo-dark">
            @endif
        </a>

        <button type="button" class="e-icon-btn" id="errThemeToggle" title="Cambiar tema claro / oscuro" aria-label="Cambiar tema claro u oscuro">
            <i class="bi bi-moon" id="errThemeIcon"></i>
        </button>
    </header>

    <main class="e-main">
        <section class="e-card tone-@yield('tone', 'info')" aria-labelledby="errTitle">
            <div class="e-body">
                <div class="e-head">
                    <div class="e-icon" aria-hidden="true">
                        @section('icon')<i class="bi bi-exclamation-circle"></i>@show
                    </div>
                    <div class="e-code">
                        Error @yield('code')
                        <span class="e-code-sub">@yield('label', 'Algo no salió como esperábamos')</span>
                    </div>
                </div>

                <h1 class="e-title" id="errTitle">@yield('headline')</h1>
                <p class="e-text">@yield('message')</p>

                <div class="e-actions">
                    @section('actions')
                        <a href="{{ $errHomeUrl }}" class="e-btn e-btn-primary">
                            <i class="bi {{ $errHomeIcon }}"></i>
                            <span>{{ $errHomeLabel }}</span>
                        </a>
                        <button type="button" onclick="history.length > 1 ? history.back() : (location.href = @js($errHomeUrl))" class="e-btn e-btn-ghost">
                            <i class="bi bi-arrow-left"></i>
                            <span>Regresar</span>
                        </button>
                    @show
                </div>
            </div>

            <dl class="e-meta">
                <div>
                    <dt>Referencia</dt>
                    <dd><span id="errRef">{{ $errRef }}</span><button type="button" class="e-copy" id="errCopyRef" title="Copiar referencia" aria-label="Copiar código de referencia"><i class="bi bi-copy"></i></button></dd>
                </div>
                <div>
                    <dt>Fecha y hora</dt>
                    <dd>{{ now()->format('d/m/Y H:i') }}</dd>
                </div>
                <div>
                    <dt>Ruta</dt>
                    <dd>/{{ \Illuminate\Support\Str::limit(ltrim(request()->path(), '/'), 40) }}</dd>
                </div>
            </dl>
            <p class="e-hint">@yield('hint', 'Si el problema continúa, comparte el código de referencia con soporte: con él encontramos exactamente qué pasó.')</p>
        </section>
    </main>

    <footer class="e-foot">&copy; {{ date('Y') }} {{ $errBrandSettings->tradeName() }} · Sistema de Gestión y Asistencia Empresarial</footer>

    <script>
        (function () {
            const root = document.documentElement;
            const icon = document.getElementById('errThemeIcon');
            const sync = () => { icon.className = root.getAttribute('data-bs-theme') === 'dark' ? 'bi bi-sun' : 'bi bi-moon'; };
            sync();
            document.getElementById('errThemeToggle').addEventListener('click', function () {
                const next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-bs-theme', next);
                try { localStorage.setItem('panel-theme', next); } catch (e) {}
                sync();
            });

            const copy = document.getElementById('errCopyRef');
            if (copy && navigator.clipboard) {
                copy.addEventListener('click', function () {
                    navigator.clipboard.writeText(document.getElementById('errRef').textContent.trim()).then(function () {
                        copy.innerHTML = '<i class="bi bi-check2"></i>';
                        setTimeout(function () { copy.innerHTML = '<i class="bi bi-copy"></i>'; }, 1600);
                    }).catch(function () {});
                });
            }
        })();
    </script>
</body>
</html>
