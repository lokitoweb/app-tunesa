<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { useSkeleton } from '@/Modules/Core/Composables/useSkeleton';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({
    people: {
        type: Array,
        default: () => [],
    },
    month: {
        type: Number,
        required: true,
    },
    months: {
        type: Array,
        default: () => [],
    },
    todayPeople: {
        type: Array,
        default: () => [],
    },
    upcoming: {
        type: Array,
        default: () => [],
    },
    missing: {
        type: Number,
        default: 0,
    },
    branches: {
        type: Array,
        default: () => [],
    },
    settings: {
        type: Object,
        default: () => ({}),
    },
    scopes: {
        type: Object,
        default: () => ({}),
    },
    sentThisYear: {
        type: Number,
        default: 0,
    },
    filters: {
        type: Object,
        default: () => ({ branch: null, search: '' }),
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
    needsTenant: {
        type: Boolean,
        default: false,
    },
});

// Estado reactivo dinámico sin mutación de URL en el navegador (Puro AJAX)
const currentMonth = ref(props.month);
const peopleList = ref([...(props.people || [])]);
const monthsList = ref([...(props.months || [])]);
const loadingMonth = ref(false);
const showMonthSkeleton = useSkeleton(loadingMonth);

// Filtros 100% internos reactivos (sin mutar URL)
const searchQuery = ref(props.filters.search ?? '');
const selectedBranch = ref(props.filters.branch ? String(props.filters.branch) : '');

// Paginación interna reactiva
const currentPage = ref(1);
const perPage = ref(10);

const settingsForm = useForm({ ...props.settings });

// Limpieza de parámetros en URL si el usuario llegó con ?month=...
onMounted(() => {
    if (window.location.search) {
        window.history.replaceState({}, '', window.location.pathname);
    }
});

// Navegación por mes 100% AJAX sin modificar URL del navegador
async function selectMonth(mValue) {
    if (loadingMonth.value || currentMonth.value === mValue) return;
    currentMonth.value = mValue;
    loadingMonth.value = true;
    currentPage.value = 1;
    try {
        const res = await axios.get('/birthdays', {
            params: { month: mValue },
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (res.data) {
            peopleList.value = res.data.people || [];
            if (res.data.months) {
                monthsList.value = res.data.months;
            }
        }
    } catch (err) {
        console.error('Error al cambiar de mes vía AJAX:', err);
    } finally {
        loadingMonth.value = false;
    }
}

// Filtrado 100% interno en memoria
const filteredPeople = computed(() => {
    return (peopleList.value || []).filter((p) => {
        // Filtro por Sucursal
        if (selectedBranch.value) {
            const branchObj = props.branches.find((b) => String(b.id) === selectedBranch.value);
            if (branchObj && p.branch && p.branch !== branchObj.name) {
                return false;
            }
        }

        // Filtro por Búsqueda Predictiva
        if (searchQuery.value.trim()) {
            const q = searchQuery.value.toLowerCase().trim();
            const matchesName = p.name?.toLowerCase().includes(q);
            const matchesPosition = p.position?.toLowerCase().includes(q);
            const matchesDepartment = p.department?.toLowerCase().includes(q);
            const matchesBranch = p.branch?.toLowerCase().includes(q);
            if (!matchesName && !matchesPosition && !matchesDepartment && !matchesBranch) {
                return false;
            }
        }

        return true;
    });
});

// Paginación interna
const totalPages = computed(() => Math.max(1, Math.ceil(filteredPeople.value.length / perPage.value)));

const paginatedPeople = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;
    return filteredPeople.value.slice(start, start + perPage.value);
});

function handleSearchInput() {
    currentPage.value = 1;
}

function handleBranchChange() {
    currentPage.value = 1;
}

function resetFilters() {
    searchQuery.value = '';
    selectedBranch.value = '';
    currentPage.value = 1;
}

function goToPage(page) {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
    }
}

function saveSettings() {
    settingsForm.put('/birthdays/settings', { preserveScroll: true });
}

const hourLabel = (h) => `${((h + 11) % 12) + 1}:00 ${h < 12 ? 'a. m.' : 'p. m.'}`;
</script>

