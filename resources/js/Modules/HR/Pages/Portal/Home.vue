<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Modules/HR/Layouts/PortalLayout.vue';
import PunchCard from '@/Modules/Attendance/Components/PunchCard.vue';

const props = defineProps({
    today: { type: String, required: true },
    attendance: { type: Object, default: null },
    schedule: { type: Object, default: null },
    next: { type: Object, default: null },
    contingency: { type: Object, default: null },
    webPunch: { type: Boolean, default: false },
});

const page = usePage();
const person = computed(() => page.props.portal?.person || {});
const sections = computed(() => page.props.portal || {});
const hour = new Date().getHours();
// Igual que el Dashboard: de madrugada todavía es "buenas noches"
const greeting = hour >= 6 && hour < 12 ? 'Buenos días' : hour >= 12 && hour < 19 ? 'Buenas tardes' : 'Buenas noches';

const shortcuts = computed(() => [
    { href: '/mi-espacio/asistencia', icon: 'bi-calendar2-check', title: 'Mi asistencia', text: 'Tus jornadas del mes y justificaciones', show: sections.value.attendance },
    { href: '/mi-espacio/boletas', icon: 'bi-file-earmark-text', title: 'Mis boletas', text: 'Descarga tus boletas de pago', show: sections.value.payslips },
    { href: '/mi-espacio/perfil', icon: 'bi-person-vcard', title: 'Mi perfil', text: 'Tus datos, legajo y contraseña', show: true },
].filter((s) => s.show));
</script>

<template>
    <PortalLayout title="Inicio">
        <h1 class="visually-hidden">{{ greeting }}, {{ person.first_name }}</h1>
        <PunchCard v-if="sections.attendance" class="mb-3" :attendance="attendance" :schedule="schedule" :next="next"
            :contingency="contingency" :web-punch="webPunch" post-url="/mi-espacio/marcar" history-url="/mi-espacio/asistencia"
            :title="`${greeting}, ${person.first_name}`" :subtitle="[person.position, person.company].filter(Boolean).join(' · ')" />
        <section v-else class="portal-hero">
            <p class="portal-hero-title mb-1">{{ greeting }}, {{ person.first_name }}</p>
            <p class="portal-hero-sub mb-0">{{ [person.position, person.company].filter(Boolean).join(' · ') }}</p>
        </section>

        <div class="row g-3">
            <div v-for="s in shortcuts" :key="s.href" class="col-12 col-md-4">
                <Link :href="s.href" class="card h-100 text-decoration-none portal-shortcut">
                    <div class="card-body d-flex align-items-center gap-3 p-3">
                        <span class="portal-shortcut-icon"><i class="bi" :class="s.icon"></i></span>
                        <span class="flex-grow-1">
                            <span class="d-block fw-semibold text-body">{{ s.title }}</span>
                            <span class="d-block small text-body-secondary">{{ s.text }}</span>
                        </span>
                        <i class="bi bi-chevron-right text-body-tertiary"></i>
                    </div>
                </Link>
            </div>
        </div>
    </PortalLayout>
</template>

<style>
.portal-hero { margin-bottom: 1rem; padding: 1.5rem; border-radius: var(--portal-radius); color: #fff;
    background: linear-gradient(135deg, var(--brand) 0%, rgba(var(--brand-rgb), .78) 100%); box-shadow: 0 8px 24px rgba(var(--brand-rgb), .25); }
.portal-hero-title { font-size: 1.6rem; font-weight: 700; letter-spacing: -.01em; }
.portal-hero-sub { opacity: .9; }
.portal-shortcut { transition: border-color var(--dur-2) var(--ease-out), box-shadow var(--dur-2) var(--ease-out); }
.portal-shortcut:hover { border-color: rgba(var(--brand-rgb), .35) !important; box-shadow: var(--shadow-sm) !important; }
.portal-shortcut:active { transform: scale(.99); }
.portal-shortcut:hover .bi-chevron-right { color: var(--brand) !important; translate: 2px 0; }
.portal-shortcut .bi-chevron-right { transition: translate var(--dur-2) var(--ease-out), color var(--dur-2) var(--ease-out); }
.portal-shortcut-icon { width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.25rem;
    background: rgba(var(--brand-rgb), .1); color: var(--brand); flex-shrink: 0; }
@media (max-width: 767.98px) {
    .portal-hero { padding: 1.25rem; }
    .portal-hero-title { font-size: 1.35rem; }
}
</style>
