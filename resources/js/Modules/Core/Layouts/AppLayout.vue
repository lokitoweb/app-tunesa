<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import ConfirmModal from '@/Modules/Core/Components/ConfirmModal.vue';
import TenantPickerModal from '@/Modules/Core/Components/TenantPickerModal.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

defineProps({ title: { type: String, default: 'Panel' } });

const { confirmState, onConfirm, onCancel } = useConfirm();

const page = usePage();
const auth = computed(() => page.props.auth || {});
const brand = computed(() => page.props.brand || {});
const bell = computed(() => page.props.notifications || { unread: 0, latest: [] });
const errors = computed(() => Object.values(page.props.errors ?? {}));

// Toasts cerrados; los de éxito e información se cierran solos a los 6 s
const dismissed = reactive(new Set());
watch(() => page.props.flash, (flash) => {
    dismissed.clear();
    for (const key of ['success', 'info']) {
        if (flash?.[key]) setTimeout(() => dismissed.add(key + flash[key]), 6000);
    }
}, { immediate: true });
watch(() => page.props.errors, () => dismissed.delete('errors'));

const FLASH = {
    success: ['success', 'bi-check-lg', 'Listo'],
    info: ['info', 'bi-info-circle-fill', 'Aviso'],
    error: ['danger', 'bi-exclamation-triangle-fill', 'Atención'],
};

// Tema claro / oscuro guardado en el navegador (misma clave que el layout Blade)
const theme = ref('light');
onMounted(() => {
    theme.value = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
});
function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', theme.value);
    try { localStorage.setItem('panel-theme', theme.value); } catch (e) { /* almacenamiento bloqueado */ }
}

// Sidebar Dock Colapsable / Deslizante (Sincronizado con Blade)
const isExpanded = ref(false);
function toggleSidebar() {
    isExpanded.value = !isExpanded.value;
}

// Pantalla Completa
function toggleFullscreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch((err) => console.warn(err));
    } else {
        document.exitFullscreen().catch((err) => console.warn(err));
    }
}

// Modal Global Command Palette (Ctrl+K)
const showPalette = ref(false);
const paletteQuery = ref('');
const paletteInput = ref(null);

const flattenedNav = computed(() => {
    const list = [];
    for (const section of page.props.nav || []) {
        for (const item of section.items || []) {
            list.push({ ...item, heading: section.heading });
        }
    }
    return list;
});

const filteredNav = computed(() => {
    const q = (paletteQuery.value || '').toLowerCase().trim();
    if (!q) return flattenedNav.value;
    return flattenedNav.value.filter(item => 
        (item.label || '').toLowerCase().includes(q) || 
        (item.heading || '').toLowerCase().includes(q)
    );
});

function openCommandPalette() {
    showPalette.value = true;
    paletteQuery.value = '';
    setTimeout(() => paletteInput.value?.focus(), 150);
}
function closeCommandPalette() {
    showPalette.value = false;
}

function handleGlobalKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        openCommandPalette();
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleGlobalKeydown);
});
onUnmounted(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
});
</script>

