@extends('errors.layout')

@section('title', '419 — Sesión expirada')
@section('code', '419')

@section('styles')
<style>
    :root {
        --err-badge-bg: rgba(245, 158, 11, 0.12);
        --err-badge-color: #d97706;
        --err-badge-border: rgba(245, 158, 11, 0.3);
        --err-icon-bg: rgba(245, 158, 11, 0.1);
        --err-icon-color: #d97706;
        --err-icon-border: rgba(245, 158, 11, 0.25);
        --err-icon-shadow: rgba(245, 158, 11, 0.2);
    }
    [data-bs-theme="dark"] {
        --err-badge-bg: rgba(245, 158, 11, 0.15);
        --err-badge-color: #f59e0b;
        --err-badge-border: rgba(245, 158, 11, 0.35);
        --err-icon-bg: rgba(245, 158, 11, 0.12);
        --err-icon-color: #f59e0b;
        --err-icon-border: rgba(245, 158, 11, 0.3);
        --err-icon-shadow: rgba(245, 158, 11, 0.3);
    }
</style>
@endsection

@section('icon')
    <i class="bi bi-clock-history"></i>
@endsection

@section('headline', 'Sesión o formulario expirado')

@section('message')
    Tu sesión ha finalizado por inactividad para proteger la seguridad de tu cuenta. Inicia sesión nuevamente o recarga para continuar.
@endsection

@section('actions')
    <button type="button" id="btn419Recover" onclick="handle419Recovery()" class="err-btn-primary">
        <i class="bi bi-arrow-clockwise"></i>
        <span>Recargar Página</span>
    </button>
    <a href="{{ Auth::check() ? route('dashboard') : route('login') }}" class="err-btn-secondary">
        <i class="bi {{ Auth::check() ? 'bi-grid-fill' : 'bi-box-arrow-in-right' }}"></i>
        <span>{{ Auth::check() ? 'Ir al Dashboard' : 'Iniciar Sesión' }}</span>
    </a>

    <script>
        function handle419Recovery() {
            const btn = document.getElementById('btn419Recover');
            if (btn) {
                btn.disabled = true;
                btn.style.opacity = '0.7';
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="width: 14px; height: 14px;"></span><span>Cargando…</span>';
            }

            const current = window.location.href;
            const ref = document.referrer;

            // 1. Si la URL actual es un endpoint de acción como /reveal, /payment-review, etc., volver a la ficha
            const revealMatch = current.match(/^(https?:\/\/[^\/]+\/employees\/\d+\/file)(\/reveal|\/payment-review|\/profile|\/documents)/);
            if (revealMatch && revealMatch[1]) {
                window.location.href = revealMatch[1];
                return;
            }

            // 2. Si venía de una página anterior en el mismo host y no es la misma URL de error
            if (ref && ref !== current && ref.includes(window.location.host)) {
                try {
                    const u = new URL(ref);
                    u.searchParams.set('_t', Date.now());
                    window.location.href = u.toString();
                    return;
                } catch (e) {
                    window.location.href = ref;
                    return;
                }
            }

            // 3. Si hay historial disponible en el navegador
            if (window.history && window.history.length > 1) {
                window.history.back();
                return;
            }

            // 4. Fallback final al dashboard o login
            window.location.href = '{{ Auth::check() ? route("dashboard") : route("login") }}';
        }
    </script>
@endsection
