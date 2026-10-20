@extends('layouts.guest')

@section('title', 'Iniciar sesión')

@section('content')
@php
    $brandTenant = isset($tenant) && $tenant instanceof \App\Modules\Core\Models\Tenant ? $tenant : \App\Modules\Core\Models\Tenant::fromHost(request()->getHost());
    $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($brandTenant);
@endphp
<div class="antigravity-composer">

    <!-- Escultura Monolítica Continua (Vidrio Unificado Anti-Gravity Symmetrical) -->
    <div class="antigravity-monolith" id="antigravityMonolith">
        
        <!-- Capa 1: Fondo de Vidrio Translúcido clip-path simétrico -->
        <div class="glass-monolith-backdrop" aria-hidden="true"></div>

        <!-- Capa 2: Contorno Especular SVG Continuo y Perfectamente Simétrico -->
        <svg class="glass-monolith-outline" viewBox="0 0 1160 560" fill="none" preserveAspectRatio="none" aria-hidden="true">
            <defs>
                <linearGradient id="specularOutlineGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="rgba(255, 255, 255, 0.95)" />
                    <stop offset="35%" stop-color="rgba(255, 255, 255, 0.70)" />
                    <stop offset="70%" stop-color="rgba(210, 235, 255, 0.65)" />
                    <stop offset="100%" stop-color="rgba(180, 215, 245, 0.85)" />
                </linearGradient>
            </defs>
            <path d="M 24 180 L 650 180 C 675 180 700 155 700 130 L 700 32 C 700 14.3 714.3 0 732 0 L 1128 0 C 1145.7 0 1160 14.3 1160 32 L 1160 528 C 1160 545.7 1145.7 560 1128 560 L 732 560 C 714.3 560 700 545.7 700 528 L 700 430 C 700 405 675 380 650 380 L 24 380 C 10.7 380 0 369.3 0 356 L 0 204 C 0 190.7 10.7 180 24 180 Z" 
                  stroke="url(#specularOutlineGrad)" stroke-width="1.8" vector-effect="non-scaling-stroke"/>
        </svg>

        <!-- Capa 3: Contenido -->
        <div class="glass-monolith-content">
            
            <!-- SECCIÓN IZQUIERDA: Ribbon Horizontal Ordenado (Icono Arriba, Texto y Métrica Abajo) -->
            <section class="monolith-ribbon" aria-label="Métricas corporativas">
                
                <!-- Columna 1: Control Operativo -->
                <div class="metric-cell">
                    <div class="metric-icon-orb">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                        </svg>
                    </div>
                    <span class="metric-num-accent">100%</span>
                    <span class="metric-label">Control Operativo</span>
                </div>

                <div class="metric-hairline-sep" aria-hidden="true"></div>

                <!-- Columna 2: Capital Humano -->
                <div class="metric-cell">
                    <div class="metric-icon-orb">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                    </div>
                    <span class="metric-num-accent">Planillas</span>
                    <span class="metric-label">Capital Humano</span>
                </div>

                <div class="metric-hairline-sep" aria-hidden="true"></div>

                <!-- Columna 3: Seguridad y GPS -->
                <div class="metric-cell">
                    <div class="metric-icon-orb">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                            <circle cx="12" cy="11" r="3"></circle>
                        </svg>
                    </div>
                    <span class="metric-num-accent">24/7</span>
                    <span class="metric-label">Seguridad & GPS</span>
                </div>

            </section>

            <!-- SECCIÓN DERECHA: Tarjeta Vertical de Login (Sin alertas inline intrusivas, diseño intacto) -->
            <section class="monolith-card" aria-label="Formulario de inicio de sesión">
                
                <!-- Logo Oficial Dinámico Centrado y Elevado -->
                <div class="card-brand-logo-wrap">
                    <img src="{{ $brandSettings->displayLogoUrl() }}" alt="{{ $brandSettings->tradeName() }}" class="card-brand-logo">
                </div>

                <!-- Título y Subtítulo Centrados con Espaciado Generoso y Holgado -->
                <h2 class="card-hero-title">Iniciar sesión</h2>
                <p class="card-hero-subtitle">Ingresa tus credenciales para acceder al ERP empresarial.</p>

                <form method="POST" action="{{ route('login.post') }}" novalidate id="loginForm" class="antigravity-form">
                    @csrf

                    <!-- Trampa Honeypot contra Bots y Scripts Maliciosos -->
                    <input type="text" name="_auth_token_verify" value="" style="display:none !important;" tabindex="-1" autocomplete="off" aria-hidden="true">

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
                                   placeholder="nombre@empresa.com" value="{{ old('email') }}" required autofocus autocomplete="username" spellcheck="false" maxlength="180">
                        </div>
                    </div>

                    <!-- Campo Contraseña -->
                    <div class="glass-field-group">
                        <div class="glass-label-row">
                            <label for="password" class="glass-field-label">Contraseña</label>
                            <a href="{{ route('password.request') }}" class="glass-forgot-link">¿Olvidaste tu contraseña?</a>
                        </div>
                        <div class="glass-input-plane" id="passwordInputPlane">
                            <div class="glass-input-icon-lead">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lead-accent-icon">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                            </div>
                            <input type="password" id="password" name="password" class="glass-input-elem" placeholder="Ingresa tu contraseña" required autocomplete="current-password" maxlength="128">
                            <!-- Ojito toggle -->
                            <button type="button" class="eye-toggle-btn" id="togglePassword" aria-label="Mostrar u ocultar contraseña" title="Mostrar contraseña">
                                <i class="bi bi-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                        <div id="capsLockAlert" class="glass-caps-alert d-none" role="alert">
                            <i class="bi bi-capslock-fill"></i>
                            <span>Bloqueo de mayúsculas activado</span>
                        </div>
                    </div>

                    <!-- Checkbox Recordar -->
                    <div class="glass-options-row">
                        <label class="glass-checkbox-wrap" for="remember">
                            <input type="checkbox" name="remember" value="1" id="remember" class="glass-checkbox-elem">
                            <span class="glass-checkbox-custom"></span>
                            <span class="glass-checkbox-text">Mantener sesión iniciada</span>
                        </label>
                    </div>

                    <!-- Botón de Acción (Deshabilitado hasta que ambos campos estén completos) -->
                    <button type="submit" class="organic-stone-btn" id="loginSubmit" disabled>
                        <span class="btn-engraved-text">Ingresar al sistema</span>
                        <span class="btn-engraved-arrow">→</span>
                    </button>
                </form>

                <!-- Nota de Seguridad -->
                <div class="card-security-note">
                    <svg class="note-shield-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <path d="m9 12 2 2 4-4"/>
                    </svg>
                    <span>Para un acceso seguro y cifrado, verificado por humanos.</span>
                </div>

            </section>

        </div>

    </div>

