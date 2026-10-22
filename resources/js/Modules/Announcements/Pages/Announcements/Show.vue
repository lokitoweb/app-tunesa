<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({
    announcement: {
        type: Object,
        required: true,
    },
    readers: {
        type: Array,
        default: () => [],
    },
    categories: {
        type: Object,
        default: () => ({}),
    },
});

const searchReader = ref('');

const rate = computed(() => (props.announcement.recipients ? Math.round((props.announcement.reads / props.announcement.recipients) * 100) : 0));

const filteredReaders = computed(() => {
    if (!searchReader.value.trim()) return props.readers;
    const q = searchReader.value.toLowerCase().trim();
    return props.readers.filter((r) => r.name?.toLowerCase().includes(q) || r.position?.toLowerCase().includes(q));
});

function getInitials(name) {
    if (!name) return '??';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return name.slice(0, 2).toUpperCase();
}
</script>

<template>
    <AppLayout :title="announcement.title">
        <div class="announcements-page-wrapper">
            <!-- =========================================================================
                 1. CABECERA EJECUTIVA DEL COMUNICADO
                 ========================================================================= -->
            <div class="announcements-header-card shadow-sm p-3 p-md-4 mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <Link href="/announcements" class="btn-executive-back">
                        <i class="bi bi-arrow-left" style="margin-right: 8px !important;"></i>
                        <span>Volver a comunicados</span>
                    </Link>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center bg-success-subtle text-success border border-success-subtle">
                            <span class="rounded-circle me-1.5 bg-success" style="width: 6px; height: 6px; display: inline-block;"></span>
                            Emitido en App Móvil
                        </span>
                        <span class="badge bg-light text-dark border px-2.5 py-1.5 fw-medium">
                            {{ categories[announcement.category]?.[0] || announcement.category }}
                        </span>
                        <span v-if="announcement.is_important" class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-1.5 fw-semibold">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>Importante
                        </span>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center" style="gap: 16px !important;">
                        <div class="announcements-header-icon shadow-sm">
                            <i class="bi" :class="`bi-${categories[announcement.category]?.[1] ?? 'megaphone-fill'}`"></i>
                        </div>
                        <div>
                            <h1 class="h4 fw-bold mb-1 text-body">{{ announcement.title }}</h1>
                            <div class="d-flex align-items-center flex-wrap text-secondary small" style="gap: 12px !important;">
                                <span>
                                    <i class="bi bi-send-check text-success" style="margin-right: 6px !important;"></i>
                                    Enviado el {{ announcement.sent_at ?? '—' }}
                                </span>
                                <span>
                                    <i class="bi bi-people text-primary" style="margin-right: 6px !important;"></i>
                                    {{ announcement.audience && announcement.audience.length ? announcement.audience.join(', ') : 'Todo el personal' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 2. CONTENIDO DEL MENSAJE Y AUDITORÍA DE LECTURAS
                 ========================================================================= -->
            <div class="row g-4">
                <!-- Columna Izquierda: Mensaje y Metadatos -->
                <div class="col-lg-7">
                    <div class="announcements-section-card shadow-sm p-4 mb-4">
                        <div class="d-flex align-items-center mb-3" style="gap: 10px !important;">
                            <div class="announcements-kpi-pill bg-primary-subtle text-primary" style="width: 32px; height: 32px; font-size: 0.95rem;">
                                <i class="bi bi-chat-left-text-fill"></i>
                            </div>
                            <h2 class="h6 fw-bold mb-0 text-body">Cuerpo del Comunicado</h2>
                        </div>

                        <div class="p-3 rounded bg-light-subtle border mb-3" style="font-size: 0.925rem; line-height: 1.7; white-space: pre-line;">
                            {{ announcement.body }}
                        </div>

                        <!-- Metadatos de Reunión / Ubicación / Enlace -->
                        <div v-if="announcement.event_at || announcement.location || announcement.url" class="d-flex flex-column gap-2 pt-3 border-top">
                            <div v-if="announcement.event_at" class="d-flex align-items-center text-body small">
                                <i class="bi bi-calendar-event-fill text-primary" style="width: 22px; font-size: 1rem; margin-right: 8px !important;"></i>
                                <span><strong>Fecha y hora del evento:</strong> {{ announcement.event_at }}</span>
                            </div>
                            <div v-if="announcement.location" class="d-flex align-items-center text-body small">
                                <i class="bi bi-geo-alt-fill text-danger" style="width: 22px; font-size: 1rem; margin-right: 8px !important;"></i>
                                <span><strong>Lugar / Sala:</strong> {{ announcement.location }}</span>
                            </div>
                            <div v-if="announcement.url" class="d-flex align-items-center text-body small">
                                <i class="bi bi-link-45deg text-info" style="width: 22px; font-size: 1.25rem; margin-right: 8px !important;"></i>
                                <span><strong>Enlace web:</strong> 
                                    <a :href="announcement.url" target="_blank" rel="noopener" class="text-decoration-none fw-semibold">
                                        {{ announcement.url }}
                                    </a>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha: Auditoría de Lecturas -->
                <div class="col-lg-5">
                    <div class="announcements-section-card shadow-sm p-4 mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="d-flex align-items-center" style="gap: 10px !important;">
                                <div class="announcements-kpi-pill bg-emerald-subtle text-emerald" style="width: 32px; height: 32px; font-size: 0.95rem;">
                                    <i class="bi bi-check2-all"></i>
                                </div>
                                <div>
                                    <h2 class="h6 fw-bold mb-0 text-body">Auditoría de Lectura</h2>
                                    <span class="small text-secondary">{{ announcement.reads }} de {{ announcement.recipients }} colaboradores</span>
                                </div>
                            </div>
                            <span class="badge rounded-pill bg-success-subtle text-success fs-6 fw-bold px-2.5 py-1">
                                {{ rate }}%
                            </span>
                        </div>

                        <!-- Barra de Progreso -->
                        <div class="progress shadow-none mb-3" style="height: 7px; border-radius: 999px; background-color: rgba(148, 163, 184, 0.2);">
                            <div class="progress-bar bg-success" :style="{ width: `${rate}%`, borderRadius: '999px' }"></div>
                        </div>

                        <!-- Buscador de Lector -->
                        <div v-if="readers.length > 5" class="modern-input-wrapper mb-3">
                            <i class="bi bi-search modern-input-icon"></i>
                            <input 
                                v-model="searchReader" 
                                type="search" 
                                class="modern-input-control" 
                                placeholder="Filtrar por nombre..."
                                aria-label="Buscar lector"
                            >
                        </div>

                        <!-- Lista de Lectores -->
                        <div class="overflow-auto pe-1" style="max-height: 420px;">
                            <div 
                                v-for="(r, i) in filteredReaders" 
                                :key="i" 
                                class="d-flex align-items-center justify-content-between py-2 border-bottom last-border-0"
                            >
                                <div class="d-flex align-items-center" style="gap: 10px !important;">
                                    <div class="training-avatar bg-primary-subtle text-primary" style="width: 32px; height: 32px; font-size: 0.75rem;">
                                        {{ getInitials(r.name) }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="fw-semibold text-body small text-truncate">{{ r.name }}</div>
                                        <div class="text-muted" style="font-size: 0.725rem;">{{ r.position || 'Colaborador' }}</div>
                                    </div>
                                </div>
                                <div class="text-secondary text-end small" style="font-size: 0.75rem;">
                                    <i class="bi bi-clock me-1"></i>{{ r.read_at }}
                                </div>
                            </div>

                            <div v-if="!readers.length" class="text-center py-4 text-muted small">
                                <i class="bi bi-eye-slash fs-2 d-block mb-1 opacity-50"></i>
                                Aún no se han registrado lecturas en la app móvil.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
