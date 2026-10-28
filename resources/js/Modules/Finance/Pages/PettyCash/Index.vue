<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
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
    showImport.value = true;
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
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Caja chica</h1>
                <p class="text-body-secondary small mb-0">Gastos menores con recibo correlativo y su comprobante.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary" @click="openImport"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Importar Excel</button>
                <button type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Registrar gasto</button>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <input type="month" class="form-control w-auto" :value="filters.month" aria-label="Mes" @change="go({ month: $event.target.value })" />
                <select class="form-select w-auto" :value="filters.box || ''" aria-label="Caja" @change="go({ box: $event.target.value || undefined })">
                    <option value="">Todas las cajas</option>
                    <option v-for="b in options.cash_boxes" :key="b" :value="b">{{ b }}</option>
                </select>
                <div class="ms-auto text-end">
                    <div class="small text-body-secondary">Total del mes</div>
                    <div class="h5 fw-bold mb-0">{{ money(total) }}</div>
                </div>
            </div>
            <div v-if="Object.keys(by_box).length > 1" class="card-footer bg-transparent small d-flex flex-wrap gap-3">
                <span v-for="(t, b) in by_box" :key="b">{{ b }}: <span class="fw-semibold">{{ money(t) }}</span></span>
            </div>
        </div>

        <div class="row g-3">
            <div :class="showForm ? 'col-xl-7' : 'col-12'">
                <div class="card shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Recibo</th><th>Concepto</th><th class="d-none d-md-table-cell">Comprobante</th><th class="text-end">Monto</th><th class="text-end">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="e in expenses.data" :key="e.id" :class="{ 'table-primary': showForm && form.id === e.id }">
                                    <td class="text-nowrap"><span class="font-monospace fw-semibold">N.º {{ String(e.receipt_number).padStart(5, '0') }}</span><div class="small text-body-secondary">{{ e.date_label }}</div></td>
                                    <td style="max-width: 280px">
                                        <div class="text-truncate">{{ e.description }}</div>
                                        <div class="small text-body-secondary text-truncate">{{ e.category || '—' }}<span v-if="e.beneficiary"> · {{ e.beneficiary }}</span> · {{ e.cash_box }}</div>
                                    </td>
                                    <td class="d-none d-md-table-cell small">{{ e.document_type }} {{ e.document_number }}<div v-if="e.ruc" class="font-monospace text-body-secondary">{{ e.ruc }}</div></td>
                                    <td class="text-end fw-semibold text-nowrap">{{ money(e.amount) }}</td>
                                    <td class="text-end text-nowrap">
                                        <a v-if="e.has_voucher" :href="`/petty-cash/${e.id}/voucher`" target="_blank" rel="noopener" class="btn-table-action" title="Ver comprobante"><i class="bi bi-file-earmark-arrow-down"></i></a>
                                        <button type="button" class="btn-table-action btn-action-edit" title="Editar gasto" @click="openEdit(e)"><i class="bi bi-pencil-fill"></i></button>
                                        <button type="button" class="btn-table-action btn-table-delete" title="Eliminar gasto" @click="destroy(e)"><i class="bi bi-trash3-fill"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="!expenses.data.length"><td colspan="5" class="text-center text-body-secondary py-5"><i class="bi bi-wallet2 fs-2 d-block mb-2"></i>No hay gastos en este mes.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                        <span class="small text-body-secondary">{{ expenses.total }} gasto(s)</span>
                        <Pagination :paginator="expenses" />
                    </div>
                </div>
            </div>

            <!-- Importación: mismas reglas del registro manual; todo o nada -->
            <div v-if="showImport" class="col-xl-5">
                <form class="card shadow-sm" novalidate @submit.prevent="submitImport">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="h6 fw-bold mb-0">Importar gastos desde Excel</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="showImport = false"></button>
                    </div>
                    <div class="card-body row g-3">
                        <p class="small text-body-secondary mb-0">
                            Columnas: Fecha, Tipo de Documento, RUC/Proveedor, Descripción del gasto, Categoría/Centro de Costo, Moneda (soles), Monto Total y Número de Comprobante.
                            <a href="/petty-cash/template" class="text-nowrap"><i class="bi bi-download me-1"></i>Descargar plantilla</a>
                        </p>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pi-box">Caja *</label>
                            <select id="pi-box" v-model="importForm.cash_box" class="form-select"><option v-for="b in options.cash_boxes" :key="b" :value="b">{{ b }}</option></select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pi-file">Archivo (.xlsx, .xls o .csv, máx. 5 MB) *</label>
                            <input id="pi-file" ref="importInput" type="file" accept=".xlsx,.xls,.csv" class="form-control" :class="{ 'is-invalid': importForm.errors.file }" @input="importForm.file = $event.target.files[0]" />
                        </div>
                        <div v-if="importErrors.length" class="col-12">
                            <div class="alert alert-danger small mb-0 py-2">
                                <div class="fw-semibold mb-1">No se importó nada. Corrige y vuelve a subir:</div>
                                <ul class="mb-0 ps-3"><li v-for="(e, i) in importErrors" :key="i">{{ e }}</li></ul>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" @click="showImport = false">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="importForm.processing || !importForm.file">
                            <span v-if="importForm.processing" class="spinner-border spinner-border-sm me-1"></span>Importar
                        </button>
                    </div>
                </form>
            </div>

            <div v-if="showForm" class="col-xl-5">
                <form class="card shadow-sm" novalidate @submit.prevent="submit">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="h6 fw-bold mb-0">{{ form.id ? 'Editar gasto' : 'Registrar gasto' }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="showForm = false"></button>
                    </div>
                    <div class="card-body row g-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pc-date">Fecha *</label>
                            <input id="pc-date" v-model="form.date" type="date" :max="today" class="form-control" :class="{ 'is-invalid': form.errors.date }" />
                            <div class="invalid-feedback">{{ form.errors.date }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pc-amount">Monto (S/) *</label>
                            <input id="pc-amount" v-model="form.amount" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': form.errors.amount }" />
                            <div class="invalid-feedback">{{ form.errors.amount }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pc-desc">Concepto *</label>
                            <input id="pc-desc" v-model="form.description" class="form-control" :class="{ 'is-invalid': form.errors.description }" maxlength="250" />
                            <div class="invalid-feedback">{{ form.errors.description }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pc-cat">Categoría</label>
                            <input id="pc-cat" v-model="form.category" class="form-control" list="pc-categories" maxlength="100" />
                            <datalist id="pc-categories"><option v-for="c in categories" :key="c" :value="c" /></datalist>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pc-box">Caja *</label>
                            <select id="pc-box" v-model="form.cash_box" class="form-select"><option v-for="b in options.cash_boxes" :key="b" :value="b">{{ b }}</option></select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pc-ben">Beneficiario</label>
                            <input id="pc-ben" v-model="form.beneficiary" class="form-control" maxlength="150" />
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small fw-semibold" for="pc-doc">Comprobante *</label>
                            <select id="pc-doc" v-model="form.document_type" class="form-select"><option v-for="d in options.document_types" :key="d" :value="d">{{ d }}</option></select>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label small fw-semibold" for="pc-docn">N.º comprobante</label>
                            <input id="pc-docn" v-model="form.document_number" class="form-control font-monospace" maxlength="50" />
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pc-ruc">RUC del emisor</label>
                            <input id="pc-ruc" v-model.trim="form.ruc" class="form-control font-monospace" :class="{ 'is-invalid': form.errors.ruc }" inputmode="numeric" maxlength="11" />
                            <div class="invalid-feedback">{{ form.errors.ruc }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pc-file">Foto o PDF del comprobante (máx. 5 MB){{ form.id ? ' — reemplaza el actual' : '' }}</label>
                            <input id="pc-file" ref="fileInput" type="file" accept=".pdf,image/*" class="form-control" :class="{ 'is-invalid': form.errors.voucher }" @input="form.voucher = $event.target.files[0]" />
                            <div class="invalid-feedback">{{ form.errors.voucher }}</div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" @click="showForm = false">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>{{ form.id ? 'Guardar cambios' : 'Registrar gasto' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