</div>

<script>
    (function () {
        const form = document.getElementById('loginForm');
        const email = document.getElementById('email');
        const password = document.getElementById('password');
        const toggle = document.getElementById('togglePassword');
        const eyeIcon = document.getElementById('eyeIcon');
        const capsLockAlert = document.getElementById('capsLockAlert');
        const button = document.getElementById('loginSubmit');
        const toastContainer = document.getElementById('toastContainer');

        // ── Estado Dinámico del Botón: se habilita solo cuando ambos campos tienen valor ──
        function updateButtonState() {
            if (!button || !email || !password) return;
            const hasEmail = email.value.trim().length > 0;
            const hasPassword = password.value.length > 0;
            button.disabled = !(hasEmail && hasPassword);
        }

        if (email) {
            email.addEventListener('input', updateButtonState);
            email.addEventListener('change', updateButtonState);
        }
        if (password) {
            password.addEventListener('input', updateButtonState);
            password.addEventListener('change', updateButtonState);
        }

        // Verificar estado inicial (por si hay valor pre-rellenado via old())
        updateButtonState();

        // Función para disparar notificaciones Toast flotantes en relieve alto
        function triggerToast(title, message, type = 'danger') {
            if (!toastContainer) return;

            // Remover toasts previos para no saturar
            const existingToasts = toastContainer.querySelectorAll('.antigravity-toast');
            existingToasts.forEach(t => t.remove());

            const toast = document.createElement('div');
            toast.className = `antigravity-toast antigravity-toast-${type}`;
            toast.setAttribute('role', 'alert');

            const iconClass = type === 'danger' ? 'bi-shield-exclamation' : 'bi-check-circle-fill';

            toast.innerHTML = `
                <div class="toast-icon-wrap">
                    <i class="bi ${iconClass}"></i>
                </div>
                <div class="toast-body-wrap">
                    <div class="toast-title">${title}</div>
                    <div class="toast-desc">${message}</div>
                </div>
                <button type="button" class="toast-close-btn" aria-label="Cerrar">
                    <i class="bi bi-x-lg"></i>
                </button>
            `;

            toast.querySelector('.toast-close-btn').addEventListener('click', () => toast.remove());
            toastContainer.appendChild(toast);

            // Auto-cerrar después de 6 segundos
            setTimeout(() => {
                if (toast && toast.parentElement) {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-15px)';
                    setTimeout(() => toast.remove(), 250);
                }
            }, 6000);
        }

        // Validación inmediata antes del envío (Client-side validation)
        if (form) {
            form.addEventListener('submit', function (e) {
                const emailVal = email ? email.value.trim() : '';
                const passVal = password ? password.value : '';

                // 1. Validar correo obligatorio
                if (!emailVal) {
                    e.preventDefault();
                    triggerToast('Campo obligatorio', 'Por favor ingresa tu correo corporativo.');
                    if (email) {
                        email.focus();
                        highlightInput('emailInputPlane');
                    }
                    return false;
                }

                // 2. Validar formato de correo
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(emailVal)) {
                    e.preventDefault();
                    triggerToast('Formato incorrecto', 'Ingresa un formato de correo electrónico corporativo válido.');
                    if (email) {
                        email.focus();
                        highlightInput('emailInputPlane');
                    }
                    return false;
                }

                // 3. Validar contraseña obligatoria
                if (!passVal) {
                    e.preventDefault();
                    triggerToast('Campo obligatorio', 'Por favor ingresa tu contraseña de acceso.');
                    if (password) {
                        password.focus();
                        highlightInput('passwordInputPlane');
                    }
                    return false;
                }

                // Si todo es válido, activar estado de carga
                if (button) {
                    button.classList.add('is-loading');
                    button.setAttribute('aria-busy', 'true');
                    button.innerHTML = '<span class="erp-spinner" aria-hidden="true"></span><span class="btn-engraved-text">Verificando...</span>';
                }
            });
        }

        function highlightInput(planeId) {
            const plane = document.getElementById(planeId);
            if (plane) {
                plane.style.borderColor = '#dc2626';
                plane.style.boxShadow = '0 0 0 3px rgba(220, 38, 38, 0.2)';
                setTimeout(() => {
                    plane.style.borderColor = '';
                    plane.style.boxShadow = '';
                }, 2000);
            }
        }

        // Ojito toggle para mostrar / ocultar contraseña
        if (toggle && password && eyeIcon) {
            toggle.addEventListener('click', function () {
                const isPassword = password.getAttribute('type') === 'password';
                password.setAttribute('type', isPassword ? 'text' : 'password');
                eyeIcon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
                toggle.setAttribute('aria-label', isPassword ? 'Ocultar contraseña' : 'Mostrar contraseña');
                toggle.setAttribute('title', isPassword ? 'Ocultar contraseña' : 'Mostrar contraseña');
            });
        }

        // Alerta de Bloqueo de Mayúsculas
        if (password && capsLockAlert) {
            ['keydown', 'keyup'].forEach(eventType => {
                password.addEventListener(eventType, function (e) {
                    if (e.getModifierState && e.getModifierState('CapsLock')) {
                        capsLockAlert.classList.remove('d-none');
                    } else {
                        capsLockAlert.classList.add('d-none');
                    }
                });
            });
            password.addEventListener('blur', function () {
                capsLockAlert.classList.add('d-none');
            });
        }

        // ── Auto-cerrar Toasts del servidor (renderizados desde Blade) después de 8s ──
        if (toastContainer) {
            const serverToasts = toastContainer.querySelectorAll('.antigravity-toast');
            serverToasts.forEach(toast => {
                const closeBtn = toast.querySelector('.toast-close-btn');
                if (closeBtn) {
                    closeBtn.addEventListener('click', () => toast.remove());
                }
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
