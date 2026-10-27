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
const url = (key) => `/reports/${key}?${new URLSearchParams({ from: from.value, to: to.value })}`;
</script>

<template>
    <AppLayout title="Reportes">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Reportes</h1>
                <p class="text-body-secondary small mb-0">Los reportes del sistema anterior, en Excel, para el periodo que elijas.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label small fw-semibold mb-1" for="rep-from">Desde</label>
                    <input id="rep-from" v-model="from" type="date" class="form-control form-control-sm" />
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1" for="rep-to">Hasta</label>
                    <input id="rep-to" v-model="to" type="date" class="form-control form-control-sm" :class="{ 'is-invalid': invalid }" />
                </div>
            </div>
        </div>
        <div v-if="invalid" class="alert alert-warning py-2 small">La fecha final no puede ser anterior a la inicial.</div>

        <div class="row g-3">
            <div v-for="r in reports" :key="r.key" class="col-md-6 col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="fw-semibold mb-1"><i class="bi bi-file-earmark-spreadsheet text-success me-1"></i>{{ r.title }}</div>
                        <p class="small text-body-secondary mb-2 flex-grow-1">{{ r.description }}</p>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small text-body-tertiary" :title="`Equivale a ${r.legacy} del sistema anterior`">{{ r.legacy }}</span>
                            <a :href="invalid ? undefined : url(r.key)" class="btn btn-sm btn-outline-success" :class="{ disabled: invalid }" :aria-disabled="invalid"><i class="bi bi-download me-1"></i>Excel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
