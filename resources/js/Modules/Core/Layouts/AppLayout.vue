<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({ title: { type: String, default: 'Panel' } });

const page = usePage();
const auth = computed(() => page.props.auth);
const brand = computed(() => page.props.brand);
const bell = computed(() => page.props.notifications);
const errors = computed(() => Object.values(page.props.errors ?? {}));

// Avisos cerrados por el usuario; los de éxito se cierran solos a los 6 s
const dismissed = reactive(new Set());
watch(() => page.props.flash, (flash) => {
    dismissed.clear();
    const text = flash?.success;
    if (text) setTimeout(() => dismissed.add('success' + text), 6000);
}, { immediate: true });

const FLASH = {
    success: ['success', 'bi-check-circle'],
    info: ['info', 'bi-info-circle'],
    error: ['danger', 'bi-exclamation-octagon'],
};

// Tema claro / oscuro guardado en el navegador (misma clave que el layout Blade)
const theme = ref('light');
onMounted(() => { theme.value = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light'; });
function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', theme.value);
    try { localStorage.setItem('panel-theme', theme.value); } catch (e) { /* almacenamiento bloqueado */ }
}
</script>

<template>
    <Head :title="`${title} — ${brand.trade_name}`" />

    <div class="panel-shell">
        <!-- SIDEBAR: fijo en escritorio (lg+), offcanvas en móvil -->
        <aside id="sidebar" class="offcanvas-lg offcanvas-start panel-sidebar" tabindex="-1" aria-labelledby="sidebarLabel">
            <div class="sidebar-brand">
                <a href="/dashboard" class="d-flex align-items-center gap-2 text-decoration-none">
                    <template v-if="brand.logo_url">
                        <img :src="brand.logo_url" alt="" class="brand-logo brand-logo-light" :class="{ 'has-dark': brand.logo_dark_url }">
                        <img v-if="brand.logo_dark_url" :src="brand.logo_dark_url" alt="" class="brand-logo brand-logo-dark">
                    </template>
                    <span v-else class="brand-mark"><i class="bi bi-geo-alt-fill"></i></span>
                    <span class="lh-sm">
                        <span id="sidebarLabel" class="d-block fw-semibold text-body text-truncate" style="max-width: 160px;">{{ brand.trade_name }}</span>
                        <small class="text-body-secondary">Asistencia y RRHH</small>
                    </span>
                </a>
                <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Cerrar menú"></button>
            </div>

            <nav class="sidebar-nav" aria-label="Menú principal">
                <template v-for="section in page.props.nav" :key="section.heading">
                    <div class="nav-heading">{{ section.heading }}</div>
                    <!-- Pantallas ya migradas navegan sin recargar; las Blade, con enlace normal -->
                    <component :is="item.spa ? Link : 'a'" v-for="item in section.items" :key="item.label" :href="item.href"
                               class="sidebar-link" :class="{ active: item.active }" :aria-current="item.active ? 'page' : null">
                        <i class="bi" :class="`bi-${item.icon}`"></i>
                        <span class="flex-grow-1">{{ item.label }}</span>
                        <span v-if="item.badge > 0" class="badge rounded-pill text-bg-danger">{{ item.badge }}</span>
                    </component>
                </template>
            </nav>

            <div class="sidebar-footer">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="avatar avatar-sm">{{ auth.initials }}</span>
                    <div class="overflow-hidden">
                        <div class="small fw-semibold text-truncate">{{ auth.name }}</div>
                        <div class="small text-body-secondary">{{ auth.role_label }}</div>
                    </div>
                </div>
                <form method="POST" action="/logout">
                    <input type="hidden" name="_token" :value="page.props.csrf">
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i> Cerrar sesión</button>
                </form>
            </div>
        </aside>

        <div class="panel-main">
            <header class="panel-topbar">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <button class="btn btn-light border d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Abrir menú">
                        <i class="bi bi-list"></i>
                    </button>
                    <span class="badge text-bg-light border text-truncate d-none d-sm-inline-block">{{ brand.company_name }}</span>
                    <form v-if="auth.is_super_admin" method="POST" action="/admin/switch-tenant" class="m-0">
                        <input type="hidden" name="_token" :value="page.props.csrf">
                        <select name="tenant_id" class="form-select form-select-sm tenant-switch" aria-label="Empresa a administrar" @change="$event.target.form.submit()">
                            <option value="">Vista global · todas las empresas</option>
                            <option v-for="t in page.props.tenants" :key="t.id" :value="t.id" :selected="t.id === brand.tenant_id">{{ t.company_name }}</option>
                        </select>
                    </form>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="small text-body-secondary d-none d-md-inline">{{ page.props.today }}</span>

                    <div class="dropdown">
                        <button type="button" class="btn btn-light border btn-sm position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                                :aria-label="bell.unread ? `Notificaciones (${bell.unread} sin leer)` : 'Notificaciones'" title="Notificaciones">
                            <i class="bi bi-bell"></i>
                            <span v-if="bell.unread > 0" class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger notif-count">{{ bell.unread > 99 ? '99+' : bell.unread }}</span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-0 notif-menu shadow">
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                                <span class="fw-semibold small">Notificaciones</span>
                                <form v-if="bell.unread > 0" method="POST" action="/notifications/read-all" class="m-0">
                                    <input type="hidden" name="_token" :value="page.props.csrf">
                                    <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none">Marcar todas como leídas</button>
                                </form>
                            </div>
                            <div class="notif-list">
                                <form v-for="n in bell.latest" :key="n.id" method="POST" :action="`/notifications/${n.id}/read`" class="m-0">
                                    <input type="hidden" name="_token" :value="page.props.csrf">
                                    <button type="submit" class="dropdown-item d-flex gap-2 py-2 text-wrap" :class="{ 'notif-unread': !n.read }">
                                        <i class="bi text-body-secondary mt-1" :class="`bi-${n.icon}`"></i>
                                        <span class="min-w-0">
                                            <span class="d-block small fw-semibold">{{ n.title }}</span>
                                            <span class="d-block small text-body-secondary">{{ n.body }}</span>
                                            <span class="d-block text-body-tertiary" style="font-size: .72rem;">{{ n.ago }}</span>
                                        </span>
                                    </button>
                                </form>
                                <div v-if="!bell.latest.length" class="px-3 py-4 text-center small text-body-secondary">
                                    <i class="bi bi-bell-slash d-block fs-4 mb-1 opacity-50"></i>No tienes notificaciones.
                                </div>
                            </div>
                            <a href="/notifications" class="d-block text-center small py-2 border-top text-decoration-none">Ver todas</a>
                        </div>
                    </div>

                    <button type="button" class="btn btn-light border btn-sm" aria-label="Cambiar tema claro u oscuro" title="Tema claro / oscuro" @click="toggleTheme">
                        <i class="bi" :class="theme === 'dark' ? 'bi-sun' : 'bi-moon-stars'"></i>
                    </button>
                </div>
            </header>

            <main id="main-content" class="panel-content">
                <div v-if="page.props.globalView" class="alert alert-primary d-flex align-items-start gap-2 global-view-banner" role="status">
                    <i class="bi bi-globe2"></i>
                    <div>Vista global: mostrando registros de todas las empresas. Para crear o editar, elige una empresa en el selector superior.</div>
                </div>

                <template v-for="(text, key) in page.props.flash" :key="key + text">
                    <div v-if="!dismissed.has(key + text)" class="alert alert-dismissible" :class="`alert-${FLASH[key][0]}`" role="alert">
                        <i class="bi" :class="FLASH[key][1]"></i>
                        <div>{{ text }}</div>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="dismissed.add(key + text)"></button>
                    </div>
                </template>

                <div v-if="errors.length" class="alert alert-danger" role="alert">
                    <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i> Revisa los datos del formulario</div>
                    <ul class="mb-0 small">
                        <li v-for="error in errors" :key="error">{{ error }}</li>
                    </ul>
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
