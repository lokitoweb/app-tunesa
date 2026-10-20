@extends('layouts.guest')

@section('title', 'Recuperar contraseña')

@section('content')
@php
    $brandTenant = isset($tenant) && $tenant instanceof \App\Modules\Core\Models\Tenant ? $tenant : \App\Modules\Core\Models\Tenant::fromHost(request()->getHost());
    $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($brandTenant);
@endphp
<div class="antigravity-composer">
    <div class="antigravity-auth-card" id="forgotPasswordCard">

        <!-- Logo Oficial Centrado -->
        <div class="card-brand-logo-wrap">
            <img src="{{ asset('img/grupo-negreiros-logo.png') }}" alt="{{ $brandSettings->tradeName() }}" class="card-brand-logo">
        </div>

        <!-- Título y Subtítulo -->
        <h2 class="card-hero-title">Recuperar contraseña</h2>
        <p class="card-hero-subtitle">Ingresa tu correo corporativo y te enviaremos un enlace de recuperación.</p>

        <form method="POST" action="{{ route('password.email') }}" novalidate id="forgotForm" class="antigravity-form">
            @csrf

            <!-- Campo Correo Corporativo -->
            <div class="glass-field-group">
                <label for="email" class="glass-field-label">Correo electrónico corporativo</label>
                <div class="glass-input-plane" id="emailInputPlane">
                    <div class="glass-input-icon-lead">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lead-accent-icon">
                            <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                        </svg>
                    </div>
                    <input type="email" id="email" name="email" class="glass-input-elem @error('email') is-invalid @enderror"
                           placeholder="nombre@empresa.com" value="{{ old('email') }}" required autofocus autocomplete="username" spellcheck="false" maxlength="180">
                </div>
            </div>

            <!-- Botón de Acción -->
            <button type="submit" class="organic-stone-btn" id="forgotSubmit" disabled>
                <span class="btn-engraved-text">Enviar enlace de recuperación</span>
                <span class="btn-engraved-arrow">→</span>
            </button>
        </form>

        <!-- Enlace Volver -->
        <div class="card-security-note" style="margin-top: 1.5rem;">
            <a href="{{ route('login') }}" class="glass-forgot-link" style="font-size: 13px; display: flex; align-items: center; gap: 0.4rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 14px; height: 14px;">
                    <path d="M19 12H5"></path>
                    <path d="m12 19-7-7 7-7"></path>
                </svg>
                Volver al inicio de sesión
            </a>
        </div>

    </div>
</div>

<script>
    (function () {
        const form = document.getElementById('forgotForm');
        const email = document.getElementById('email');
        const btn = document.getElementById('forgotSubmit');
        const toastContainer = document.getElementById('toastContainer');

        // ── Estado Dinámico del Botón ──
        function updateButtonState() {
            if (!btn || !email) return;
            btn.disabled = email.value.trim().length === 0;
        }

        if (email) {
            email.addEventListener('input', updateButtonState);
            email.addEventListener('change', updateButtonState);
        }
        updateButtonState();

        // Validación antes del envío
        if (form) {
            form.addEventListener('submit', function (e) {
                const emailVal = email ? email.value.trim() : '';

                if (!emailVal) {
                    e.preventDefault();
                    triggerToast('Campo obligatorio', 'Por favor ingresa tu correo corporativo.');
                    if (email) email.focus();
                    return false;
                }

                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(emailVal)) {
                    e.preventDefault();
                    triggerToast('Formato incorrecto', 'Ingresa un formato de correo electrónico válido.');
                    if (email) email.focus();
                    return false;
                }

                // Estado de carga
                if (btn) {
                    btn.disabled = true;
                    btn.classList.add('is-loading');
                    btn.innerHTML = '<span class="erp-spinner" aria-hidden="true"></span><span class="btn-engraved-text">Enviando enlace…</span>';
                }
            });
        }

        function triggerToast(title, message, type = 'danger') {
            if (!toastContainer) return;
            const existing = toastContainer.querySelectorAll('.antigravity-toast');
            existing.forEach(t => t.remove());

            const toast = document.createElement('div');
            toast.className = `antigravity-toast antigravity-toast-${type}`;
            toast.setAttribute('role', 'alert');
            const iconClass = type === 'danger' ? 'bi-shield-exclamation' : 'bi-check-circle-fill';
            toast.innerHTML = `
                <div class="toast-icon-wrap"><i class="bi ${iconClass}"></i></div>
                <div class="toast-body-wrap">
                    <div class="toast-title">${title}</div>
                    <div class="toast-desc">${message}</div>
                </div>
                <button type="button" class="toast-close-btn" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
            `;
            toast.querySelector('.toast-close-btn').addEventListener('click', () => toast.remove());
            toastContainer.appendChild(toast);
            setTimeout(() => {
                if (toast && toast.parentElement) {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-15px)';
                    setTimeout(() => toast.remove(), 250);
                }
            }, 6000);
        }

        // Auto-cerrar toasts del servidor
        if (toastContainer) {
            const serverToasts = toastContainer.querySelectorAll('.antigravity-toast');
            serverToasts.forEach(toast => {
                const closeBtn = toast.querySelector('.toast-close-btn');
                if (closeBtn) closeBtn.addEventListener('click', () => toast.remove());
                setTimeout(() => {
                    if (toast && toast.parentElement) {
                        toast.style.opacity = '0';
                        toast.style.transform = 'translateY(-15px)';
                        setTimeout(() => toast.remove(), 250);
                    }
                }, 8000);
            });
        }
    })();
</script>
@endsection
