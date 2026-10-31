<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $brandTenant = isset($tenant) && $tenant instanceof \App\Modules\Core\Models\Tenant ? $tenant : \App\Modules\Core\Models\Tenant::fromHost(request()->getHost());
        $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($brandTenant);
        $heroLogo = $brandSettings->usesDefaultLogo() ? asset(\App\Modules\Core\Models\TenantSetting::DEFAULT_LOGO_DARK) : ($brandSettings->logoDarkUrl() ?: $brandSettings->displayLogoUrl());
        $heroOnPlate = ! $brandSettings->usesDefaultLogo() && ! $brandSettings->logoDarkUrl();
        $loginBackgrounds = $brandSettings->loginBackgroundUrls();
        $slideSeconds = $brandSettings->loginSlideSeconds();
    @endphp
    <link rel="preload" as="image" href="{{ $loginBackgrounds[0] }}" fetchpriority="high">
    <title>@yield('title', 'Acceso') — {{ $brandSettings->tradeName() }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v=23">
    @include('layouts.partials.brand-head', ['brandSettings' => $brandSettings])
</head>
<body class="gn-auth-body">
    {{-- Contenedor Flotante de Notificaciones Toast (Relieve Alto) --}}
    <div class="antigravity-toast-container" id="toastContainer" aria-live="polite">
        @if($errors->any())
            <div class="antigravity-toast antigravity-toast-danger" role="alert">
                <div class="toast-icon-wrap">
                    <i class="bi bi-shield-exclamation"></i>
                </div>
                <div class="toast-body-wrap">
                    <div class="toast-title">Atención requerida</div>
                    <div class="toast-desc">
                        @foreach($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                </div>
                <button type="button" class="toast-close-btn" onclick="this.closest('.antigravity-toast').remove()" aria-label="Cerrar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        @endif
        @if(session('status') || session('success'))
            <div class="antigravity-toast antigravity-toast-success" role="status">
                <div class="toast-icon-wrap">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="toast-body-wrap">
                    <div class="toast-title">Operación exitosa</div>
                    <div class="toast-desc">{{ session('status') ?? session('success') }}</div>
                </div>
                <button type="button" class="toast-close-btn" onclick="this.closest('.antigravity-toast').remove()" aria-label="Cerrar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        @endif
        @if(session('info') || session('warning'))
            <div class="antigravity-toast antigravity-toast-info" role="status">
                <div class="toast-icon-wrap">
                    <i class="bi bi-info-circle-fill"></i>
                </div>
                <div class="toast-body-wrap">
                    <div class="toast-title">Aviso del sistema</div>
                    <div class="toast-desc">{{ session('info') ?? session('warning') }}</div>
                </div>
                <button type="button" class="toast-close-btn" onclick="this.closest('.antigravity-toast').remove()" aria-label="Cerrar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        @endif
    </div>

    {{-- Escenario Anti-Gravity Completo --}}
    <div class="antigravity-viewport">
        {{-- Fondos administrables (Marca y app): fundido con acercamiento lento; la primera carga ya, las demás al tocarles --}}
        <div class="auth-slides" aria-hidden="true" data-seconds="{{ $slideSeconds }}" style="--slide-ms: {{ $slideSeconds * 1000 + 1800 }}ms;">
            @foreach($loginBackgrounds as $i => $bg)
                @if($i === 0)
                    <div class="auth-slide is-active" style="background-image: url('{{ $bg }}');"></div>
                @else
                    <div class="auth-slide" data-bg="{{ $bg }}"></div>
                @endif
            @endforeach
        </div>

        {{-- Floating Ambient Refraction Caustics --}}
        <div class="antigravity-caustic-layer" aria-hidden="true"></div>

        {{-- Barra Superior Transparente (Modo claro/oscuro eliminado según solicitud) --}}
        <header class="antigravity-top-bar"></header>

        {{-- Main Anti-Gravity Composition Area (Perfectamente Centrado Verticalmente) --}}
        <main class="antigravity-stage">
            @yield('content')
        </main>

        {{-- Barra Inferior: Solo Texto Puro sin Pill, Administrable con Año Dinámico --}}
        <footer class="antigravity-bottom-bar">
            <p class="antigravity-bottom-text">ERP Corporativo • {{ $brandSettings->tradeName() }} • Transportes y Encomiendas • © {{ date('Y') }}</p>
        </footer>
    </div>

    <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script>
        // Fondos del login: cambia cada N segundos con fundido; la siguiente imagen se descarga antes de mostrarse.
        // Con "reducir movimiento" o una sola imagen queda fija; se pausa con la pestaña oculta.
        (() => {
            const box = document.querySelector('.auth-slides');
            const slides = box ? [...box.children] : [];
            if (slides.length < 2 || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            const ms = (Number(box.dataset.seconds) || 8) * 1000;
            let current = 0, timer;

            const load = (slide) => new Promise((done) => {
                const url = slide.dataset.bg;
                if (!url) return done();
                const img = new Image();
                img.onload = img.onerror = () => { slide.style.backgroundImage = `url("${url}")`; delete slide.dataset.bg; done(); };
                img.src = url;
            });
            const next = async () => {
                const n = (current + 1) % slides.length;
                await load(slides[n]);
                const prev = slides[current];
                prev.classList.replace('is-active', 'is-leaving');
                setTimeout(() => prev.classList.remove('is-leaving'), 1800);
                slides[n].classList.add('is-active');
                current = n;
                load(slides[(n + 1) % slides.length]);
                timer = setTimeout(next, ms);
            };

            addEventListener('load', () => load(slides[1]), { once: true });
            timer = setTimeout(next, ms);
            document.addEventListener('visibilitychange', () => {
                clearTimeout(timer);
                if (!document.hidden) timer = setTimeout(next, ms);
            });
        })();
    </script>
</body>
</html>
