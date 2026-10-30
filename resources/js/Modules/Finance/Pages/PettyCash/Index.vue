<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';
import { money } from '@/Modules/Core/format';

const props = defineProps({
    expenses: { type: Object, required: true },
    total: { type: Number, default: 0 },
    by_box: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
});

const { ask } = useConfirm();
const { ensureTenant } = useTenantPicker();
const today = new Date().toLocaleDateString('en-CA') // fecha local (toISOString es UTC: después de las 19:00 en Lima daría mañana);


const showForm = ref(false);
const fileInput = ref(null);
const blank = () => ({ id: null, date: today, description: '', category: '', beneficiary: '', document_type: 'BOLETA', document_number: '', ruc: '', amount: '', cash_box: props.filters.box || 'CAJA EFECTIVO', voucher: null });
const form = useForm(blank());

function go(params) {
    router.get('/petty-cash', { month: props.filters.month, box: props.filters.box || undefined, ...params }, { preserveState: true, replace: true });
}

function openCreate() {
    if (!ensureTenant({ message: 'El gasto se registra en una empresa.' })) return;
    showImport.value = false;
    Object.assign(form, blank());
    form.clearErrors();
    if (fileInput.value) fileInput.value.value = '';
    showForm.value = true;
}
function openEdit(e) {
    showImport.value = false;
    Object.assign(form, { ...blank(), ...Object.fromEntries(Object.entries(e).map(([k, v]) => [k, v ?? ''])), voucher: null });
    form.clearErrors();
    if (fileInput.value) fileInput.value.value = '';
    showForm.value = true;
}
function submit() {
    const options = { preserveScroll: true, forceFormData: true, onSuccess: () => (showForm.value = false) };
    if (form.id) form.post(`/petty-cash/${form.id}`, options);
    else form.post('/petty-cash', options);
}
// Importación desde Excel
const showImport = ref(false);
const importInput = ref(null);
const importForm = useForm({ file: null, cash_box: props.options.cash_boxes[0] });
const importErrors = computed(() => [importForm.errors.file, importForm.errors.cash_box].filter(Boolean).flatMap((e) => e.split('\n')));
function openImport() {
    if (!ensureTenant({ message: 'Los gastos se importan en una empresa.' })) return;
    showForm.value = false;
    importForm.reset();
    importForm.clearErrors();
    if (importInput.value) importInput.value.value = '';
    preview.value = null;
    showImport.value = true;
}
// Fcaja_chica › Importar Archivo: revisar (# Registros, Total, Válidos, No válidos) antes de Procesar Carga
const preview = ref(null);
const previewing = ref(false);
async function loadPreview() {
    preview.value = null;
    importForm.clearErrors();
    if (!importForm.file) return;
    previewing.value = true;
    const data = new FormData();
    data.append('file', importForm.file);
    data.append('cash_box', importForm.cash_box);
    try {
        preview.value = (await axios.post('/petty-cash/import/preview', data)).data;
    } catch (e) {
        importForm.setError('file', Object.values(e.response?.data?.errors || {}).flat().join('\n') || e.response?.data?.message || 'No se pudo revisar el archivo.');
    } finally {
        previewing.value = false;
    }
}
function submitImport() {
    importForm.post('/petty-cash/import', { preserveScroll: true, forceFormData: true, onSuccess: () => (showImport.value = false) });
}

