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
    kinds: { type: Object, default: () => ({}) },
    overtimeRules: { type: Object, default: () => ({}) },
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
const formKind = ref('holiday');
const formRule = ref('none');
const formFrom = ref('13:00');
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
            kind: formKind.value,
            overtime_rule: formRule.value,
            overtime_from: formRule.value === 'after' ? formFrom.value : null,
        }, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        formName.value = '';
        formKind.value = 'holiday';
        formRule.value = 'none';
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

// Tipo de día y regla de horas extra de una fila (se guarda al cambiar)
async function saveRule(h, changes) {
    const next = { kind: h.kind, overtime_rule: h.overtime_rule, overtime_from: h.overtime_from, ...changes };
    if (next.overtime_rule === 'after' && !next.overtime_from) next.overtime_from = '13:00';
    try {
        const res = await axios.put(`/holidays/${h.id}`, next, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        Object.assign(h, res.data.holiday);
        showToast(res.data.message, 'success');
    } catch (err) {
        const errors = err.response?.data?.errors;
        showToast(errors ? Object.values(errors)[0][0] : 'No se pudo guardar la regla.', 'danger');
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
    <AppLayout title="Feriados y días especiales">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Feriados y días especiales</h1>
                <p class="page-subtitle">En los feriados no se registran faltas. Los días especiales (p. ej. 24 y 31 de diciembre) se trabajan. En ambos eliges cómo se cuentan las horas extra.</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-light border" :disabled="loadingSync" title="Sincronizar feriados oficiales de ley de Perú" @click="handleLoadPeru">
                    <span v-if="loadingSync" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <i v-else class="bi bi-arrow-repeat me-1"></i>Sincronizar feriados oficiales {{ currentYear }}
                </button>
            </div>
        </header>

        <div class="row g-3 align-items-stretch">
            <!-- Nuevo feriado y resumen del año -->
            <div class="col-12 col-xl-4 d-flex flex-column gap-3" style="position: relative; z-index: 30;">
                <section class="surface" style="overflow: visible;">
                    <header class="surface-head">
                        <div class="d-flex align-items-center gap-2">
                            <span class="list-plain-icon"><i class="bi bi-calendar-plus"></i></span>
                            <div>
                                <h2 class="surface-title">Nuevo día</h2>
                                <p class="surface-sub">Feriado o día especial, con su regla de horas extra</p>
                            </div>
                        </div>
                    </header>
                    <form class="surface-pad" @submit.prevent="submitHoliday">
                        <div class="mb-3" style="position: relative; z-index: 35;">
                            <label class="form-label">Fecha <span class="text-danger">*</span></label>
                            <DateRangePicker v-model:startDate="formDate" :single-date="true" :today="today" label="Fecha del feriado" :allow-indefinite="false" align="right" />
                            <div v-if="formErrors.date" class="invalid-feedback d-block mt-1">{{ formErrors.date[0] || formErrors.date }}</div>
                        </div>
                        <div class="mb-3">
                            <label for="holiday_name" class="form-label">Nombre <span class="text-danger">*</span></label>
                            <input id="holiday_name" v-model="formName" type="text" class="form-control" :class="{ 'is-invalid': formErrors.name }"
                                placeholder="Ej.: Aniversario de la empresa, Día cívico..." maxlength="150" required>
                            <div v-if="formErrors.name" class="invalid-feedback d-block mt-1">{{ formErrors.name[0] || formErrors.name }}</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tipo de día</label>
                            <div class="seg-tabs w-100" role="radiogroup" aria-label="Tipo de día">
                                <button v-for="(label, key) in kinds" :key="key" type="button" role="radio" :aria-checked="formKind === key"
                                    class="seg-tab flex-fill justify-content-center" :class="{ 'is-active': formKind === key }" @click="formKind = key">{{ label.split(' (')[0] }}</button>
                            </div>
                            <div class="form-text">{{ formKind === 'holiday' ? 'No laborable: no se registran faltas.' : 'Laborable: se marca y se controla como cualquier día.' }}</div>
                        </div>
                        <div class="mb-3">
                            <label for="holiday_rule" class="form-label">Horas extra ese día</label>
                            <div class="d-flex gap-2">
                                <select id="holiday_rule" v-model="formRule" class="form-select">
                                    <option v-for="(label, key) in overtimeRules" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <input v-if="formRule === 'after'" v-model="formFrom" type="time" step="60" class="form-control hol-time" aria-label="Hora extra desde" required>
                            </div>
                            <div v-if="formErrors.overtime_from" class="invalid-feedback d-block mt-1">{{ formErrors.overtime_from[0] }}</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" :disabled="formLoading">
                            <span v-if="formLoading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                            <i v-else class="bi bi-plus-lg me-1"></i>Agregar día
                        </button>
                    </form>
                </section>

                <section class="dash-kpis dash-kpis-2 mb-0" aria-label="Feriados del año">
                    <article class="dash-kpi-card">
                        <div class="dash-kpi-top"><span class="dash-kpi-label">Nacionales</span><span class="dash-kpi-icon tone-brand"><i class="bi bi-flag"></i></span></div>
                        <div class="dash-kpi-value">{{ nationalCount }}</div>
                    </article>
                    <article class="dash-kpi-card">
                        <div class="dash-kpi-top"><span class="dash-kpi-label">De la empresa</span><span class="dash-kpi-icon tone-neutral"><i class="bi bi-building"></i></span></div>
                        <div class="dash-kpi-value">{{ corporateCount }}</div>
                    </article>
                </section>
            </div>

            <!-- Feriados del año -->
            <div class="col-12 col-xl-8 d-flex flex-column">
                <section class="surface h-100 d-flex flex-column">
                    <div class="filter-row">
                        <div class="d-flex align-items-center gap-2 me-auto">
                            <h2 class="surface-title">Feriados {{ currentYear }}</h2>
                            <span class="count-pill">
                                <span v-if="loadingYear" class="spinner-border spinner-border-sm me-1" style="width: 0.6rem; height: 0.6rem;" role="status"></span>{{ holidaysList.length }}
                            </span>
                        </div>
                        <div class="search-field" style="flex: 1 1 200px; max-width: 260px;">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <input v-model="searchQuery" type="search" class="form-control" placeholder="Filtrar feriados..." aria-label="Filtrar feriados">
                        </div>
                        <div class="d-flex align-items-center gap-1" role="group" aria-label="Navegación de año">
                            <button type="button" class="icon-btn border" title="Año anterior" aria-label="Año anterior" :disabled="loadingYear" @click="goToYear(currentYear - 1)"><i class="bi bi-chevron-left"></i></button>
                            <span class="small fw-bold px-1 tabular">{{ currentYear }}</span>
                            <button type="button" class="icon-btn border" title="Año siguiente" aria-label="Año siguiente" :disabled="loadingYear" @click="goToYear(currentYear + 1)"><i class="bi bi-chevron-right"></i></button>
                        </div>
                    </div>

                    <div class="table-responsive flex-grow-1" style="max-height: 460px; overflow-y: auto;" :class="{ 'sk-loading': showYearSkeleton }" :aria-busy="loadingYear">
                        <table class="table data-table table-cards align-middle mb-0">
                            <thead><tr><th>Fecha</th><th>Feriado</th><th>Tipo</th><th>Horas extra</th><th><span class="visually-hidden">Acciones</span></th></tr></thead>
                            <tbody>
                                <tr v-for="h in filteredHolidays" :key="h.id">
                                    <td data-label="Fecha" class="text-nowrap"><div class="cell-title tabular">{{ h.formatted_date }}</div><div class="cell-sub">{{ h.day_name }}</div></td>
                                    <td data-label="Feriado" class="cell-main">
                                        <div class="min-w-0">
                                            <div class="cell-title fw-medium">{{ h.name }}</div>
                                            <div v-if="h.tenant_id" class="mt-1"><TenantBadge :tenant-id="h.tenant_id" /></div>
                                        </div>
                                    </td>
                                    <td data-label="Tipo">
                                        <div class="d-flex flex-wrap gap-1">
                                            <span v-if="h.is_national" class="status-pill tone-brand"><i class="bi bi-flag-fill"></i>Nacional</span>
                                            <span v-else class="status-pill tone-neutral"><i class="bi bi-building"></i>Empresa</span>
                                            <button type="button" class="status-pill border-0" :class="h.kind === 'special' ? 'tone-amber' : 'tone-emerald'"
                                                :title="h.kind === 'special' ? 'Día laborable. Clic para volverlo feriado' : 'No laborable. Clic para volverlo día especial'"
                                                @click="saveRule(h, { kind: h.kind === 'special' ? 'holiday' : 'special' })">
                                                {{ h.kind === 'special' ? 'Día especial' : 'Feriado' }}
                                            </button>
                                        </div>
                                    </td>
                                    <td data-label="Horas extra">
                                        <div class="d-flex flex-wrap gap-1">
                                            <select class="form-select form-select-sm hol-rule" :value="h.overtime_rule" :aria-label="`Horas extra el ${h.formatted_date}`"
                                                @change="saveRule(h, { overtime_rule: $event.target.value })">
                                                <option v-for="(label, key) in overtimeRules" :key="key" :value="key">{{ label }}</option>
                                            </select>
                                            <input v-if="h.overtime_rule === 'after'" type="time" step="60" class="form-control form-control-sm hol-time" :value="h.overtime_from"
                                                :aria-label="`Hora extra desde, ${h.formatted_date}`" @change="saveRule(h, { overtime_from: $event.target.value })">
                                        </div>
                                    </td>
                                    <td class="cell-actions">
                                        <div class="row-actions">
                                            <button type="button" class="icon-btn icon-btn-danger" :title="`Eliminar feriado ${h.formatted_date}`" :aria-label="`Eliminar feriado ${h.formatted_date}`" @click="destroyHoliday(h)"><i class="bi bi-trash3"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!filteredHolidays.length" class="row-empty">
                                    <td colspan="5">
                                        <div class="dash-empty">
                                            <span class="dash-empty-icon"><i class="bi bi-calendar2-check"></i></span>
                                            <p class="dash-empty-title">No hay feriados que coincidan en {{ currentYear }}</p>
                                            <p class="dash-empty-text">Los feriados nacionales oficiales de ley se sincronizan automáticamente.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<style>
.hol-rule { min-width: 170px; }
.hol-time { width: 140px; flex-shrink: 0; }
</style>
