<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import { useSkeleton } from '@/Modules/Core/Composables/useSkeleton';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { toast } from '@/Modules/Core/Composables/useToast';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';
import DateRangePicker from '@/Modules/Core/Components/DateRangePicker.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    holidays: {
        type: Array,
        default: () => [],
    },
    year: {
        type: Number,
        default: () => new Date().getFullYear(),
    },
    today: {
        type: String,
        default: () => new Date().toISOString().substring(0, 10),
    },
    loadPeruLabel: {
        type: String,
        default: '',
    },
});

const { ask } = useConfirm();

// Estado reactivo dinámico sin mutación de URL en el navegador (Puro AJAX)
const currentYear = ref(props.year);
const holidaysList = ref([...props.holidays]);
const loadingYear = ref(false);
const loadingSync = ref(false);
const showYearSkeleton = useSkeleton(loadingYear);

// Formulario reactivo para nuevo feriado (vía AJAX)
const formDate = ref(props.today);
const formName = ref('');
const formErrors = ref({});
const formLoading = ref(false);

// Sistema de Toast / Feedback Ejecutivo
const showToast = (message, type = 'success') => toast(message, type);

// Búsqueda instantánea en tiempo real sin recargar página
const searchQuery = ref('');

const filteredHolidays = computed(() => {
    const q = (searchQuery.value || '').trim().toLowerCase();
    if (!q) return holidaysList.value;

    return holidaysList.value.filter((h) => {
        return (
            (h.name && h.name.toLowerCase().includes(q)) ||
            (h.formatted_date && h.formatted_date.includes(q)) ||
            (h.day_name && h.day_name.toLowerCase().includes(q))
        );
    });
});

// Contadores de feriados nacionales vs corporativos
const nationalCount = computed(() => holidaysList.value.filter((h) => h.is_national).length);
const corporateCount = computed(() => holidaysList.value.filter((h) => !h.is_national).length);