async function destroy(e) {
    const ok = await ask({ title: '¿Eliminar gasto?', message: `Recibo N.º ${e.receipt_number} · ${money(e.amount)}`, confirmText: 'Sí, eliminar', variant: 'danger', icon: 'bi bi-trash3-fill' });
    if (ok) router.delete(`/petty-cash/${e.id}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Caja chica">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Caja chica</h1>
                <p class="page-subtitle">Gastos menores con recibo correlativo y su comprobante.</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-light border" :aria-expanded="showImport" @click="openImport"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Importar Excel</button>
                <button type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Registrar gasto</button>
            </div>
        </header>

        <!-- Mes, caja y total del mes -->
        <section class="surface mb-3">
            <div class="filter-row border-0">
                <input type="month" class="form-control w-auto" :value="filters.month" aria-label="Mes" @change="go({ month: $event.target.value })" />
                <select class="form-select w-auto" :value="filters.box || ''" aria-label="Caja" @change="go({ box: $event.target.value || undefined })">
                    <option value="">Todas las cajas</option>
                    <option v-for="b in options.cash_boxes" :key="b" :value="b">{{ b }}</option>
                </select>
                <div class="ms-auto text-end">
                    <div class="dash-kpi-label">Total del mes</div>
                    <div class="cell-amount fs-5">{{ money(total) }}</div>
                </div>
            </div>
            <div v-if="Object.keys(by_box).length > 1" class="surface-foot justify-content-start gap-2">
                <span v-for="(t, b) in by_box" :key="b" class="status-pill tone-neutral">{{ b }} · {{ money(t) }}</span>
            </div>
        </section>

        <section class="surface">
                    <div class="table-responsive">
                        <table class="table data-table table-cards align-middle mb-0">
                            <thead>
                                <tr><th>Recibo</th><th>Concepto</th><th>Comprobante</th><th class="text-end">Monto</th><th class="text-end"><span class="visually-hidden">Acciones</span></th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="e in expenses.data" :key="e.id">
                                    <td data-label="Recibo" class="text-nowrap"><span class="cell-code">N.º {{ String(e.receipt_number).padStart(5, '0') }}</span><div class="cell-sub tabular">{{ e.date_label }}</div></td>
                                    <td data-label="Concepto" class="cell-main">
                                        <div class="min-w-0">
                                            <div class="cell-title fw-medium text-truncate">{{ e.description }}</div>
                                            <div class="cell-sub text-truncate">{{ e.category || '—' }}<span v-if="e.beneficiary"> · {{ e.beneficiary }}</span> · {{ e.cash_box }}</div>
                                        </div>
                                    </td>
                                    <td data-label="Comprobante" class="small">{{ e.document_type }} {{ e.document_number }}<div v-if="e.ruc" class="cell-sub font-monospace">{{ e.ruc }}</div></td>
                                    <td data-label="Monto" class="text-end text-nowrap"><span class="cell-amount">{{ money(e.amount) }}</span></td>
                                    <td class="cell-actions">
                                        <div class="row-actions">
                                            <a v-if="e.has_voucher" :href="`/petty-cash/${e.id}/voucher`" target="_blank" rel="noopener" class="icon-btn" title="Ver comprobante" aria-label="Ver comprobante"><i class="bi bi-file-earmark-arrow-down"></i></a>
                                            <button type="button" class="icon-btn" title="Editar gasto" aria-label="Editar gasto" @click="openEdit(e)"><i class="bi bi-pencil"></i></button>
                                            <button type="button" class="icon-btn icon-btn-danger" title="Eliminar gasto" aria-label="Eliminar gasto" @click="destroy(e)"><i class="bi bi-trash3"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!expenses.data.length" class="row-empty">
                                    <td colspan="5">
                                        <div class="dash-empty">
                                            <span class="dash-empty-icon"><i class="bi bi-wallet2"></i></span>
                                            <p class="dash-empty-title">No hay gastos en este mes</p>
                                            <p class="dash-empty-text">Registra un gasto o impórtalos desde Excel.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <footer class="surface-foot">
                        <span class="small text-body-secondary">{{ expenses.total }} gasto(s)</span>
                        <Pagination :paginator="expenses" />
                    </footer>
        </section>

            <!-- Importación: mismas reglas del registro manual; todo o nada -->
            <AppModal :show="showImport" title="Importar gastos desde Excel" size="lg" :busy="importForm.processing" @close="showImport = false">
                <template #subtitle>Columnas: Fecha, Tipo de Documento, RUC/Proveedor, Descripción del gasto, Categoría/Centro de Costo, Moneda (soles), Monto Total y Número de Comprobante.
                                    <a href="/petty-cash/template" class="text-nowrap"><i class="bi bi-download me-1"></i>Descargar plantilla</a></template>
                    <form id="pc-import-form" class="row g-3" novalidate @submit.prevent="submitImport">
                            <div class="col-12">
                                <label class="form-label" for="pi-box">Caja *</label>
                                <select id="pi-box" v-model="importForm.cash_box" class="form-select" @change="loadPreview"><option v-for="b in options.cash_boxes" :key="b" :value="b">{{ b }}</option></select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="pi-file">Archivo (.xlsx, .xls o .csv, máx. 5 MB) *</label>
                                <input id="pi-file" ref="importInput" type="file" accept=".xlsx,.xls,.csv" class="form-control" :class="{ 'is-invalid': importForm.errors.file }" @input="importForm.file = $event.target.files[0]; loadPreview()" />
                            </div>
                            <div v-if="previewing" class="col-12 small text-body-secondary"><span class="spinner-border spinner-border-sm me-1"></span>Revisando el archivo…</div>
                            <div v-if="preview" class="col-12">
                                <div class="preview-stats mb-2">
                                    <div class="concept-card"><div class="concept-card-title"># Registros</div><div class="concept-card-value">{{ preview.count }}</div></div>
                                    <div class="concept-card"><div class="concept-card-title">Total</div><div class="concept-card-value">{{ money(preview.total) }}</div></div>
                                    <div class="concept-card"><div class="concept-card-title">Válidos</div><div class="concept-card-value text-success">{{ preview.valid }}</div></div>
                                    <div class="concept-card"><div class="concept-card-title">No válidos</div><div class="concept-card-value" :class="{ 'text-danger': preview.invalid }">{{ preview.invalid }}</div></div>
                                </div>
                                <div class="table-responsive surface" style="max-height: 260px">
                                    <table class="table table-sm data-table small mb-0 align-middle">
                                        <thead><tr><th>Fila</th><th>Fecha</th><th>Comprobante</th><th>RUC / Proveedor</th><th>Glosa</th><th class="text-end">Monto</th></tr></thead>
                                        <tbody>
                                            <tr v-for="r in preview.rows" :key="r.line" :class="{ 'is-overdue': !r.ok }">
                                                <td>{{ r.line }}</td><td class="text-nowrap">{{ String(r.date || '').includes('-') ? String(r.date).slice(0, 10).split('-').reverse().join('/') : r.date }}</td><td class="text-nowrap">{{ r.document }}</td>
                                                <td>{{ r.beneficiary || r.ruc || '—' }}</td><td>{{ r.description }}</td><td class="text-end">{{ r.amount === null ? '—' : money(r.amount) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div v-if="preview.errors.length" class="alert alert-danger small mt-2 mb-0 py-2">
                                    <div class="fw-semibold mb-1">Corrige estas filas y vuelve a elegir el archivo (no se guardará nada hasta que todas estén bien):</div>
                                    <ul class="mb-0 ps-3"><li v-for="(e, i) in preview.errors" :key="i">{{ e }}</li></ul>
                                </div>
                            </div>
                            <div v-if="importErrors.length" class="col-12">
                                <div class="alert alert-danger small mb-0 py-2">
                                    <div class="fw-semibold mb-1">No se importó nada. Corrige y vuelve a subir:</div>
                                    <ul class="mb-0 ps-3"><li v-for="(e, i) in importErrors" :key="i">{{ e }}</li></ul>
                                </div>
                            </div>
                    </form>
                <template #footer>
                    <button type="button" class="btn btn-light border" @click="showImport = false">Cancelar</button>
                    <button type="submit" form="pc-import-form" class="btn btn-primary" :disabled="importForm.processing || previewing || !preview || preview.invalid > 0 || !preview.count">
                                <span v-if="importForm.processing" class="spinner-border spinner-border-sm me-1"></span>Procesar carga
                    </button>
                </template>
            </AppModal>

            <AppModal :show="showForm" :title="form.id ? 'Editar gasto' : 'Registrar gasto'" :subtitle="form.id ? `Recibo N.º ${String(form.receipt_number || '').padStart(5, '0')}` : 'El recibo correlativo se asigna al guardar.'" :busy="form.processing" @close="showForm = false">
                    <form id="pc-expense-form" class="row g-3" novalidate @submit.prevent="submit">
                            <div class="col-sm-6">
                                <label class="form-label" for="pc-date">Fecha *</label>
                                <input id="pc-date" v-model="form.date" type="date" :max="today" class="form-control" :class="{ 'is-invalid': form.errors.date }" />
                                <div class="invalid-feedback">{{ form.errors.date }}</div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="pc-amount">Monto (S/) *</label>
                                <input id="pc-amount" v-model="form.amount" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': form.errors.amount }" />
                                <div class="invalid-feedback">{{ form.errors.amount }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="pc-desc">Concepto *</label>
                                <input id="pc-desc" v-model="form.description" class="form-control" :class="{ 'is-invalid': form.errors.description }" maxlength="250" />
                                <div class="invalid-feedback">{{ form.errors.description }}</div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="pc-cat">Categoría</label>
                                <input id="pc-cat" v-model="form.category" class="form-control" list="pc-categories" maxlength="100" />
                                <datalist id="pc-categories"><option v-for="c in categories" :key="c" :value="c" /></datalist>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="pc-box">Caja *</label>
                                <select id="pc-box" v-model="form.cash_box" class="form-select"><option v-for="b in options.cash_boxes" :key="b" :value="b">{{ b }}</option></select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="pc-ben">Beneficiario</label>
                                <input id="pc-ben" v-model="form.beneficiary" class="form-control" maxlength="150" />
                            </div>
                            <div class="col-sm-5">
                                <label class="form-label" for="pc-doc">Comprobante *</label>
                                <select id="pc-doc" v-model="form.document_type" class="form-select"><option v-for="d in options.document_types" :key="d" :value="d">{{ d }}</option></select>
                            </div>
                            <div class="col-sm-7">
                                <label class="form-label" for="pc-docn">N.º comprobante</label>
                                <input id="pc-docn" v-model="form.document_number" class="form-control font-monospace" maxlength="50" />
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="pc-ruc">RUC del emisor</label>
                                <input id="pc-ruc" v-model.trim="form.ruc" class="form-control font-monospace" :class="{ 'is-invalid': form.errors.ruc }" inputmode="numeric" maxlength="11" />
                                <div class="invalid-feedback">{{ form.errors.ruc }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="pc-file">Foto o PDF del comprobante (máx. 5 MB){{ form.id ? ' — reemplaza el actual' : '' }}</label>
                                <input id="pc-file" ref="fileInput" type="file" accept=".pdf,image/*" class="form-control" :class="{ 'is-invalid': form.errors.voucher }" @input="form.voucher = $event.target.files[0]" />
                                <div class="invalid-feedback">{{ form.errors.voucher }}</div>
                            </div>
                    </form>
                <template #footer>
                    <button type="button" class="btn btn-light border" @click="showForm = false">Cancelar</button>
                    <button type="submit" form="pc-expense-form" class="btn btn-primary" :disabled="form.processing">
                                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>{{ form.id ? 'Guardar cambios' : 'Registrar gasto' }}
                    </button>
                </template>
            </AppModal>
    </AppLayout>
</template>