<template>
    <Head :title="`${title} — ${brand.trade_name || 'Grupo Negreiros'}`" />

    <div class="panel-shell">
        <!-- SIDEBAR: Dock colapsable con auto-expansión al pasar el mouse o al hacer clic -->
        <aside id="sidebar" class="panel-sidebar" :class="{ 'is-expanded': isExpanded }" tabindex="-1" aria-label="Menú principal">
            <div class="sidebar-header">
                <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" title="Expandir / Contraer menú" aria-label="Expandir o contraer menú" @click="toggleSidebar">
                    <i class="bi bi-layout-sidebar"></i>
                </button>
                <a href="/dashboard" class="sidebar-logo-link" :title="brand.trade_name">
                    <img :src="brand.logo_url" :alt="brand.trade_name" class="sidebar-logo-img">
                </a>
            </div>

            <nav class="sidebar-nav" aria-label="Navegación del ERP">
                <template v-for="section in page.props.nav" :key="section.heading">
                    <div class="nav-heading">{{ section.heading }}</div>
                    <component :is="item.spa ? Link : 'a'" v-for="item in section.items" :key="item.label" :href="item.href"
                               class="sidebar-link" :class="{ active: item.active }" :aria-current="item.active ? 'page' : null" :title="item.label">
                        <i class="bi" :class="`bi-${item.icon}`"></i>
                        <span class="link-label">{{ item.label }}</span>
                        <span v-if="item.badge > 0" class="badge rounded-pill text-bg-danger sidebar-badge ms-auto">{{ item.badge }}</span>
                    </component>
                </template>
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-shield-btn" title="ERP Verificado · Conexión Cifrada SSL">
                    <i class="bi bi-shield-check"></i>
                </div>
            </div>
        </aside>

        <!-- CONTENIDO -->
        <div class="panel-main">
            <header class="panel-topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle-btn d-lg-none" type="button" id="sidebarMobileToggle" aria-label="Abrir menú" @click="toggleSidebar">
                        <i class="bi bi-list"></i>
                    </button>
                    <!-- Selector de empresa del Super Admin -->
                    <form v-if="auth.is_super_admin" method="POST" action="/admin/switch-tenant" class="m-0">
                        <input type="hidden" name="_token" :value="page.props.csrf">
                        <div class="topbar-tenant-selector" title="Filtrar vista por empresa">
                            <span class="tenant-selector-icon">
                                <i class="bi bi-buildings"></i>
                            </span>
                            <select name="tenant_id" class="tenant-selector-select" aria-label="Empresa a administrar" @change="$event.target.form.submit()">
                                <option value="" :selected="!brand.tenant_id">Todas las empresas</option>
                                <option v-for="t in page.props.tenants" :key="t.id" :value="t.id" :selected="t.id === brand.tenant_id">{{ t.company_name }}</option>
                            </select>
                            <i class="bi bi-chevron-down tenant-selector-arrow"></i>
                        </div>
                    </form>
                    <span v-else class="topbar-company text-truncate">
                        <i class="bi bi-building text-primary me-1"></i> {{ brand.company_name }}
                    </span>
                </div>

                <div class="topbar-right">
                    <!-- Herramientas Rápidas del ERP: Pantalla Completa -->
                    <button type="button" class="topbar-icon-btn d-none d-sm-inline-flex" id="fullscreenToggle" title="Pantalla completa" aria-label="Pantalla completa" @click="toggleFullscreen">
                        <i class="bi bi-fullscreen"></i>
                    </button>

                    <!-- Buscador Compacto (Ctrl+K) -->
                    <div class="topbar-search-container d-none d-md-block">
                        <button type="button" class="topbar-search-box" title="Buscar en cualquier módulo, colaborador o reporte (Ctrl+K)" @click="openCommandPalette">
                            <i class="bi bi-search text-secondary"></i>
                            <span class="search-box-text">Buscar...</span>
                            <kbd class="topbar-search-kbd">Ctrl+K</kbd>
                        </button>
                    </div>

                    <!-- Botón de Tema Claro / Oscuro -->
                    <button type="button" class="topbar-icon-btn" id="themeToggle" title="Tema claro / oscuro" aria-label="Cambiar tema" @click="toggleTheme">
                        <i class="bi" :class="theme === 'dark' ? 'bi-sun' : 'bi-moon-stars'"></i>
                    </button>

                    <!-- Notificaciones -->
                    <div class="dropdown">
                        <a href="/notifications" class="topbar-icon-btn position-relative" title="Notificaciones del ERP" aria-label="Notificaciones">
                            <i class="bi bi-bell"></i>
                            <span v-if="bell.unread > 0" class="badge-count badge-count-red">{{ bell.unread }}</span>
                        </a>
                    </div>

                    <!-- Menú de Usuario -->
                    <div class="dropdown">
                        <button type="button" class="topbar-user" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menú de usuario">
                            <div class="topbar-avatar">{{ auth.initials }}</div>
                            <div class="topbar-user-info d-none d-md-block text-start">
                                <span class="topbar-user-name">{{ auth.name }}</span>
                                <span class="topbar-user-role">{{ auth.role_label }}</span>
                            </div>
                            <i class="bi bi-chevron-down ms-1 text-secondary" style="font-size: 0.7rem;"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end shadow-lg user-profile-dropdown">
                            <div class="px-3 py-2 border-bottom user-profile-header">
                                <div class="fw-bold user-profile-name small">{{ auth.name }}</div>
                                <div class="user-profile-email small text-truncate">{{ auth.email }}</div>
                            </div>
                            <a class="dropdown-item py-2 d-flex align-items-center gap-2 user-profile-item" href="/account">
                                <i class="bi bi-person-gear"></i> <span>Mi cuenta</span>
                            </a>
                            <a class="dropdown-item py-2 d-flex align-items-center gap-2 user-profile-item" href="/notifications">
                                <i class="bi bi-bell"></i> <span>Notificaciones</span>
                                <span v-if="bell.unread > 0" class="badge bg-danger ms-auto">{{ bell.unread }}</span>
                            </a>
                            <div class="dropdown-divider user-profile-divider"></div>
                            <form method="POST" action="/logout" class="m-0">
                                <input type="hidden" name="_token" :value="page.props.csrf">
                                <button type="submit" class="dropdown-item py-2 d-flex align-items-center gap-2 text-danger user-profile-logout">
                                    <i class="bi bi-box-arrow-right"></i> <span>Cerrar sesión</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main id="main-content" class="panel-content">
                <!-- Banner de Vista Global -->
                <div v-if="page.props.globalView" class="alert alert-primary d-flex align-items-center gap-2 global-view-banner mb-4" role="status" style="border-radius: 10px;">
                    <i class="bi bi-globe2 fs-5 global-view-icon"></i>
                    <div>Vista global: mostrando registros de todas las empresas. Al crear algo te preguntaremos en qué empresa.</div>
                </div>

                <!-- Avisos flotantes (toasts) -->
                <div class="toast-stack" aria-live="polite">
                    <template v-for="(text, key) in page.props.flash" :key="key + text">
                        <div v-if="!dismissed.has(key + text) && FLASH[key]" class="alert toast-item" :class="`alert-${FLASH[key][0]} toast-${FLASH[key][0]}`" role="alert">
                            <div class="toast-icon-badge" :class="`toast-badge-${FLASH[key][0]}`">
                                <i class="bi" :class="FLASH[key][1]"></i>
                            </div>
                            <div class="toast-content">
                                <strong class="toast-title">{{ FLASH[key][2] }}</strong>
                                <span class="toast-text">{{ text }}</span>
                            </div>
                            <button type="button" class="toast-close-btn" aria-label="Cerrar" @click="dismissed.add(key + text)">
                                <i class="bi bi-x-lg" style="font-size: 0.75rem;"></i>
                            </button>
                        </div>
                    </template>

                    <div v-if="errors.length && !dismissed.has('errors')" class="alert alert-danger toast-item toast-danger" role="alert">
                        <div class="toast-icon-badge toast-badge-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </div>
                        <div class="toast-content">
                            <strong class="toast-title">Revisa los datos</strong>
                            <ul class="mb-0 toast-text ps-3">
                                <li v-for="error in errors" :key="error">{{ error }}</li>
                            </ul>
                        </div>
                        <button type="button" class="toast-close-btn" aria-label="Cerrar" @click="dismissed.add('errors')">
                            <i class="bi bi-x-lg" style="font-size: 0.75rem;"></i>
                        </button>
                    </div>
                </div>

                <slot />
            </main>
        </div>
    </div>

    <!-- Modal Global Command Palette (Ctrl+K) -->
    <div v-if="showPalette" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5); z-index: 1060;" @click.self="closeCommandPalette">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content command-palette-content">
                <div class="command-palette-header">
                    <i class="bi bi-search command-palette-search-icon"></i>
                    <input ref="paletteInput" v-model="paletteQuery" type="text" class="command-palette-input" placeholder="Buscar módulo, colaborador, sede o reporte..." autocomplete="off" @keydown.esc="closeCommandPalette">
                    <kbd class="command-palette-kbd-esc" @click="closeCommandPalette">ESC</kbd>
                </div>
                <div class="command-palette-body">
                    <div class="command-palette-section-title">Navegación del Sistema</div>
                    <div class="command-palette-list">
                        <component :is="item.spa ? Link : 'a'" v-for="item in filteredNav" :key="item.label" :href="item.href" class="command-palette-item" @click="closeCommandPalette">
                            <div class="d-flex align-items-center gap-3">
                                <span class="command-item-icon"><i class="bi" :class="`bi-${item.icon}`"></i></span>
                                <div>
                                    <div class="command-item-title">{{ item.label }}</div>
                                    <div class="command-item-sub">{{ item.heading }}</div>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right command-item-arrow"></i>
                        </component>
                        <a href="/account" class="command-palette-item" @click="closeCommandPalette">
                            <div class="d-flex align-items-center gap-3">
                                <span class="command-item-icon"><i class="bi bi-person-gear"></i></span>
                                <div>
                                    <div class="command-item-title">Mi cuenta</div>
                                    <div class="command-item-sub">Perfil y preferencias del usuario</div>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right command-item-arrow"></i>
                        </a>
                        <a href="/notifications" class="command-palette-item" @click="closeCommandPalette">
                            <div class="d-flex align-items-center gap-3">
                                <span class="command-item-icon"><i class="bi bi-bell"></i></span>
                                <div>
                                    <div class="command-item-title">Notificaciones</div>
                                    <div class="command-item-sub">Avisos y solicitudes del ERP</div>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right command-item-arrow"></i>
                        </a>
                    </div>
                    <div v-if="!filteredNav.length" class="text-center py-4 text-muted small">
                        <i class="bi bi-search fs-3 d-block mb-1 text-secondary"></i>
                        No se encontraron coincidencias para su búsqueda.
                    </div>
                </div>
                <div class="command-palette-footer">
                    <span><kbd class="command-palette-kbd">ESC</kbd> para cerrar</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Global de Confirmación Ejecutiva (Reemplazo moderno de confirm()) -->
    <ConfirmModal 
        :show="confirmState.show" 
        :title="confirmState.title" 
        :message="confirmState.message" 
        :confirm-text="confirmState.confirmText" 
        :cancel-text="confirmState.cancelText" 
        :variant="confirmState.variant" 
        :icon="confirmState.icon" 
        @confirm="onConfirm" 
        @cancel="onCancel" 
        @close="onCancel" 
    />

    <!-- Super Admin en la vista global: "¿En qué empresa?" antes de crear -->
    <TenantPickerModal v-if="auth.is_super_admin" />
</template>
