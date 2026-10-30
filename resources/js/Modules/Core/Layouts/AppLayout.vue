<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ConfirmModal from '@/Modules/Core/Components/ConfirmModal.vue';
import FlashToasts from '@/Modules/Core/Components/FlashToasts.vue';
import TenantPickerModal from '@/Modules/Core/Components/TenantPickerModal.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

defineProps({ title: { type: String, default: 'Panel' } });

const { confirmState, onConfirm, onCancel } = useConfirm();

const page = usePage();
const auth = computed(() => page.props.auth || {});
const brand = computed(() => page.props.brand || {});
const bell = computed(() => page.props.notifications || { unread: 0, latest: [] });

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

// Menú lateral (mismo comportamiento que panel.js en Blade): en escritorio se reduce a íconos y se recuerda;
// en celular se abre como panel deslizable y se cierra con Escape, tocando afuera o al navegar
const drawerOpen = ref(false);
const isDrawer = () => window.matchMedia('(max-width: 991.98px)').matches;
function toggleSidebar() {
    if (isDrawer()) { drawerOpen.value = false; return; }
    const rail = document.documentElement.getAttribute('data-sidebar') !== 'rail';
    if (rail) document.documentElement.setAttribute('data-sidebar', 'rail');
    else document.documentElement.removeAttribute('data-sidebar');
    try { rail ? localStorage.removeItem('panel-sidebar-pinned') : localStorage.setItem('panel-sidebar-pinned', '1'); } catch (e) { /* almacenamiento bloqueado */ }
}
const closeDrawerOnEscape = (e) => { if (e.key === 'Escape') drawerOpen.value = false; };
let stopNavigateListener = null;
onMounted(() => {
    document.addEventListener('keydown', closeDrawerOnEscape);
    stopNavigateListener = router.on('navigate', () => { drawerOpen.value = false; });
});
onUnmounted(() => {
    document.removeEventListener('keydown', closeDrawerOnEscape);
    stopNavigateListener?.();
});

// Selector de Empresa del Super Admin
const showTenantMenu = ref(false);
const tenantSearch = ref('');
const filteredTenants = computed(() => {
    const list = page.props.tenants || [];
    if (!tenantSearch.value.trim()) return list;
    const q = tenantSearch.value.trim().toLowerCase();
    return list.filter(t => (t.company_name || '').toLowerCase().includes(q));
});
function selectTenant(id) {
    showTenantMenu.value = false;
    const form = document.getElementById('vueTenantForm');
    if (form) {
        form.querySelector('input[name="tenant_id"]').value = id;
        form.submit();
    }
}
function handleGlobalClick(e) {
    if (!e.target.closest('.topbar-tenant-dropdown')) {
        showTenantMenu.value = false;
    }
}
onMounted(() => {
    document.addEventListener('click', handleGlobalClick);
});
onUnmounted(() => {
    document.removeEventListener('click', handleGlobalClick);
});

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
    <Head :title="brand.trade_name ? `${title} — ${brand.trade_name}` : title" />

    <div class="panel-shell">
        <!-- SIDEBAR: Dock colapsable con auto-expansión al pasar el mouse o al hacer clic -->
        <aside id="sidebar" class="panel-sidebar" :class="{ 'is-open': drawerOpen }" tabindex="-1" aria-label="Menú principal">
            <div class="sidebar-header">
                <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" title="Fijar el menú abierto o volver a la barra de íconos" aria-label="Fijar el menú abierto o volver a la barra de íconos" @click="toggleSidebar">
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
        <div class="sidebar-backdrop" :class="{ 'is-open': drawerOpen }" aria-hidden="true" @click="drawerOpen = false"></div>

        <!-- CONTENIDO -->
        <div class="panel-main">
            <header class="panel-topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle-btn d-lg-none" type="button" id="sidebarMobileToggle" aria-label="Abrir menú" aria-controls="sidebar" :aria-expanded="drawerOpen" @click="drawerOpen = true">
                        <i class="bi bi-list"></i>
                    </button>
                    <!-- Selector de empresa del Super Admin -->
                    <form v-if="auth.is_super_admin" method="POST" action="/admin/switch-tenant" class="m-0 d-inline-flex align-items-center" id="vueTenantForm">
                        <input type="hidden" name="_token" :value="page.props.csrf">
                        <input type="hidden" name="tenant_id" :value="brand.tenant_id || ''">
                        <div class="dropdown topbar-tenant-dropdown" :class="{ show: showTenantMenu }">
                            <button type="button" class="topbar-tenant-btn" @click.stop="showTenantMenu = !showTenantMenu" :title="brand.company_name || 'Todas las empresas'">
                                <span class="tenant-btn-icon">
                                    <i class="bi" :class="brand.tenant_id ? 'bi-building' : 'bi-globe2'"></i>
                                </span>
                                <span class="tenant-btn-text">{{ brand.company_name || 'Todas las empresas' }}</span>
                                <i class="bi bi-chevron-down tenant-btn-chevron"></i>
                            </button>
                            <div v-if="showTenantMenu" class="dropdown-menu dropdown-menu-start topbar-tenant-menu shadow-lg show" style="display: block;">
                                <div class="tenant-menu-header">
                                    <span class="tenant-menu-title"><i class="bi bi-buildings-fill text-primary me-1"></i> EMPRESA ACTIVA</span>
                                    <span class="tenant-menu-badge">{{ (page.props.tenants || []).length }} disp.</span>
                                </div>
                                <div v-if="(page.props.tenants || []).length >= 4" class="tenant-search-box">
                                    <i class="bi bi-search tenant-search-icon"></i>
                                    <input type="text" v-model="tenantSearch" class="tenant-search-input" placeholder="Buscar empresa..." autocomplete="off" @click.stop>
                                </div>
                                <div class="tenant-options-list">
                                    <button type="button" class="tenant-menu-item" :class="{ 'is-selected': !brand.tenant_id }" @click="selectTenant('')">
                                        <span class="tenant-item-icon is-global">
                                            <i class="bi bi-globe2"></i>
                                        </span>
                                        <div class="tenant-item-content">
                                            <span class="tenant-item-name">Todas las empresas</span>
                                            <span class="tenant-item-sub">Consolidado global de la plataforma</span>
                                        </div>
                                        <i v-if="!brand.tenant_id" class="bi bi-check2 tenant-item-check"></i>
                                    </button>
                                    <div class="tenant-menu-divider"></div>
                                    <button v-for="t in filteredTenants" :key="t.id" type="button" class="tenant-menu-item" :class="{ 'is-selected': brand.tenant_id === t.id }" @click="selectTenant(t.id)">
                                        <span class="tenant-item-icon is-tenant">
                                            <i class="bi bi-building"></i>
                                        </span>
                                        <div class="tenant-item-content">
                                            <span class="tenant-item-name">{{ t.company_name }}</span>
                                            <span class="tenant-item-sub">Empresa activa</span>
                                        </div>
                                        <i v-if="brand.tenant_id === t.id" class="bi bi-check2 tenant-item-check"></i>
                                    </button>
                                    <div v-if="filteredTenants.length === 0" class="tenant-empty-search text-center py-2 text-muted small">
                                        <i class="bi bi-search me-1"></i> No se encontraron empresas
                                    </div>
                                </div>
                            </div>
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
                <FlashToasts />

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
