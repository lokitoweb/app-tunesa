<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';
import { money } from '@/Modules/Core/format';

const props = defineProps({
    requisitions: { type: Object, required: true },
    stages: { type: Object, required: true },
    totals: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    can_create: { type: Boolean, default: false },
    company_code: { type: String, default: null },
    can_edit_code: { type: Boolean, default: false },
});

// Abreviatura [EMPRESA] de los correlativos RQ/OC (los ya emitidos no cambian)
const codeForm = useForm({ code: props.company_code || '' });
const editingCode = ref(false);
function saveCode() {
    codeForm.put('/requisitions/company-code', { preserveScroll: true, onSuccess: () => { editingCode.value = false; } });
}
function cancelCode() {
    editingCode.value = false;
    codeForm.code = props.company_code || '';
    codeForm.clearErrors();
}

const { ensureTenant } = useTenantPicker();
const search = ref(props.filters.search || '');
// Desde / Hasta por fecha del requerimiento (sisgere FrmReq › Buscar)
const from = ref(props.filters.from || '');
const to = ref(props.filters.to || '');

let timer = null;
function go(params) {
    router.get('/requisitions', { search: search.value || undefined, stage: props.filters.stage || undefined, from: from.value || undefined, to: to.value || undefined, ...params }, { preserveState: true, replace: true });
}
function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => go({}), 300);
}

function create() {
    if (ensureTenant({ message: 'El requerimiento se registra en una empresa.' })) router.get('/requisitions/create');
}

// Color de cada etapa con los tonos del sistema (Requisition::STAGES trae el color de Bootstrap)
const TONES = { warning: 'amber', info: 'blue', primary: 'brand', success: 'emerald', dark: 'neutral', danger: 'rose', secondary: 'neutral' };
const tone = (stage) => `tone-${TONES[props.stages[stage]?.[1]] || 'neutral'}`;
const filtered = () => props.filters.search || props.filters.stage || props.filters.from || props.filters.to;
</script>

<template>
    <AppLayout title="Requerimientos y compras">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Requerimientos y compras</h1>
                <p class="page-subtitle">Solicitud, aprobación, cotización y orden de compra o servicio.</p>
            </div>
            <div class="page-actions align-items-center">
                <div v-if="company_code" class="small">
                    <form v-if="editingCode" class="d-flex align-items-start gap-1" @submit.prevent="saveCode">
                        <div>
                            <input v-model="codeForm.code" maxlength="2" aria-label="Abreviatura de la empresa" class="form-control form-control-sm text-uppercase font-monospace" style="width: 4rem"
                                   :class="{ 'is-invalid': codeForm.errors.code }">
                            <div class="invalid-feedback">{{ codeForm.errors.code }}</div>
                        </div>
                        <button class="btn btn-sm btn-primary" :disabled="codeForm.processing">Guardar</button>
                        <button type="button" class="btn btn-sm btn-light border" @click="cancelCode">Cancelar</button>
                    </form>
                    <span v-else class="text-body-secondary d-inline-flex align-items-center gap-1">
                        Abreviatura en correlativos <span class="status-pill tone-neutral font-monospace">{{ company_code }}</span>
                        <button v-if="can_edit_code" type="button" class="icon-btn" aria-label="Editar abreviatura" title="Editar abreviatura" @click="editingCode = true"><i class="bi bi-pencil"></i></button>
                    </span>
                </div>
                <button v-if="can_create" type="button" class="btn btn-primary" @click="create">
                    <i class="bi bi-plus-lg me-1"></i>Nuevo requerimiento
                </button>
            </div>
        </header>

        <section class="surface">
            <div class="surface-toolbar">
                <div class="seg-tabs" role="tablist" aria-label="Etapas">
                    <button type="button" role="tab" class="seg-tab" :class="{ 'is-active': !filters.stage }" :aria-selected="!filters.stage" @click="go({ stage: undefined })">
                        Todos <span class="count-pill">{{ Object.values(totals).reduce((a, b) => a + b, 0) }}</span>
                    </button>
                    <button v-for="(s, key) in stages" :key="key" type="button" role="tab" class="seg-tab" :class="{ 'is-active': filters.stage === key }"
                        :aria-selected="filters.stage === key" @click="go({ stage: key })">
                        {{ s[0] }} <span class="count-pill" :class="filters.stage === key ? tone(key) : ''">{{ totals[key] || 0 }}</span>
                    </button>
                </div>
            </div>

            <div class="filter-row">
                <div class="search-field">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input v-model="search" type="search" class="form-control" placeholder="Buscar por N.º, detalle, área, solicitante u orden" aria-label="Buscar requerimiento" @input="onSearch" />
                </div>
                <div class="range-field" role="group" aria-label="Fecha del requerimiento">
                    <input v-model="from" type="date" class="form-control" aria-label="Desde" title="Desde" @change="go({})" />
                    <span class="range-sep" aria-hidden="true">–</span>
                    <input v-model="to" type="date" class="form-control" aria-label="Hasta" title="Hasta" :min="from || undefined" @change="go({})" />
                </div>
            </div>

            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead>
                        <tr>
                            <th>N.º</th>
                            <th>Detalle</th>
                            <th>Solicita</th>
                            <th>Proveedor / Orden</th>
                            <th class="text-end">Monto</th>
                            <th>Etapa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in requisitions.data" :key="r.id" class="is-clickable" @click="router.get(`/requisitions/${r.id}`)">
                            <td data-label="N.º" class="text-nowrap">
                                <div class="d-flex align-items-center gap-1">
                                    <Link :href="`/requisitions/${r.id}`" class="cell-code" @click.stop>{{ r.code }}</Link>
                                    <span v-if="r.is_express" class="status-pill tone-amber" title="Express"><i class="bi bi-lightning-charge-fill"></i>Express</span>
                                </div>
                                <div class="cell-sub tabular">{{ r.requested_at }}</div>
                            </td>
                            <td data-label="Detalle" class="cell-main">
                                <div class="min-w-0">
                                    <div class="cell-title fw-medium text-truncate">{{ r.detail }}</div>
                                    <div class="cell-sub">{{ r.classification }} · {{ r.items_count }} ítem(s)</div>
                                </div>
                            </td>
                            <td data-label="Solicita" class="text-nowrap">
                                <div class="cell-title fw-medium">{{ r.requested_by }}</div>
                                <div class="cell-sub">{{ r.area }}</div>
                            </td>
                            <td data-label="Proveedor">
                                <div class="cell-title fw-medium">{{ r.supplier || '—' }}</div>
                                <div class="cell-sub font-monospace">{{ r.order_number }}</div>
                            </td>
                            <td data-label="Monto" class="text-end text-nowrap"><span class="cell-amount">{{ r.amount ? money(r.amount, r.currency) : '—' }}</span></td>
                            <td data-label="Etapa"><span class="status-pill" :class="tone(r.stage)">{{ stages[r.stage]?.[0] }}</span></td>
                        </tr>
                        <tr v-if="!requisitions.data.length" class="row-empty">
                            <td colspan="6">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-cart3"></i></span>
                                    <p class="dash-empty-title">{{ filtered() ? 'Ningún requerimiento coincide con el filtro' : 'Aún no hay requerimientos' }}</p>
                                    <p class="dash-empty-text">{{ filtered() ? 'Prueba con otra etapa, otra búsqueda o sin fechas.' : 'Registra el primero con «Nuevo requerimiento».' }}</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="surface-foot">
                <span class="small text-body-secondary">{{ requisitions.total }} requerimiento(s)</span>
                <Pagination :paginator="requisitions" />
            </footer>
        </section>
    </AppLayout>
</template>
