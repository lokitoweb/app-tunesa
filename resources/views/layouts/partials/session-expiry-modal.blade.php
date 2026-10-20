{{-- Modal Ejecutivo de Advertencia de Expiración de Sesión por Inactividad --}}
<div class="modal fade" id="sessionExpiryModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="sessionExpiryModalLabel" aria-hidden="true" style="z-index: 1099;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content border-0 shadow-lg p-3 text-center" style="border-radius: 20px; background: var(--bg-surface, #ffffff);">
            <div class="modal-body p-4">
                {{-- Ícono circular estilo moderno (Info vectorial idéntico a referencia) --}}
                <div class="d-inline-flex align-items-center justify-content-center mb-3" style="width: 78px; height: 78px;">
                    <svg width="78" height="78" viewBox="0 0 78 78" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="39" cy="39" r="35" stroke="#00A3FF" stroke-width="3.5" fill="rgba(0, 163, 255, 0.04)"/>
                        <circle cx="39" cy="24.5" r="3.2" fill="#00A3FF"/>
                        <rect x="36.8" y="32.5" width="4.4" height="23" rx="2.2" fill="#00A3FF"/>
                    </svg>
                </div>

                {{-- Título y descripción concisa y ejecutiva --}}
                <h3 class="h4 fw-bold text-body mb-2" id="sessionExpiryModalLabel">Tu sesión está por expirar</h3>
                <p class="text-secondary mb-4 px-2" style="font-size: 0.935rem; line-height: 1.5;">
                    Llevas un rato sin actividad. Por seguridad, tu sesión se cerrará en <strong id="sessionCountdownSec" class="text-primary fw-bold">60</strong> segundos si no confirmas que sigues aquí.
                </p>

                {{-- Botones de Acción con espaciado amplio y elegante --}}
                <div class="d-flex align-items-center justify-content-center gap-2 flex-wrap pt-1">
                    <button type="button" id="btnKeepSessionAlive" onclick="window.keepSessionAlive()" class="btn btn-primary emp-btn-primary px-4 py-2.5 fw-bold shadow-sm" style="border-radius: 10px; font-size: 0.885rem; min-width: 155px; min-height: 42px;">
                        <span class="spinner-border spinner-border-sm d-none me-1" id="spinnerKeepAlive" role="status" aria-hidden="true"></span>
                        <span id="textKeepAlive">Seguir conectado</span>
                    </button>
                    <a href="{{ route('logout') }}?expired=1" class="btn btn-outline-secondary px-3 py-2.5 fw-semibold" style="border-radius: 10px; font-size: 0.885rem; min-height: 42px;">
                        Cerrar sesión ahora
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Micro Toast de Confirmación de Sesión --}}
<div id="sessionKeepAliveToast" class="emp-toast tone-success" role="alert" aria-live="polite">
    <i class="bi bi-check-circle-fill"></i>
    <span>Sesión renovada con éxito. Sigues conectado.</span>
</div>

