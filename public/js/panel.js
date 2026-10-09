/**
 * TuneSA — utilidades compartidas del panel.
 *
 * - Panel.tiles(map): capa base OpenStreetMap (gratuita, sin API key).
 * - Panel.esc(texto): escapa HTML antes de insertarlo con innerHTML.
 * - Panel.openPhoto(url, título, meta): selfie ampliada en un modal.
 * - Tema claro / oscuro guardado en el navegador.
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
        document.getElementById('photoModalImg').src = url;
        document.getElementById('photoModalTitle').textContent = title || 'Evidencia de marcación';
        document.getElementById('photoModalMeta').textContent = meta || '';
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    // Tema claro / oscuro
    function currentTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    }
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        try { localStorage.setItem('panel-theme', theme); } catch (e) { /* almacenamiento bloqueado */ }
        const icon = document.querySelector('#themeToggle .bi');
        if (icon) icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
        document.dispatchEvent(new CustomEvent('panel:theme', { detail: theme }));
    }

    document.addEventListener('DOMContentLoaded', () => {
        const toggle = document.getElementById('themeToggle');
        if (toggle) {
            applyTheme(currentTheme());
            toggle.addEventListener('click', () => applyTheme(currentTheme() === 'dark' ? 'light' : 'dark'));
        }

        // Miniaturas con data-photo abren el modal de selfie
        document.addEventListener('click', (e) => {
            const el = e.target.closest('[data-photo]');
            if (!el) return;
            e.preventDefault();
            openPhoto(el.dataset.photo, el.dataset.photoTitle, el.dataset.photoMeta);
        });
    });

    window.Panel = { esc, tiles, cssVar, openPhoto };
    // Compatibilidad con vistas antiguas
    window.openPhotoModal = openPhoto;
})();
