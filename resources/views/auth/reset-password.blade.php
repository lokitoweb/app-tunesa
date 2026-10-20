@extends('layouts.guest')

@section('title', 'Nueva contraseña')

@section('content')
@php
    $brandTenant = isset($tenant) && $tenant instanceof \App\Modules\Core\Models\Tenant ? $tenant : \App\Modules\Core\Models\Tenant::fromHost(request()->getHost());
    $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($brandTenant);
@endphp
<div class="antigravity-composer">
    <div class="antigravity-auth-card" id="resetPasswordCard">

        <!-- Logo Oficial Centrado -->
        <div class="card-brand-logo-wrap">
            <img src="{{ asset('img/grupo-negreiros-logo.png') }}" alt="{{ $brandSettings->tradeName() }}" class="card-brand-logo">
        </div>

        <!-- Badge de Seguridad + Título -->
        <h2 class="card-hero-title">Crea una nueva contraseña</h2>
        <p class="card-hero-subtitle">Ingresa una contraseña segura de mínimo 8 caracteres con letras y números.</p>

        <form method="POST" action="{{ route('password.update') }}" novalidate id="resetForm" class="antigravity-form">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <!-- Campo Correo Corporativo -->
            <div class="glass-field-group">
                <label for="email" class="glass-field-label">Correo corporativo</label>
                <div class="glass-input-plane" id="emailInputPlane">
                    <div class="glass-input-icon-lead">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lead-accent-icon">
                            <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                        </svg>
                    </div>
                    <input type="email" id="email" name="email" class="glass-input-elem @error('email') is-invalid @enderror"
                           placeholder="nombre@empresa.com" value="{{ old('email', $email) }}" required autocomplete="username" spellcheck="false" maxlength="180">
                </div>
            </div>

            <!-- Campo Nueva Contraseña -->
            <div class="glass-field-group">
                <label for="password" class="glass-field-label">Nueva contraseña</label>
                <div class="glass-input-plane" id="passwordInputPlane">
                    <div class="glass-input-icon-lead">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lead-accent-icon">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </div>
                    <input type="password" id="password" name="password" class="glass-input-elem @error('password') is-invalid @enderror"
                           placeholder="Mínimo 8 caracteres" required autofocus autocomplete="new-password" minlength="8" maxlength="128">
                    <button type="button" class="eye-toggle-btn" id="togglePassword" aria-label="Mostrar u ocultar contraseña" title="Mostrar contraseña">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <!-- Campo Confirmar Contraseña -->
            <div class="glass-field-group">
                <label for="password_confirmation" class="glass-field-label">Confirma la nueva contraseña</label>
                <div class="glass-input-plane" id="confirmInputPlane">
                    <div class="glass-input-icon-lead">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lead-accent-icon">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </div>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="glass-input-elem"
                           placeholder="Repite la contraseña" required autocomplete="new-password" minlength="8" maxlength="128">
                    <button type="button" class="eye-toggle-btn" id="toggleConfirm" aria-label="Mostrar u ocultar contraseña" title="Mostrar contraseña">
                        <i class="bi bi-eye" id="eyeIconConfirm"></i>
                    </button>
                </div>
            </div>

            <!-- Botón de Acción -->
            <button type="submit" class="organic-stone-btn" id="resetSubmit" disabled>
                <span class="btn-engraved-text">Actualizar contraseña</span>
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
        const form = document.getElementById('resetForm');
        const email = document.getElementById('email');
        const password = document.getElementById('password');
        const confirm = document.getElementById('password_confirmation');
        const btn = document.getElementById('resetSubmit');
        const toastContainer = document.getElementById('toastContainer');

        // ── Estado Dinámico del Botón ──
        function updateButtonState() {
            if (!btn || !email || !password || !confirm) return;
            const hasEmail = email.value.trim().length > 0;
            const hasPass = password.value.length >= 8;
            const hasConfirm = confirm.value.length >= 8;
            btn.disabled = !(hasEmail && hasPass && hasConfirm);
        }

        [email, password, confirm].forEach(el => {
            if (el) {
                el.addEventListener('input', updateButtonState);
                el.addEventListener('change', updateButtonState);
            }
        });
        updateButtonState();

        // Ojito toggle para contraseña
        function setupToggle(toggleId, inputEl, iconId) {
            const toggle = document.getElementById(toggleId);
            const icon = document.getElementById(iconId);
            if (toggle && inputEl && icon) {
                toggle.addEventListener('click', function () {
                    const isPassword = inputEl.getAttribute('type') === 'password';
                    inputEl.setAttribute('type', isPassword ? 'text' : 'password');
                    icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
                    toggle.setAttribute('title', isPassword ? 'Ocultar contraseña' : 'Mostrar contraseña');
                });
            }
        }
        setupToggle('togglePassword', password, 'eyeIcon');
        setupToggle('toggleConfirm', confirm, 'eyeIconConfirm');

        // Validación antes del envío
        if (form) {
            form.addEventListener('submit', function (e) {
                const passVal = password ? password.value : '';
                const confirmVal = confirm ? confirm.value : '';

                if (passVal !== confirmVal) {
                    e.preventDefault();
                    triggerToast('Error de validación', 'Las contraseñas no coinciden. Verifica e intenta de nuevo.');
                    if (confirm) confirm.focus();
                    return false;
                }

                if (passVal.length < 8) {
                    e.preventDefault();
                    triggerToast('Contraseña débil', 'La contraseña debe tener al menos 8 caracteres.');
                    if (password) password.focus();
                    return false;
                }

                // Estado de carga
                if (btn) {
                    btn.disabled = true;
                    btn.classList.add('is-loading');
                    btn.innerHTML = '<span class="erp-spinner" aria-hidden="true"></span><span class="btn-engraved-text">Guardando contraseña…</span>';
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