<script>
(function() {
    // Configuración de tiempos de inactividad
    // Inactividad antes de advertir: 20 minutos (1200000 ms)
    // Tiempo de cuenta regresiva en el modal: 60 segundos
    const INACTIVITY_LIMIT_MS = 20 * 60 * 1000;
    const COUNTDOWN_TOTAL_SEC = 60;

    let inactivityTimer = null;
    let countdownInterval = null;
    let countdownLeft = COUNTDOWN_TOTAL_SEC;
    let modalInstance = null;

    // Canal de sincronización multi-pestaña para renovar sesión
    let sessionChannel = null;
    if (typeof BroadcastChannel !== 'undefined') {
        try {
            sessionChannel = new BroadcastChannel('erp_session_sync');
            sessionChannel.onmessage = function(ev) {
                if (ev.data && ev.data.type === 'SESSION_RENEWED') {
                    // Otra pestaña activa renovó la sesión: cerrar modal y reiniciar timer
                    hideExpiryModal();
                    resetInactivityTimer(false);
                }
            };
        } catch (e) {}
    }

    function getModalInstance() {
        const el = document.getElementById('sessionExpiryModal');
        if (!el || typeof bootstrap === 'undefined') return null;
        if (!modalInstance) {
            modalInstance = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });
        }
        return modalInstance;
    }

    function hideExpiryModal() {
        if (countdownInterval) clearInterval(countdownInterval);
        const modal = getModalInstance();
        if (modal) modal.hide();
    }

    function resetInactivityTimer(notifyChannel = true) {
        const el = document.getElementById('sessionExpiryModal');
        if (el && el.classList.contains('show')) return;

        if (inactivityTimer) clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(showExpiryWarning, INACTIVITY_LIMIT_MS);
    }

    function showExpiryWarning() {
        const modal = getModalInstance();
        if (!modal) return;

        countdownLeft = COUNTDOWN_TOTAL_SEC;
        const countEl = document.getElementById('sessionCountdownSec');
        if (countEl) countEl.textContent = countdownLeft;

        modal.show();

        if (countdownInterval) clearInterval(countdownInterval);
        countdownInterval = setInterval(function() {
            countdownLeft--;
            if (countEl) countEl.textContent = countdownLeft;

            if (countdownLeft <= 0) {
                clearInterval(countdownInterval);
                window.location.href = "{{ route('logout') }}?expired=1";
            }
        }, 1000);
    }

    // Exponer función de prueba o llamada manual en consola para testing
    window.showSessionExpiryWarning = showExpiryWarning;

    // Acción: Seguir conectado
    window.keepSessionAlive = async function() {
        const btn = document.getElementById('btnKeepSessionAlive');
        const spinner = document.getElementById('spinnerKeepAlive');
        const text = document.getElementById('textKeepAlive');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        if (btn) btn.disabled = true;
        if (spinner) spinner.classList.remove('d-none');
        if (text) text.textContent = 'Renovando…';

        try {
            const res = await fetch("{{ route('session.keep-alive') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (res.ok) {
                const data = await res.json();
                if (data.csrf_token) {
                    const metaCsrf = document.querySelector('meta[name="csrf-token"]');
                    if (metaCsrf) metaCsrf.setAttribute('content', data.csrf_token);
                    document.querySelectorAll('input[name="_token"]').forEach(input => {
                        input.value = data.csrf_token;
                    });
                }

                hideExpiryModal();

                // Notificar a otras pestañas
                if (sessionChannel) {
                    try { sessionChannel.postMessage({ type: 'SESSION_RENEWED' }); } catch (e) {}
                }

                // Micro toast de confirmación
                const toast = document.getElementById('sessionKeepAliveToast');
                if (toast) {
                    toast.classList.add('show');
                    setTimeout(() => toast.classList.remove('show'), 3500);
                }

                resetInactivityTimer();
            } else {
                // Si la sesión ya murió en el servidor (401/419)
                window.location.href = "{{ route('logout') }}?expired=1";
            }
        } catch (err) {
            console.error('Error renovando sesión:', err);
            window.location.reload();
        } finally {
            if (btn) btn.disabled = false;
            if (spinner) spinner.classList.add('d-none');
            if (text) text.textContent = 'Seguir conectado';
        }
    };

    // Escuchar actividad del usuario para reiniciar el temporizador de inactividad
    const activityEvents = ['mousedown', 'keydown', 'scroll', 'touchstart'];
    let lastActivityLogged = Date.now();

    activityEvents.forEach(evt => {
        window.addEventListener(evt, () => {
            const now = Date.now();
            if (now - lastActivityLogged > 2000) {
                lastActivityLogged = now;
                resetInactivityTimer();
            }
        }, { passive: true });
    });

    document.addEventListener('DOMContentLoaded', () => {
        resetInactivityTimer();
    });
})();
</script>
