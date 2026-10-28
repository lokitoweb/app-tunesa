<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import FlashToasts from '@/Modules/Core/Components/FlashToasts.vue';

// Mi espacio: portal web del colaborador (sin el menú ni los datos del panel)
defineProps({ title: { type: String, default: 'Mi espacio' } });

const page = usePage();
const auth = computed(() => page.props.auth || {});
const brand = computed(() => page.props.brand || {});
const sections = computed(() => page.props.portal || {});
const links = computed(() => [
    { href: '/mi-espacio', label: 'Inicio', icon: 'bi-house', show: true },
    { href: '/mi-espacio/asistencia', label: 'Mi asistencia', icon: 'bi-calendar-check', show: sections.value.attendance },
    { href: '/mi-espacio/boletas', label: 'Mis boletas', icon: 'bi-file-earmark-text', show: sections.value.payslips },
    { href: '/mi-espacio/perfil', label: 'Mi perfil', icon: 'bi-person', show: true },
].filter((l) => l.show));
const current = (href) => (href === '/mi-espacio' ? page.url === '/mi-espacio' || page.url.startsWith('/mi-espacio?') : page.url.startsWith(href));
</script>

<template>
    <Head :title="title" />
    <div class="min-vh-100 bg-body-tertiary">
        <nav class="navbar navbar-expand bg-body border-bottom shadow-sm">
            <div class="container-lg px-3 gap-2 flex-wrap">
                <Link href="/mi-espacio" class="navbar-brand d-flex align-items-center gap-2 me-auto">
                    <img v-if="brand.logo_url" :src="brand.logo_url" :alt="brand.trade_name" style="max-height: 32px; max-width: 140px;" />
                    <span v-else class="fw-bold">{{ brand.trade_name }}</span>
                </Link>
                <span class="small text-body-secondary d-none d-md-inline">{{ auth.name }}</span>
                <form method="POST" action="/logout" class="m-0">
                    <input type="hidden" name="_token" :value="page.props.csrf" />
                    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-right me-1"></i>Salir</button>
                </form>
            </div>
        </nav>
        <div class="container-lg px-3">
            <ul class="nav nav-pills gap-1 py-2 flex-nowrap overflow-auto" aria-label="Mi espacio">
                <li v-for="l in links" :key="l.href" class="nav-item">
                    <Link :href="l.href" class="nav-link text-nowrap" :class="{ active: current(l.href) }" :aria-current="current(l.href) ? 'page' : undefined">
                        <i class="bi me-1" :class="l.icon"></i>{{ l.label }}
                    </Link>
                </li>
            </ul>
            <FlashToasts />
            <main class="pb-5">
                <slot />
            </main>
        </div>
    </div>
</template>
