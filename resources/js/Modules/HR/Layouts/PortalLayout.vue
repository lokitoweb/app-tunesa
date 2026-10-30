<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import FlashToasts from '@/Modules/Core/Components/FlashToasts.vue';

// Portal web del colaborador: su propio espacio, con los colores de su empresa (Marca y app)
const props = defineProps({ title: { type: String, default: '' } });

const page = usePage();
const auth = computed(() => page.props.auth || {});
const brand = computed(() => page.props.brand || {});
const person = computed(() => page.props.portal?.person || {});
const sections = computed(() => page.props.portal || {});
const links = computed(() => [
    { href: '/mi-espacio', label: 'Inicio', icon: 'bi-house-door', show: true },
    { href: '/mi-espacio/asistencia', label: 'Asistencia', icon: 'bi-calendar2-check', show: sections.value.attendance },
    { href: '/mi-espacio/boletas', label: 'Boletas', icon: 'bi-file-earmark-text', show: sections.value.payslips },
    { href: '/mi-espacio/perfil', label: 'Perfil', icon: 'bi-person', show: true },
].filter((l) => l.show));
const current = (href) => (href === '/mi-espacio' ? page.url === '/mi-espacio' || page.url.startsWith('/mi-espacio?') : page.url.startsWith(href));
// La pestaña del navegador lleva su nombre (y la sección), no un rótulo genérico
const tabTitle = computed(() => [props.title, person.value.full_name || auth.value.name].filter(Boolean).join(' · '));
</script>

<template>
    <Head :title="tabTitle" />
    <div class="portal">
        <header class="portal-top">
            <div class="container-lg px-3 d-flex align-items-center gap-3">
                <Link href="/mi-espacio" class="d-flex align-items-center me-auto" :aria-label="brand.trade_name">
                    <img v-if="brand.logo_url" :src="brand.logo_url" :alt="brand.trade_name" class="portal-logo" />
                    <span v-else class="fw-bold">{{ brand.trade_name }}</span>
                </Link>
                <div class="portal-user d-none d-sm-flex">
                    <span class="portal-avatar" aria-hidden="true">{{ auth.initials }}</span>
                    <span class="lh-sm">
                        <span class="d-block fw-semibold">{{ person.full_name || auth.name }}</span>
                        <span class="d-block small text-body-secondary">{{ person.position || person.company }}</span>
                    </span>
                </div>
                <form method="POST" action="/logout" class="m-0">
                    <input type="hidden" name="_token" :value="page.props.csrf" />
                    <button type="submit" class="btn portal-logout" aria-label="Cerrar sesión" title="Cerrar sesión"><i class="bi bi-box-arrow-right"></i><span class="d-none d-md-inline ms-2">Salir</span></button>
                </form>
            </div>
        </header>

        <nav class="portal-nav" aria-label="Secciones">
            <div class="container-lg px-3">
                <div class="portal-tabs">
                    <Link v-for="l in links" :key="l.href" :href="l.href" class="portal-tab" :class="{ active: current(l.href) }" :aria-current="current(l.href) ? 'page' : undefined">
                        <i class="bi" :class="l.icon"></i><span>{{ l.label }}</span>
                    </Link>
                </div>
            </div>
        </nav>

        <FlashToasts />
        <main class="container-lg px-3 pt-3 pb-5">
            <slot />
        </main>
    </div>
</template>

<style>
.portal { min-height: 100vh; background: var(--bg-base); --portal-radius: var(--radius-lg); }
.portal-top { background: var(--bg-surface); border-bottom: 1px solid var(--border-color); padding: .75rem 0; }
.portal-logo { max-height: 38px; max-width: 150px; object-fit: contain; }
.portal-user { align-items: center; gap: .65rem; }
.portal-avatar { width: 38px; height: 38px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: .85rem;
    color: var(--brand); background: rgba(var(--brand-rgb), .12); letter-spacing: .02em; flex-shrink: 0; }
.portal-logout { border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: .4rem .75rem; color: var(--text-primary); }
.portal-logout:hover { background: var(--bg-surface-elevated); }
.portal-nav { background: color-mix(in srgb, var(--bg-surface) 88%, transparent); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 20; }
.portal-tabs { display: flex; gap: .25rem; overflow-x: auto; scrollbar-width: none; }
.portal-tabs::-webkit-scrollbar { display: none; }
.portal-tab { display: inline-flex; align-items: center; gap: .5rem; padding: .85rem 1rem; color: var(--text-secondary); text-decoration: none; white-space: nowrap;
    font-weight: 600; font-size: .88rem; border-bottom: 2px solid transparent; transition: color var(--dur-1) var(--ease-out), border-color var(--dur-2) var(--ease-out); }
.portal-tab:hover { color: var(--text-primary); }
.portal-tab:focus-visible { outline: none; box-shadow: var(--ring); border-radius: var(--radius-sm); }
.portal-tab.active { color: var(--brand); border-bottom-color: var(--brand); }
.portal .card { border: 1px solid var(--border-color); border-radius: var(--portal-radius); box-shadow: var(--shadow-xs); background: var(--bg-surface); }
.portal .card .table { margin-bottom: 0; }
.portal .card > .table-responsive:first-child, .portal .card > ul:first-child { border-radius: var(--portal-radius); overflow: hidden; }
.portal-title { font-size: clamp(1.3rem, 1.1rem + .7vw, 1.6rem); font-weight: 700; letter-spacing: -.025em; margin: .5rem 0 1.25rem; }
@media (max-width: 575.98px) {
    .portal-tab { padding: .75rem .8rem; }
    .portal-tab span { font-size: .9rem; }
}
</style>