// Navegación por año 100% AJAX (La URL del navegador permanece limpia en /holidays)
async function goToYear(targetYear) {
    if (loadingYear.value) return;
    currentYear.value = targetYear;
    loadingYear.value = true;
    try {
        const res = await axios.get('/holidays', {
            params: { year: targetYear },
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (res.data && res.data.holidays) {
            holidaysList.value = res.data.holidays;
        }
    } catch (err) {
        console.error(err);
        showToast('Error al cargar feriados del año ' + targetYear, 'danger');
    } finally {
        loadingYear.value = false;
    }
}

// Cargar / Sincronizar feriados oficiales de Perú vía AJAX
async function handleLoadPeru() {
    if (loadingSync.value) return;
    loadingSync.value = true;
    try {
        const res = await axios.post('/holidays/load-peru', {
            year: currentYear.value,
        }, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        showToast(res.data?.message || 'Feriados sincronizados correctamente.', 'success');
        await goToYear(currentYear.value);
    } catch (err) {
        console.error(err);
        showToast('No se pudieron sincronizar los feriados.', 'danger');
    } finally {
        loadingSync.value = false;
    }
}

// Envío del formulario de nuevo feriado vía AJAX (Sin recarga ni parámetros en URL)
async function submitHoliday() {
    formLoading.value = true;
    formErrors.value = {};
    try {
        const res = await axios.post('/holidays', {
            date: formDate.value,
            name: formName.value,
        }, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        formName.value = '';
        showToast(res.data?.message || 'Feriado agregado con éxito.', 'success');

        const hYear = new Date(formDate.value + 'T00:00:00').getFullYear();
        if (hYear === currentYear.value) {
            await goToYear(currentYear.value);
        } else {
            await goToYear(hYear);
        }
    } catch (err) {
        if (err.response?.data?.errors) {
            formErrors.value = err.response.data.errors;
        } else {
            showToast('Error al registrar el feriado.', 'danger');
        }
    } finally {
        formLoading.value = false;
    }
}

// Eliminación con Modal de Confirmación y AJAX
async function destroyHoliday(holiday) {
    const ok = await ask({
        title: '¿Eliminar feriado?',
        message: `¿Estás seguro de eliminar el feriado "${holiday.name}" (${holiday.formatted_date})? Esta fecha volverá a considerarse como día laboral ordinario.`,
        confirmText: 'Sí, eliminar',
        cancelText: 'Cancelar',
        variant: 'danger',
    });

    if (ok) {
        try {
            await axios.delete(`/holidays/${holiday.id}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            holidaysList.value = holidaysList.value.filter((h) => h.id !== holiday.id);
            showToast(`Feriado "${holiday.name}" eliminado correctamente.`, 'success');
        } catch (err) {
            console.error(err);
            showToast('No se pudo eliminar el feriado.', 'danger');
        }
    }
}
</script>

<template>
    <AppLayout title="Feriados">

        <!-- Header Principal de la Pantalla con Acciones -->
        <div class="d-flex align-items-center justify-content-between flex-wrap mb-4" style="gap: 1.25rem;">
            <div>
                <h1 class="h2 fw-bold text-dark mb-1">Feriados</h1>
                <p class="text-secondary mb-0" style="font-size: 0.875rem;">
                    En estas fechas el control de faltas no registra ausencias. Agrega los feriados nacionales y los propios de la empresa.
                </p>
            </div>
            <div>
                <button 
                    type="button" 
                    class="btn btn-outline-primary d-inline-flex align-items-center shadow-sm" 
                    style="height: 38px; border-radius: 8px; font-weight: 600; padding: 0 1rem; gap: 8px !important; white-space: nowrap; font-size: 0.85rem;"
                    :disabled="loadingSync"
                    title="Sincronizar feriados oficiales de ley de Perú"
                    @click="handleLoadPeru"
                >
                    <span v-if="loadingSync" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <i v-else class="bi bi-arrow-repeat" style="font-size: 0.95rem;"></i>
                    <span>Sincronizar feriados oficiales {{ currentYear }}</span>
                </button>
            </div>
        </div>

        <!-- Layout de 2 Columnas de Misma Altura: Formulario a la Izquierda y Tabla a la Derecha -->
        <div class="row g-4 align-items-stretch">
            <!-- =====================================================================
                 COLUMNA 1: FORMULARIO NUEVO FERIADO Y RESUMEN (ALTURA UNIFICADA)
                 ===================================================================== -->
            <div class="col-12 col-xl-4 d-flex flex-column justify-content-between" style="gap: 16px; position: relative; z-index: 30;">
                <div class="emp-table-card shadow-sm p-4" style="overflow: visible !important; position: relative; z-index: 30;">
                    <div class="d-flex align-items-center mb-3" style="gap: 10px !important;">
                        <div class="calendar-trigger-icon" style="width: 34px; height: 34px; font-size: 1rem;">
                            <i class="bi bi-calendar-plus"></i>
                        </div>
                        <div>
                            <h2 class="h6 fw-bold text-dark mb-0">Nuevo feriado</h2>
                            <span class="small text-muted" style="font-size: 0.75rem;">Registra un día no laborable en el calendario</span>
                        </div>
                    </div>

                    <form @submit.prevent="submitHoliday">
                        <!-- Fecha con DateRangePicker Moderno (Single Date Mode, Compacto 290px, Smart Flip) -->
                        <div class="mb-3" style="position: relative; z-index: 35;">
                            <label class="form-label fw-semibold small text-dark mb-1.5">
                                Fecha <span class="text-danger">*</span>
                            </label>
                            <DateRangePicker 
                                v-model:startDate="formDate"
                                :single-date="true"
                                :today="today"
                                label="Fecha del feriado"
                                :allow-indefinite="false"
                                align="right"
                            />
                            <div v-if="formErrors.date" class="invalid-feedback d-block mt-1">
                                {{ formErrors.date[0] || formErrors.date }}
                            </div>
                        </div>

                        <!-- Nombre o Motivo del Feriado -->
                        <div class="mb-4" style="position: relative; z-index: 1;">
                            <label for="holiday_name" class="form-label fw-semibold small text-dark mb-1.5">
                                Nombre <span class="text-danger">*</span>
                            </label>
                            <input 
                                id="holiday_name"
                                v-model="formName"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': formErrors.name }"
                                placeholder="Ej.: Aniversario de la empresa, Día cívico..."
                                style="height: 42px; border-radius: 8px; font-size: 0.85rem;"
                                maxlength="150"
                                required
                            >
                            <div v-if="formErrors.name" class="invalid-feedback d-block mt-1">
                                {{ formErrors.name[0] || formErrors.name }}
                            </div>
                        </div>

                        <!-- Botón de Envío -->
                        <button 
                            type="submit" 
                            class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center shadow-sm"
                            style="height: 42px; border-radius: 8px; font-weight: 600; gap: 8px !important;"
                            :disabled="formLoading"
                        >
                            <span v-if="formLoading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                            <i v-else class="bi bi-plus-lg" style="margin-right: 4px;"></i>
                            <span>Agregar feriado</span>
                        </button>
                    </form>
                </div>

                <!-- Resumen de Feriados del Año -->
                <div class="emp-table-card shadow-sm p-3" style="position: relative; z-index: 5;">
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="small fw-semibold text-secondary">Nacionales oficiales:</span>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="border-radius: 6px; font-weight: 600;">
                            {{ nationalCount }} días
                        </span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top">
                        <span class="small fw-semibold text-secondary">Propios de la empresa:</span>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="border-radius: 6px; font-weight: 600;">
                            {{ corporateCount }} días
                        </span>
                    </div>
                </div>
            </div>

            <!-- =====================================================================
                 COLUMNA 2: TABLA DE FERIADOS DEL AÑO CON ALTURA IGUALADA Y SCROLL
                 ===================================================================== -->
            <div class="col-12 col-xl-8 d-flex flex-column">
                <div class="emp-table-card shadow-sm h-100 d-flex flex-column">
                    <!-- Cabecera de la Tarjeta con Filtro y Selector de Año (100% AJAX) -->
                    <div class="card-header border-bottom bg-transparent py-3 px-4 d-flex align-items-center justify-content-between flex-wrap" style="gap: 0.85rem;">
                        <div class="d-flex align-items-center" style="gap: 12px !important;">
                            <i class="bi bi-calendar-event text-primary fs-5"></i>
                            <h2 class="h6 fw-bold mb-0 text-dark">Feriados {{ currentYear }}</h2>
                            <span class="badge bg-body-secondary text-secondary border px-2.5 py-1" style="border-radius: 6px; font-weight: 600; font-size: 0.75rem;">
                                <span v-if="loadingYear" class="spinner-border spinner-border-sm me-1" style="width: 0.7rem; height: 0.7rem;" role="status"></span>
                                {{ holidaysList.length }} {{ holidaysList.length === 1 ? 'feriado' : 'feriados' }}
                            </span>
                        </div>

                        <!-- Stepper de Año y Buscador con Espaciado Garantizado -->
                        <div class="d-flex align-items-center flex-grow-1 flex-sm-grow-0" style="gap: 12px !important;">
                            <!-- Buscador: 210 px en escritorio; en celular se ajusta al ancho que queda -->
                            <div class="position-relative" style="flex: 1 1 210px; min-width: 0;">
                                <i 
                                    class="bi bi-search" 
                                    style="position: absolute; left: 14px !important; top: 50%; transform: translateY(-50%); font-size: 0.85rem; color: #94a3b8; pointer-events: none; z-index: 5;"
                                ></i>
                                <input 
                                    v-model="searchQuery" 
                                    type="text" 
                                    class="form-control form-control-sm" 
                                    placeholder="Filtrar feriados..."
                                    style="border-radius: 20px; font-size: 0.825rem; height: 36px; padding-left: 38px !important; padding-right: 14px; border: 1.5px solid #cbd5e1; background-color: var(--bg-surface, #ffffff);"
                                >
                            </div>

                            <!-- Navegador de Años 100% AJAX -->
                            <div class="btn-group btn-group-sm shadow-sm" role="group" aria-label="Navegación de Año">
                                <button 
                                    type="button" 
                                    class="btn btn-outline-secondary" 
                                    title="Año anterior"
                                    :disabled="loadingYear"
                                    @click="goToYear(currentYear - 1)"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <span class="btn btn-outline-secondary disabled fw-bold text-dark px-3" style="opacity: 1; cursor: default;">
                                    <span v-if="loadingYear" class="spinner-border spinner-border-sm me-1" style="width: 0.65rem; height: 0.65rem;"></span>
                                    {{ currentYear }}
                                </span>
                                <button 
                                    type="button" 
                                    class="btn btn-outline-secondary" 
                                    title="Año siguiente"
                                    :disabled="loadingYear"
                                    @click="goToYear(currentYear + 1)"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Tabla de Feriados con Scroll Vertical para Misma Altura -->
                    <div 
                        class="emp-table-responsive flex-grow-1" 
                        style="max-height: 440px; overflow-y: auto; transition: opacity 0.2s ease;"
                        :class="{ 'sk-loading': showYearSkeleton }"
                        :aria-busy="loadingYear"
                    >
                        <table class="emp-table align-middle">
                            <thead style="position: sticky; top: 0; z-index: 2; background: var(--bg-surface);">
                                <tr>
                                    <th class="ps-4">Fecha</th>
                                    <th>Día</th>
                                    <th>Feriado</th>
                                    <th>Tipo</th>
                                    <th class="pe-4 text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="h in filteredHolidays" :key="h.id">
                                    <!-- Fecha -->
                                    <td class="ps-4 py-3 text-nowrap">
                                        <div class="d-flex align-items-center" style="gap: 8px !important;">
                                            <i class="bi bi-calendar-day text-muted" style="font-size: 0.9rem;"></i>
                                            <span class="fw-bold text-dark" style="font-size: 0.885rem;">
                                                {{ h.formatted_date }}
                                            </span>
                                        </div>
                                    </td>

                                    <!-- Día de la semana -->
                                    <td class="py-3 text-nowrap">
                                        <span class="badge bg-body-secondary text-secondary border px-2.5 py-1" style="border-radius: 6px; font-size: 0.75rem; font-weight: 600;">
                                            {{ h.day_name }}
                                        </span>
                                    </td>

                                    <!-- Nombre del Feriado -->
                                    <td class="py-3">
                                        <div class="fw-semibold text-dark" style="font-size: 0.885rem;">
                                            {{ h.name }}
                                        </div>
                                        <div v-if="h.tenant_id" style="margin-top: 3px;">
                                            <TenantBadge :tenant-id="h.tenant_id" />
                                        </div>
                                    </td>

                                    <!-- Tipo (Nacional vs Corporativo) -->
                                    <td class="py-3 text-nowrap">
                                        <span 
                                            v-if="h.is_national" 
                                            class="badge bg-primary-subtle text-primary border border-primary-subtle" 
                                            style="border-radius: 6px; font-weight: 600; font-size: 0.725rem; padding: 3px 8px;"
                                        >
                                            <i class="bi bi-flag-fill me-1" style="font-size: 0.65rem;"></i>Nacional
                                        </span>
                                        <span 
                                            v-else 
                                            class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" 
                                            style="border-radius: 6px; font-weight: 600; font-size: 0.725rem; padding: 3px 8px;"
                                        >
                                            <i class="bi bi-building me-1" style="font-size: 0.65rem;"></i>Empresa
                                        </span>
                                    </td>

                                    <!-- Acciones: Botón Eliminar con Confirmación Moderna -->
                                    <td class="pe-4 py-3 text-end text-nowrap">
                                        <div class="emp-table-actions justify-content-end">
                                            <button 
                                                type="button" 
                                                class="btn-table-action btn-table-delete" 
                                                :title="`Eliminar feriado ${h.formatted_date}`"
                                                @click="destroyHoliday(h)"
                                            >
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Estado Vacío Limpio Sin Botón Redundante -->
                                <tr v-if="!filteredHolidays.length">
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="bi bi-calendar2-check fs-1 d-block mb-2 opacity-50" style="color: var(--accent-cyan);"></i>
                                        <div class="fw-medium text-dark">No hay feriados que coincidan en {{ currentYear }}.</div>
                                        <p class="small text-muted mb-0">
                                            Los feriados nacionales oficiales de ley se sincronizan automáticamente.
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