<template>
    <AppLayout title="Calendario de Cumpleaños">
        <div class="birthdays-page-wrapper">
            <!-- =========================================================================
                 1. CABECERA EJECUTIVA DEL MÓDULO
                 ========================================================================= -->
            <div class="birthdays-header-card p-4 mb-4 shadow-sm">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="birthdays-header-icon shadow-sm">
                            <i class="bi bi-cake2-fill"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <h1 class="h4 fw-bold mb-0 text-body">Calendario de Cumpleaños</h1>
                                <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 small">
                                    <i class="bi bi-balloon-heart-fill me-1"></i>Celebraciones
                                </span>
                            </div>
                            <p class="text-body-secondary small mb-0">
                                Monitorea los aniversarios de nacimiento del personal y configura las notificaciones push automáticas para la app móvil.
                            </p>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 w-100 w-md-auto justify-content-md-end">
                        <a :href="`/birthdays/export?month=${currentMonth}`" class="btn btn-outline-secondary px-3 py-2 fw-semibold d-inline-flex align-items-center">
                            <i class="bi bi-download me-2 text-primary"></i>Exportar mes (CSV)
                        </a>
                    </div>
                </div>
            </div>

            <!-- Alertas Globales -->
            <div v-if="needsTenant" class="alert alert-info d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-info-circle-fill fs-5"></i>
                <span>Estás en la vista global de todas las empresas. Elige una empresa en el selector superior para ver sus cumpleaños.</span>
            </div>

            <div v-if="missing > 0" class="alert alert-warning d-flex align-items-center justify-content-between gap-3 mb-4 p-3 shadow-sm">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill fs-5 text-warning"></i>
                    <span>
                        <strong>{{ missing }} colaborador(es) activos</strong> no tienen fecha de nacimiento configurada.
                    </span>
                </div>
                <Link href="/employees" class="btn btn-sm btn-warning text-dark fw-bold text-nowrap">
                    <i class="bi bi-person-gear me-1"></i>Completar en Colaboradores
                </Link>
            </div>

            <!-- =========================================================================
                 2. TARJETAS DE CELEBRACIONES (HOY Y PRÓXIMOS 7 DÍAS)
                 ========================================================================= -->
            <div class="row g-4 mb-4">
                <!-- Celebraciones de Hoy -->
                <div class="col-lg-6">
                    <div class="birthdays-section-card h-100 p-4 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <h2 class="h6 fw-bold mb-0 text-body d-flex align-items-center">
                                <i class="bi bi-balloon-heart-fill me-2 text-danger fs-5"></i>
                                ¡Cumpleañeros de Hoy!
                            </h2>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                {{ todayPeople.length }} celebrante(s)
                            </span>
                        </div>

                        <div v-if="todayPeople.length > 0" class="d-flex flex-column gap-3">
                            <div
                                v-for="p in todayPeople"
                                :key="p.id"
                                class="p-3 rounded-3 d-flex align-items-center gap-3"
                                style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2);"
                            >
                                <img
                                    v-if="p.avatar_url"
                                    :src="p.avatar_url"
                                    :alt="p.name"
                                    class="rounded-circle shadow-sm"
                                    width="48"
                                    height="48"
                                    style="object-fit: cover;"
                                >
                                <span
                                    v-else
                                    class="rounded-circle d-inline-flex align-items-center justify-content-center bg-danger text-white fw-bold shadow-sm"
                                    style="width: 48px; height: 48px; font-size: 1.1rem;"
                                >
                                    {{ p.initials }}
                                </span>

                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-bold text-body fs-6 mb-1 text-truncate">{{ p.name }}</div>
                                    <div class="small text-body-secondary d-flex align-items-center gap-2 flex-wrap">
                                        <span><i class="bi bi-briefcase me-1"></i>{{ p.position ?? 'Colaborador' }}</span>
                                        <span v-if="p.branch">•</span>
                                        <span v-if="p.branch"><i class="bi bi-building me-1"></i>{{ p.branch }}</span>
                                    </div>
                                </div>

                                <span class="badge bg-danger text-white px-3 py-2 fw-bold text-uppercase" style="letter-spacing: 0.05em;">
                                    🎉 ¡Hoy!
                                </span>
                            </div>
                        </div>

                        <div v-else class="text-center py-4">
                            <i class="bi bi-calendar-check text-body-secondary fs-1 d-block mb-2 opacity-50"></i>
                            <span class="text-body-secondary small">No hay colaboradores que cumplan años el día de hoy.</span>
                        </div>
                    </div>
                </div>

                <!-- Próximos 7 días -->
                <div class="col-lg-6">
                    <div class="birthdays-section-card h-100 p-4 shadow-sm">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <h2 class="h6 fw-bold mb-0 text-body d-flex align-items-center">
                                <i class="bi bi-calendar-event-fill me-2 text-primary fs-5"></i>
                                Próximos 7 días
                            </h2>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                {{ upcoming.length }} cercano(s)
                            </span>
                        </div>

                        <div v-if="upcoming.length > 0" class="d-flex flex-column gap-2" style="max-height: 220px; overflow-y: auto;">
                            <div
                                v-for="p in upcoming"
                                :key="p.id"
                                class="p-2 px-3 rounded-2 d-flex align-items-center justify-content-between border"
                            >
                                <div class="d-flex align-items-center gap-2 min-w-0">
                                    <span class="badge rounded-circle bg-primary-subtle text-primary p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">
                                        <i class="bi bi-gift-fill"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="fw-semibold text-body small text-truncate">{{ p.name }}</div>
                                        <div class="text-body-secondary" style="font-size: 0.72rem;">{{ p.position ?? p.branch ?? 'Colaborador' }}</div>
                                    </div>
                                </div>
                                <div class="text-end flex-shrink-0 ms-2">
                                    <div class="fw-bold text-primary small">{{ p.date_label }}</div>
                                    <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.68rem;">
                                        en {{ p.days_until }} día(s)
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div v-else class="text-center py-4">
                            <i class="bi bi-calendar-x text-body-secondary fs-1 d-block mb-2 opacity-50"></i>
                            <span class="text-body-secondary small">Sin cumpleaños registrados para esta semana.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 3. SELECTOR DE MESES (GRILLA EJECUTIVA DE 12 COLUMNAS SIN DESBORDES)
                 ========================================================================= -->
            <div class="birthdays-months-card p-2 mb-4 bg-surface rounded-3 border shadow-sm position-relative">
                <div class="birthdays-months-grid" :class="{ 'is-loading': loadingMonth }">
                    <button
                        v-for="m in monthsList"
                        :key="m.value"
                        type="button"
                        class="birthdays-month-tab"
                        :class="{ 'is-active': m.value === currentMonth }"
                        :disabled="loadingMonth"
                        @click="selectMonth(m.value)"
                    >
                        <span class="month-name">{{ m.label }}</span>
                        <span class="month-badge">{{ m.count }}</span>
                    </button>
                </div>
            </div>

            <!-- =========================================================================
                 4. TABLA CON FILTROS 100% INTERNOS Y PAGINACIÓN INTERNA
                 ========================================================================= -->
            <div class="birthdays-table-card shadow-sm mb-4">
                <!-- Barra de Filtros Internos -->
                <div class="p-3 border-bottom d-flex flex-column flex-md-row align-items-stretch align-items-md-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-2">
                        <!-- Filtro de Sucursal -->
                        <div class="modern-input-wrapper" style="min-width: 200px;">
                            <span class="modern-input-icon"><i class="bi bi-building"></i></span>
                            <select
                                v-model="selectedBranch"
                                class="modern-input-control"
                                aria-label="Filtrar por sucursal"
                                @change="handleBranchChange"
                            >
                                <option value="">Todas las sucursales</option>
                                <option v-for="b in branches" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
                            </select>
                        </div>

                        <!-- Botón Limpiar si está activo -->
                        <button
                            v-if="selectedBranch || searchQuery"
                            type="button"
                            class="btn btn-outline-secondary px-3 py-1 fw-medium d-inline-flex align-items-center"
                            style="border-radius: 8px; height: 38px;"
                            title="Restablecer filtros"
                            @click="resetFilters"
                        >
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Limpiar
                        </button>
                    </div>

                    <!-- Buscador Predictivo en Tiempo Real -->
                    <div class="modern-input-wrapper" style="min-width: 260px;">
                        <span class="modern-input-icon"><i class="bi bi-search"></i></span>
                        <input
                            v-model="searchQuery"
                            type="text"
                            class="modern-input-control"
                            placeholder="Buscar persona, cargo o área..."
                            aria-label="Buscar colaboradores"
                            @input="handleSearchInput"
                        >
                        <button
                            v-if="searchQuery"
                            type="button"
                            class="btn btn-link text-body-secondary p-0 me-2"
                            style="text-decoration: none;"
                            @click="searchQuery = ''; handleSearchInput();"
                        >
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>
                </div>

                <!-- Tabla de Resultados -->
                <div class="table-responsive" :class="{ 'sk-loading': showMonthSkeleton }" :aria-busy="loadingMonth">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-surface-elevated text-body-secondary small text-uppercase">
                            <tr>
                                <th class="ps-3 py-3" style="width: 160px;">Fecha</th>
                                <th class="py-3">Colaborador</th>
                                <th class="py-3">Cargo</th>
                                <th class="py-3">Área / Dpto</th>
                                <th class="pe-3 py-3">Sucursal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="p in paginatedPeople"
                                :key="p.id"
                                :class="{ 'table-danger-subtle': p.is_today }"
                            >
                                <td class="ps-3 text-nowrap fw-bold">
                                    <div class="d-inline-flex align-items-center gap-2">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                            <i class="bi bi-calendar-event me-1"></i>{{ p.date_label }}
                                        </span>
                                        <span v-if="p.is_today" class="badge bg-danger text-white shadow-sm">
                                            ¡Hoy!
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <img
                                            v-if="p.avatar_url"
                                            :src="p.avatar_url"
                                            :alt="p.name"
                                            class="rounded-circle"
                                            width="32"
                                            height="32"
                                            style="object-fit: cover;"
                                        >
                                        <span
                                            v-else
                                            class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary text-white small fw-bold"
                                            style="width: 32px; height: 32px; font-size: 0.75rem;"
                                        >
                                            {{ p.initials }}
                                        </span>
                                        <span class="fw-semibold text-body">{{ p.name }}</span>
                                    </div>
                                </td>
                                <td class="small text-body">{{ p.position ?? '—' }}</td>
                                <td class="small text-body-secondary">{{ p.department ?? '—' }}</td>
                                <td class="pe-3 small text-body-secondary">
                                    <span v-if="p.branch" class="d-inline-flex align-items-center">
                                        <i class="bi bi-geo-alt me-1 text-primary"></i>{{ p.branch }}
                                    </span>
                                    <span v-else>—</span>
                                </td>
                            </tr>

                            <tr v-if="filteredPeople.length === 0">
                                <td colspan="5" class="p-5 text-center">
                                    <i class="bi bi-balloon fs-1 text-body-secondary d-block mb-2 opacity-50"></i>
                                    <div class="fw-bold text-body">Sin cumpleaños encontrados</div>
                                    <span class="text-body-secondary small">
                                        No hay registros de cumpleaños para este mes con los filtros actuales.
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación Interna (Sin modificar URL) -->
                <div v-if="filteredPeople.length > 0" class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 p-3 border-top">
                    <div class="text-body-secondary small">
                        Mostrando
                        <span class="fw-bold text-body">{{ ((currentPage - 1) * perPage) + 1 }}</span>
                        -
                        <span class="fw-bold text-body">{{ Math.min(currentPage * perPage, filteredPeople.length) }}</span>
                        de
                        <span class="fw-bold text-body">{{ filteredPeople.length }}</span> colaboradores
                    </div>

                    <div v-if="totalPages > 1" class="d-flex align-items-center gap-1">
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary px-2 py-1"
                            style="border-radius: 6px;"
                            :disabled="currentPage === 1"
                            @click="goToPage(currentPage - 1)"
                        >
                            <i class="bi bi-chevron-left me-1"></i>Anterior
                        </button>

                        <button
                            v-for="page in totalPages"
                            :key="page"
                            type="button"
                            class="btn btn-sm"
                            :class="currentPage === page ? 'btn-primary fw-bold' : 'btn-outline-secondary'"
                            style="width: 32px; height: 32px; border-radius: 6px; padding: 0;"
                            @click="goToPage(page)"
                        >
                            {{ page }}
                        </button>

                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary px-2 py-1"
                            style="border-radius: 6px;"
                            :disabled="currentPage === totalPages"
                            @click="goToPage(currentPage + 1)"
                        >
                            Siguiente<i class="bi bi-chevron-right ms-1"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 5. CONFIGURACIÓN DE AVISOS AUTOMÁTICOS
                 ========================================================================= -->
            <form class="birthdays-section-card shadow-sm p-4" @submit.prevent="saveSettings">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <h2 class="h6 fw-bold mb-0 text-body d-flex align-items-center">
                        <i class="bi bi-bell-fill me-2 text-primary fs-5"></i>
                        Aviso Automático Push en la App Móvil
                    </h2>
                    <span class="badge bg-secondary-subtle text-secondary">
                        Saludos enviados este año: {{ sentThisYear }}
                    </span>
                </div>

                <div class="row g-3 align-items-center">
                    <div class="col-md-5">
                        <label for="notify_colleagues" class="modern-field-label">¿A quién avisar del cumpleaños?</label>
                        <div class="modern-input-wrapper">
                            <span class="modern-input-icon"><i class="bi bi-people-fill"></i></span>
                            <select
                                id="notify_colleagues"
                                v-model="settingsForm.notify_colleagues"
                                class="modern-input-control"
                                :disabled="!canEdit"
                            >
                                <option v-for="(label, key) in scopes" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label for="hour" class="modern-field-label">Hora del envío</label>
                        <div class="modern-input-wrapper">
                            <span class="modern-input-icon"><i class="bi bi-clock-fill"></i></span>
                            <select
                                id="hour"
                                v-model.number="settingsForm.hour"
                                class="modern-input-control"
                                :disabled="!canEdit"
                            >
                                <option v-for="h in 24" :key="h - 1" :value="h - 1">{{ hourLabel(h - 1) }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-4 pt-md-3">
                        <div class="form-check form-switch">
                            <input
                                id="greet_celebrant"
                                v-model="settingsForm.greet_celebrant"
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                :disabled="!canEdit"
                            >
                            <label class="form-check-label fw-semibold text-body ms-2" for="greet_celebrant">
                                Saludo especial al celebrante
                                <div class="text-body-secondary small fw-normal">Envía mensaje de felicitación de parte de la empresa.</div>
                            </label>
                        </div>
                    </div>
                </div>

                <div v-if="canEdit" class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" :disabled="settingsForm.processing">
                        <i class="bi bi-save me-1"></i>Guardar ajustes de avisos
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.birthdays-months-card {
    position: relative;
    background: var(--bg-surface, #ffffff);
}

.birthdays-months-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 6px;
    width: 100%;
    transition: opacity 0.15s ease;
}

.birthdays-months-grid.is-loading {
    opacity: 0.6;
}

@media (max-width: 1200px) {
    .birthdays-months-grid {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }
}

@media (max-width: 680px) {
    .birthdays-months-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

.birthdays-month-tab {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 38px;
    padding: 0 6px;
    border-radius: 8px;
    border: 1px solid var(--border-color, #e2e8f0);
    background: transparent;
    color: var(--text-secondary, #64748b);
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
    min-width: 0;
}

.birthdays-month-tab .month-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.birthdays-month-tab:hover:not(.is-active) {
    background: var(--bg-surface-elevated, #f1f5f9);
    border-color: var(--accent-cyan, #0284c7);
    color: var(--text-primary, #0f172a);
}

.birthdays-month-tab.is-active {
    background: var(--accent-cyan, #0284c7) !important;
    border-color: var(--accent-cyan, #0284c7) !important;
    color: #ffffff !important;
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.35) !important;
}

.birthdays-month-tab .month-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 10px;
    background: var(--bg-surface-elevated, #f1f5f9);
    color: var(--text-muted, #94a3b8);
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.birthdays-month-tab.is-active .month-badge {
    background: rgba(255, 255, 255, 0.25) !important;
    color: #ffffff !important;
}

[data-bs-theme="dark"] .birthdays-months-card {
    background: #141822 !important;
    border-color: #242c3d !important;
}

[data-bs-theme="dark"] .birthdays-month-tab {
    border-color: #2a3447;
    color: #94a3b8;
}

[data-bs-theme="dark"] .birthdays-month-tab:hover:not(.is-active) {
    background: #1e2638;
    color: #ffffff;
}

[data-bs-theme="dark"] .birthdays-month-tab .month-badge {
    background: #242c3d;
    color: #cbd5e1;
}
</style>
