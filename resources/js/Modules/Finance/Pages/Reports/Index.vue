<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({
    reports: { type: Array, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
});

const from = ref(props.from);
const to = ref(props.to);
const invalid = computed(() => !from.value || !to.value || to.value < from.value);
const plate = ref('');
// Centro de costos: con placa, solo ese vehículo (crcentro_c); sin placa, el general (Crcentroc_gen)
const url = (key) => `/finance-reports/${key}?${new URLSearchParams({ from: from.value, to: to.value, ...(key === 'cost_center' && plate.value.trim() ? { plate: plate.value.trim() } : {}) })}`;
</script>

<template>
    <AppLayout title="Reportes de finanzas">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Reportes de finanzas</h1>
                <p class="page-subtitle">Los reportes del sistema anterior, en Excel, para el periodo que elijas.</p>
            </div>
            <div class="range-field align-self-end" role="group" aria-label="Periodo">
                <label class="visually-hidden" for="rep-from">Desde</label>
                <input id="rep-from" v-model="from" type="date" class="form-control" title="Desde" />
                <span class="range-sep" aria-hidden="true">–</span>
                <label class="visually-hidden" for="rep-to">Hasta</label>
                <input id="rep-to" v-model="to" type="date" class="form-control" title="Hasta" :class="{ 'is-invalid': invalid }" />
            </div>
        </header>
        <div v-if="invalid" class="dash-notice notice-warning"><i class="bi bi-exclamation-triangle"></i><span>La fecha final no puede ser anterior a la inicial.</span></div>

        <div class="report-grid">
            <article v-for="r in reports" :key="r.key" class="report-card">
                <div class="d-flex align-items-start gap-2">
                    <span class="dash-kpi-icon tone-emerald flex-shrink-0"><i class="bi bi-file-earmark-spreadsheet"></i></span>
                    <div class="min-w-0">
                        <h2 class="report-card-title">{{ r.title }}</h2>
                        <p class="report-card-text">{{ r.description }}</p>
                    </div>
                </div>
                <input v-if="r.key === 'cost_center'" v-model="plate" type="text" maxlength="10" class="form-control form-control-sm text-uppercase" placeholder="Placa (opcional)" aria-label="Placa" />
                <div class="d-flex justify-content-between align-items-center gap-2 mt-auto pt-1">
                    <span class="status-pill tone-neutral font-monospace d-block text-truncate min-w-0" style="line-height: 22px" :title="`Equivale a ${r.legacy} del sistema anterior`">{{ r.legacy }}</span>
                    <a :href="invalid ? undefined : url(r.key)" class="btn btn-sm btn-light border flex-shrink-0 text-nowrap" :class="{ disabled: invalid }" :aria-disabled="invalid"><i class="bi bi-download me-1"></i>Excel</a>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
