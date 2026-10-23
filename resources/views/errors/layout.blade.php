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
    $errAuthed    = rescue(fn () => Auth::check(), false, false);
    $errHomeUrl   = $errAuthed ? route('dashboard') : route('login');
    $errHomeLabel = $errAuthed ? 'Ir al panel principal' : 'Ir al inicio de sesión';

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

    @include('layouts.partials.theme-boot')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">

    @include('layouts.partials.brand-head', ['brandSettings' => $errBrandSettings])

    <style>
        :root, [data-bs-theme="light"] {
            --x-panel: #ffffff;
            --x-title: #0b1324;
            --x-text: #4a5568;
            --x-muted: #718096;
            --x-line: #e7eaf0;
            --x-ghost-bg: #ffffff;
            --x-ghost-hover: #f4f6f9;
            --x-chip-bg: rgba(var(--brand-rgb), .08);
            --x-chip-text: var(--brand);
        }
        [data-bs-theme="dark"] {
            --x-panel: #0e131c;
            --x-title: #f3f5f9;
            --x-text: #aab3c3;
            --x-muted: #7d879a;
            --x-line: #1f2633;
            --x-ghost-bg: #131a25;
            --x-ghost-hover: #182131;
            --x-chip-bg: rgba(255, 255, 255, .06);
            --x-chip-text: #c9d4ea;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', sans-serif;
            color: var(--x-text);
            background: var(--x-panel);
            -webkit-font-smoothing: antialiased;
        }

        .x-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(420px, 46%) 1fr;
        }

        /* ── Columna de contenido ─────────────────────────────── */
        .x-panel {
            display: flex;
            flex-direction: column;
            padding: 40px clamp(28px, 5vw, 72px);
            background: var(--x-panel);
        }
        .x-top { display: flex; align-items: center; justify-content: space-between; }
        .x-brand img { height: 40px; max-width: 200px; object-fit: contain; display: block; }

        .x-theme {
            width: 40px;
            height: 40px;
            display: inline-grid;
            place-items: center;
            border-radius: 12px;
            border: 1px solid var(--x-line);
            background: var(--x-ghost-bg);
            color: var(--x-text);
            cursor: pointer;
            transition: background-color .15s, color .15s;
        }
        .x-theme:hover { background: var(--x-ghost-hover); color: var(--x-title); }

        .x-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            max-width: 480px;
            padding: 48px 0;
            animation: x-in .45s cubic-bezier(.16, 1, .3, 1) both;
        }
        @keyframes x-in { from { opacity: 0; transform: translateY(10px); } }

        .x-chip {
            align-self: flex-start;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px 6px 10px;
            border-radius: 999px;
            background: var(--x-chip-bg);
            color: var(--x-chip-text);
            font-size: 12.5px;
            font-weight: 600;
            letter-spacing: .01em;
        }
        .x-chip i { font-size: 14px; }

        .x-title {
            margin: 22px 0 14px;
            font-size: clamp(30px, 3.2vw, 40px);
            line-height: 1.12;
            font-weight: 800;
            letter-spacing: -.025em;
            color: var(--x-title);
        }
        .x-text { margin: 0; font-size: 16px; line-height: 1.7; color: var(--x-text); }

        .x-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 34px; }
        .x-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            height: 48px;
            padding: 0 22px;
            border-radius: 12px;
            font: inherit;
            font-size: 14.5px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: transform .15s, box-shadow .15s, background-color .15s, opacity .15s;
        }
        .x-btn:focus-visible { outline: 3px solid rgba(var(--brand-rgb), .35); outline-offset: 2px; }
        .x-btn[disabled] { opacity: .5; cursor: not-allowed; }
        .x-btn-primary {
            border: 0;
            background: var(--brand);
            color: var(--brand-text);
            box-shadow: 0 8px 20px -8px rgba(var(--brand-rgb), .6);
        }
        .x-btn-primary:hover:not([disabled]) { transform: translateY(-1px); box-shadow: 0 12px 24px -10px rgba(var(--brand-rgb), .7); color: var(--brand-text); }
        .x-btn-ghost { border: 1px solid var(--x-line); background: var(--x-ghost-bg); color: var(--x-title); }
        .x-btn-ghost:hover { background: var(--x-ghost-hover); color: var(--x-title); }

        .x-support {
            margin-top: 40px;
            padding-top: 22px;
            border-top: 1px solid var(--x-line);
            font-size: 13px;
            line-height: 1.6;
            color: var(--x-muted);
        }
        .x-ref {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-left: 2px;
            padding: 1px 8px;
            border-radius: 6px;
            border: 1px solid var(--x-line);
            background: var(--x-ghost-bg);
            color: var(--x-title);
            font: 600 12px ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
            letter-spacing: .04em;
            cursor: pointer;
        }
        .x-ref:hover { background: var(--x-ghost-hover); }

        .x-foot { font-size: 12.5px; color: var(--x-muted); }

        /* ── Columna visual (foto corporativa del login) ───────── */
        .x-visual {
            position: relative;
            overflow: hidden;
            background: #0b1324 url('{{ asset('img/antigravity-bg.jpg') }}') center / cover no-repeat;
        }
        .x-visual::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(8, 14, 28, .15) 0%, rgba(8, 14, 28, .55) 55%, rgba(8, 14, 28, .92) 100%),
                linear-gradient(90deg, rgba(var(--brand-rgb), .35), rgba(var(--brand-rgb), 0) 60%);
        }
        .x-visual-inner {
            position: absolute;
            inset: auto 0 0 0;
            padding: 0 clamp(32px, 4vw, 64px) clamp(36px, 5vh, 56px);
            color: #fff;
        }
        .x-code {
            display: block;
            font-size: clamp(96px, 10vw, 168px);
            line-height: .9;
            font-weight: 800;
            letter-spacing: -.05em;
            color: #fff;
            text-shadow: 0 12px 40px rgba(0, 0, 0, .35);
            user-select: none;
        }
        .x-tagline { margin: 18px 0 0; padding-top: 18px; border-top: 1px solid rgba(255, 255, 255, .25); max-width: 440px; font-size: 15px; line-height: 1.6; color: rgba(255, 255, 255, .82); }
        .x-tagline strong { display: block; font-size: 17px; color: #fff; margin-bottom: 2px; }

        @media (max-width: 991.98px) {
            .x-shell { grid-template-columns: 1fr; }
            .x-visual { display: none; }
            .x-content { max-width: 560px; }
        }
        @media (max-width: 575.98px) {
            .x-panel { padding: 22px 20px; }
            .x-brand img { height: 32px; }
            .x-content { padding: 36px 0 28px; }
            .x-actions .x-btn { flex: 1 1 100%; }
        }
        @media (prefers-reduced-motion: reduce) { .x-content { animation: none; } }
    </style>

    @yield('styles')
</head>
<body>
    <div class="x-shell">
        <main class="x-panel">
            <header class="x-top">
                <a href="{{ $errHomeUrl }}" class="x-brand" title="{{ $errBrandSettings->tradeName() }}">
                    <img src="{{ $errLogo }}" alt="{{ $errBrandSettings->tradeName() }}" class="brand-logo-light {{ $errHasDark ? 'has-dark' : '' }}">
                    @if($errHasDark)
                        <img src="{{ $errLogoDark }}" alt="" aria-hidden="true" class="brand-logo-dark">
                    @endif
                </a>
                <button type="button" class="x-theme" id="errThemeToggle" title="Cambiar tema" aria-label="Cambiar tema claro u oscuro">
                    <i class="bi bi-moon-stars" id="errThemeIcon"></i>
                </button>
            </header>

            <section class="x-content" aria-labelledby="errTitle">
                <span class="x-chip">
                    @section('icon')<i class="bi bi-info-circle"></i>@show
                    <span>Error @yield('code') · @yield('label', 'Algo no salió como esperábamos')</span>
                </span>

                <h1 class="x-title" id="errTitle">@yield('headline')</h1>
                <p class="x-text">@yield('message')</p>

                <div class="x-actions">
                    @section('actions')
                        <button type="button" onclick="history.length > 1 ? history.back() : (location.href = @js($errHomeUrl))" class="x-btn x-btn-primary">
                            <i class="bi bi-arrow-left"></i>
                            <span>Volver atrás</span>
                        </button>
                        <a href="{{ $errHomeUrl }}" class="x-btn x-btn-ghost">
                            <span>{{ $errHomeLabel }}</span>
                        </a>
                    @show
                </div>

                <p class="x-support">
                    @yield('hint', '¿Necesitas ayuda? Comparte este código con soporte y ubicaremos lo ocurrido:')
                    <button type="button" class="x-ref" id="errCopyRef" title="Copiar código" aria-label="Copiar código de referencia {{ $errRef }}">
                        <span id="errRef">{{ $errRef }}</span><i class="bi bi-copy" id="errCopyIcon"></i>
                    </button>
                </p>
            </section>

            <footer class="x-foot">&copy; {{ date('Y') }} {{ $errBrandSettings->tradeName() }}</footer>
        </main>

        <aside class="x-visual" aria-hidden="true">
            <div class="x-visual-inner">
                <span class="x-code">@yield('code')</span>
                <p class="x-tagline">
                    <strong>{{ $errBrandSettings->tradeName() }}</strong>
                    Sistema de Gestión y Asistencia Empresarial
                </p>
            </div>
        </aside>
    </div>

    <script>
        (function () {
            const root = document.documentElement;
            const icon = document.getElementById('errThemeIcon');
            const sync = () => { icon.className = root.getAttribute('data-bs-theme') === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'; };
            sync();
            document.getElementById('errThemeToggle').addEventListener('click', function () {
                const next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-bs-theme', next);
                try { localStorage.setItem('panel-theme', next); } catch (e) {}
                sync();
            });

            const copy = document.getElementById('errCopyRef');
            const copyIcon = document.getElementById('errCopyIcon');
            copy.addEventListener('click', function () {
                if (!navigator.clipboard) return;
                navigator.clipboard.writeText(document.getElementById('errRef').textContent.trim()).then(function () {
                    copyIcon.className = 'bi bi-check2';
                    setTimeout(function () { copyIcon.className = 'bi bi-copy'; }, 1600);
                }).catch(function () {});
            });
        })();
    </script>
</body>
</html>
