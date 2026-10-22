/**
 * Panel ERP — Futuristic Operations & Telemetry Panel Engine
 * Live telemetry, dynamic time greeting, collapsible dock, toast alerts
 */
(function () {
    'use strict';

    const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function tiles(map) {
        return L.tileLayer(OSM_URL, { maxZoom: 19, attribution: OSM_ATTRIBUTION }).addTo(map);
    }

    function cssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function openPhoto(url, title, meta) {
        const modal = document.getElementById('photoModal');
        if (!modal) return;
        const img = document.getElementById('photoModalImg');
        const titleEl = document.getElementById('photoModalTitle');
        const metaEl = document.getElementById('photoModalMeta');
        if (img) img.src = url;
        if (titleEl) titleEl.textContent = title || 'Evidencia de marcación';
        if (metaEl) metaEl.textContent = meta || '';
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    // Modern Floating Toast Notification Engine
    const Toast = {
        getStack() {
            let stack = document.querySelector('.toast-stack');
            if (!stack) {
                stack = document.createElement('div');
                stack.className = 'toast-stack';
                stack.setAttribute('aria-live', 'polite');
                document.body.appendChild(stack);
            }
            return stack;
        },

        show({ type = 'info', title = '', message = '', duration = 4000 }) {
            const stack = this.getStack();
            const alert = document.createElement('div');
            
            const variantMap = {
                success: { icon: 'bi-check-lg', defTitle: 'Operación exitosa' },
                error:   { icon: 'bi-exclamation-triangle-fill', defTitle: 'Error del sistema' },
                warning: { icon: 'bi-exclamation-circle-fill', defTitle: 'Advertencia' },
                info:    { icon: 'bi-info-circle-fill', defTitle: 'Información del ERP' },
            };
            const config = variantMap[type] || variantMap.info;

            alert.className = `alert alert-${type} toast-item toast-${type}`;
            alert.setAttribute('role', 'alert');

            alert.innerHTML = `
                <div class="toast-icon-badge toast-badge-${type}">
                    <i class="bi ${config.icon}"></i>
                </div>
                <div class="toast-content">
                    <strong class="toast-title">${esc(title || config.defTitle)}</strong>
                    ${message ? `<span class="toast-text">${esc(message)}</span>` : ''}
                </div>
                <button type="button" class="toast-close-btn" aria-label="Cerrar">
                    <i class="bi bi-x-lg" style="font-size: 0.75rem;"></i>
                </button>
            `;

            const closeBtn = alert.querySelector('.toast-close-btn');
            const dismiss = () => {
                alert.classList.add('is-leaving');
                setTimeout(() => alert.remove(), 260);
            };

            if (closeBtn) closeBtn.addEventListener('click', dismiss);

            let timer = null;
            if (duration > 0) {
                timer = setTimeout(dismiss, duration);
                alert.addEventListener('mouseenter', () => clearTimeout(timer));
                alert.addEventListener('mouseleave', () => {
                    timer = setTimeout(dismiss, 2500);
                });
            }

            stack.appendChild(alert);
            return alert;
        },

        success(message, title = 'Operación completada') {
            return this.show({ type: 'success', title, message });
        },
        error(message, title = 'Atención requerida') {
            return this.show({ type: 'error', title, message, duration: 8000 });
        },
        warning(message, title = 'Aviso de telemetría') {
            return this.show({ type: 'warning', title, message, duration: 6000 });
        },
        info(message, title = 'Información') {
            return this.show({ type: 'info', title, message });
        }
    };

    // Dark / Light Theme
    function currentTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    }
    
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        try { localStorage.setItem('panel-theme', theme); } catch (e) { /* local storage blocked */ }
        const icon = document.querySelector('#themeToggle .bi');
        if (icon) icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
        document.dispatchEvent(new CustomEvent('panel:theme', { detail: theme }));
    }

    // Dynamic Time Greeting and Live Clock
    function startLiveClock() {
        const titleEl = document.getElementById('liveGreetingTitle');
        const clockEl = document.getElementById('liveClockText');

        function update() {
            const now = new Date();
            const hours = now.getHours();

            let greeting = 'BUENAS NOCHES';
            if (hours >= 6 && hours < 12) {
                greeting = 'BUENOS DÍAS';
            } else if (hours >= 12 && hours < 19) {
                greeting = 'BUENAS TARDES';
            }

            if (titleEl && titleEl.dataset.userName) {
                titleEl.textContent = `${greeting}, ${titleEl.dataset.userName.toUpperCase()}`;
            }

            if (clockEl) {
                const timeStr = now.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
                const dateStr = now.toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                const formattedDate = dateStr.charAt(0).toUpperCase() + dateStr.slice(1);
                clockEl.textContent = `${timeStr} • ${formattedDate}`;
            }
        }

        update();
        setInterval(update, 1000);
    }

    // Command Palette (Ctrl+K)
    function openCommandPalette() {
        const modalEl = document.getElementById('commandPaletteModal');
        if (!modalEl) return;
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        const input = document.getElementById('commandPaletteInput');
        if (input) {
            input.value = '';
            setTimeout(() => input.focus(), 150);
            filterPaletteItems('');
        }
    }

    function filterPaletteItems(query) {
        const q = (query || '').toLowerCase().trim();
        const items = document.querySelectorAll('.command-palette-item');
        const emptyState = document.getElementById('commandPaletteEmpty');
        let visibleCount = 0;

        items.forEach(item => {
            const label = ((item.dataset.label || '') + ' ' + item.textContent).toLowerCase();
            const matches = !q || label.includes(q);
            item.style.display = matches ? 'flex' : 'none';
            item.classList.remove('is-selected');
            if (matches) visibleCount++;
        });

        if (emptyState) {
            emptyState.classList.toggle('d-none', visibleCount > 0);
        }

        if (visibleCount > 0) {
            const firstVisible = Array.from(items).find(el => el.style.display !== 'none');
            if (firstVisible) firstVisible.classList.add('is-selected');
        }
    }

    function setupCommandPalette() {
        const input = document.getElementById('commandPaletteInput');
        if (input) {
            input.addEventListener('input', (e) => {
                filterPaletteItems(e.target.value);
            });

            input.addEventListener('keydown', (e) => {
                const visibleItems = Array.from(document.querySelectorAll('.command-palette-item')).filter(el => el.style.display !== 'none');
                if (!visibleItems.length) return;

                const currentIndex = visibleItems.findIndex(el => el.classList.contains('is-selected'));

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const nextIndex = (currentIndex + 1) % visibleItems.length;
                    visibleItems.forEach(el => el.classList.remove('is-selected'));
                    visibleItems[nextIndex].classList.add('is-selected');
                    visibleItems[nextIndex].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prevIndex = (currentIndex - 1 + visibleItems.length) % visibleItems.length;
                    visibleItems.forEach(el => el.classList.remove('is-selected'));
                    visibleItems[prevIndex].classList.add('is-selected');
                    visibleItems[prevIndex].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentIndex >= 0 && visibleItems[currentIndex]) {
                        visibleItems[currentIndex].click();
                    } else if (visibleItems[0]) {
                        visibleItems[0].click();
                    }
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        // Theme initialization (Light mode standard)
        const toggle = document.getElementById('themeToggle');
        let initialTheme = 'light';
        try {
            initialTheme = localStorage.getItem('panel-theme') || 'light';
        } catch (e) {}
        applyTheme(initialTheme);

        if (toggle) {
            toggle.addEventListener('click', () => {
                const nextTheme = currentTheme() === 'dark' ? 'light' : 'dark';
                applyTheme(nextTheme);
            });
        }

        // Live Clock
        startLiveClock();

        // Setup Command Palette
        setupCommandPalette();

        // Sidebar Collapse / Expand Toggle
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarMobileToggle = document.getElementById('sidebarMobileToggle');

        if (sidebar && sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.toggle('is-expanded');
            });
        }
        if (sidebar && sidebarMobileToggle) {
            sidebarMobileToggle.addEventListener('click', () => {
                sidebar.classList.toggle('is-expanded');
            });
        }

        // Fullscreen toggle
        const fullscreenBtn = document.getElementById('fullscreenToggle');
        if (fullscreenBtn) {
            fullscreenBtn.addEventListener('click', () => {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().catch(err => console.warn(err));
                } else {
                    document.exitFullscreen().catch(err => console.warn(err));
                }
            });
        }

        // Auto-dismiss and close handler for Blade-rendered toasts
        document.querySelectorAll('.toast-stack > .alert').forEach((el) => {
            const closeBtn = el.querySelector('.toast-close-btn');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    el.classList.add('is-leaving');
                    setTimeout(() => el.remove(), 260);
                });
            }
            setTimeout(() => {
                el.classList.add('is-leaving');
                setTimeout(() => el.remove(), 260);
            }, 5000);
        });

        // Click on thumbnail with data-photo opens evidence modal
        document.addEventListener('click', (e) => {
            const el = e.target.closest('[data-photo]');
            if (!el) return;
            e.preventDefault();
            openPhoto(el.dataset.photo, el.dataset.photoTitle, el.dataset.photoMeta);
        });

        // Global Keyboard Shortcut: Ctrl+K or Cmd+K opens Command Palette
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                openCommandPalette();
            }
        });
    });

    // Confirmación con modal del tema (reemplaza confirm() nativo)
    function confirmDialog(message, { title = '¿Confirmas esta acción?', confirmText = 'Confirmar', cancelText = 'Cancelar', danger = false } = {}) {
        return new Promise((resolve) => {
            if (typeof bootstrap === 'undefined') {
                resolve(window.confirm(message));
                return;
            }
            const wrap = document.createElement('div');
            wrap.className = 'modal fade';
            wrap.tabIndex = -1;
            wrap.setAttribute('aria-modal', 'true');
            wrap.setAttribute('role', 'dialog');
            wrap.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow-lg">
                        <div class="modal-body p-4 text-center">
                            <div class="mb-3"><i class="bi ${danger ? 'bi-exclamation-octagon-fill text-danger' : 'bi-question-circle-fill text-primary'}" style="font-size: 2.25rem;"></i></div>
                            <h2 class="h5 fw-bold mb-2">${esc(title)}</h2>
                            <p class="text-body-secondary mb-0">${esc(message)}</p>
                        </div>
                        <div class="modal-footer border-0 justify-content-center pt-0 pb-4 gap-2">
                            <button type="button" class="btn btn-outline-secondary px-4" data-action="cancel">${esc(cancelText)}</button>
                            <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'} px-4" data-action="ok">${esc(confirmText)}</button>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(wrap);
            const modal = new bootstrap.Modal(wrap);
            let answer = false;
            wrap.querySelector('[data-action="ok"]').addEventListener('click', () => { answer = true; modal.hide(); });
            wrap.querySelector('[data-action="cancel"]').addEventListener('click', () => modal.hide());
            wrap.addEventListener('hidden.bs.modal', () => { wrap.remove(); resolve(answer); });
            modal.show();
        });
    }

    function toast(message, type = 'info', title = '') {
        return Toast.show({ type: type === 'danger' ? 'error' : type, title, message, duration: type === 'error' || type === 'danger' ? 8000 : 4000 });
    }

    // <form data-confirm="..."> y <a|button data-confirm="..."> piden confirmación con el modal
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') return;
        e.preventDefault();
        const submitter = e.submitter;
        confirmDialog(form.dataset.confirm, {
            title: form.dataset.confirmTitle || undefined,
            confirmText: form.dataset.confirmButton || undefined,
            danger: form.dataset.confirmDanger === '1',
        }).then((ok) => {
            if (!ok) return;
            form.dataset.confirmed = '1';
            form.requestSubmit ? form.requestSubmit(submitter || undefined) : form.submit();
            delete form.dataset.confirmed;
        });
    }, true);

    window.Panel = { esc, tiles, cssVar, openPhoto, confirm: confirmDialog, toast };
    window.Toast = Toast;
    window.openPhotoModal = openPhoto;
    window.openCommandPalette = openCommandPalette;
})();
