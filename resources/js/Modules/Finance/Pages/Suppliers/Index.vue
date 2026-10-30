<script setup>
import { ref, onMounted } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

const props = defineProps({
    suppliers: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can_manage: { type: Boolean, default: false },
    has_tenant: { type: Boolean, default: false },
    ruc_lookup: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
    banks: { type: Array, default: () => [] },
    account_types: { type: Array, default: () => [] },
});

const { ask } = useConfirm();
const { ensureTenant, consumeIntent } = useTenantPicker();

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');
const showForm = ref(false);
const lookup = ref({ loading: false, message: '', ok: false });

const blank = () => ({
    id: null, name: '', ruc: '', dni: '', address: '', phone: '', email: '', contact: '', city: '', category: '',
    is_active: true, bank_accounts: [],
});
const form = useForm(blank());

onMounted(() => {
    if (consumeIntent('proveedor')) openCreate();
});

let timer = null;
function applyFilters() {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/suppliers', { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true });
    }, 300);
}

function openCreate() {
    if (!ensureTenant({ intent: 'proveedor', message: 'El proveedor se registra en una empresa.' })) return;
    Object.assign(form, blank());
    form.clearErrors();
    lookup.value = { loading: false, message: '', ok: false };
    showForm.value = true;
}

function openEdit(s) {
    Object.assign(form, {
        ...blank(),
        ...Object.fromEntries(Object.entries(s).map(([k, v]) => [k, v ?? ''])),
        is_active: s.is_active,
        bank_accounts: s.bank_accounts.map((a) => ({ ...a, account_type: a.account_type ?? '' })),
    });
    form.clearErrors();
    lookup.value = { loading: false, message: '', ok: false };
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    form.clearErrors();
}

function addAccount() {
    form.bank_accounts.push({ bank: '', account_number: '', account_type: '' });
}

// Consulta por documento (Migo): RUC en SUNAT o DNI en RENIEC; completa nombre y dirección
const DOCS = {
    ruc: { digits: 11, label: 'RUC', source: 'SUNAT' },
    dni: { digits: 8, label: 'DNI', source: 'RENIEC' },
};

async function lookupDoc(kind) {
    const doc = DOCS[kind];
    if (!new RegExp(`^\\d{${doc.digits}}$`).test(form[kind])) {
        lookup.value = { loading: false, message: `El ${doc.label} tiene ${doc.digits} dígitos.`, ok: false, kind };
        return;
    }
    lookup.value = { loading: true, message: '', ok: false, kind };
    try {
        const res = await fetch(`/suppliers/${kind}/${form[kind]}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const body = await res.json().catch(() => ({}));
        if (res.ok && body.success) {
            form.name = body.data.name;
            if (body.data.address) form.address = body.data.address.slice(0, 100);
            if (body.data.city) form.city = body.data.city.slice(0, 50);
            lookup.value = { loading: false, message: `Datos de ${doc.source} cargados.`, ok: true, kind };
        } else {
            lookup.value = { loading: false, message: body.message || `No se pudo consultar el ${doc.label}.`, ok: false, kind };
        }
    } catch {
        lookup.value = { loading: false, message: 'Sin conexión con el servidor.', ok: false };
    }
}

function submit() {
    const options = { preserveScroll: true, onSuccess: closeForm };
    const data = (f) => ({ ...f, bank_accounts: f.bank_accounts.filter((a) => a.bank || a.account_number) });
    if (form.id) {
        form.transform(data).put(`/suppliers/${form.id}`, options);
    } else {
        form.transform(data).post('/suppliers', options);
    }
}

async function destroy(s) {
    const confirmed = await ask({
        title: '¿Eliminar proveedor?',
        message: `Se eliminará "${s.name}" y sus cuentas bancarias.`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });
    if (confirmed) router.delete(`/suppliers/${s.id}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Proveedores">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Proveedores</h1>
                <p class="page-subtitle">Proveedores de la empresa con sus cuentas bancarias para la programación de pagos.</p>
            </div>
            <div class="page-actions">
                <button v-if="can_manage" type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Nuevo proveedor</button>
            </div>
        </header>

        <section class="surface">
            <div class="filter-row">
                <div class="search-field">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input v-model="search" type="search" class="form-control" placeholder="Buscar por razón social, RUC o DNI" aria-label="Buscar proveedor" @input="applyFilters" />
                </div>
                <div class="seg-tabs" role="tablist" aria-label="Estado">
                    <button v-for="o in [['', 'Todos'], ['active', 'Activos'], ['inactive', 'Inactivos']]" :key="o[0]" type="button" role="tab" class="seg-tab" :class="{ 'is-active': status === o[0] }"
                        :aria-selected="status === o[0]" @click="status = o[0]; applyFilters()">{{ o[1] }}</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Razón social</th>
                            <th>RUC / DNI</th>
                            <th>Contacto</th>
                            <th>Cuentas</th>
                            <th>Estado</th>
                            <th v-if="can_manage" class="text-end"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in suppliers.data" :key="s.id" :class="{ 'is-muted': !s.is_active }">
                            <td data-label="Razón social" class="cell-main">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="list-plain-icon"><i class="bi bi-truck"></i></span>
                                    <div class="min-w-0">
                                        <div class="cell-title text-truncate">{{ s.name }}</div>
                                        <div class="cell-sub text-truncate">{{ s.category || '—' }}<span v-if="s.city"> · {{ s.city }}</span></div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="RUC / DNI" class="text-nowrap"><span class="font-monospace small">{{ s.ruc || s.dni || '—' }}</span></td>
                            <td data-label="Contacto">
                                <div class="cell-title fw-medium">{{ s.contact || '—' }}</div>
                                <div class="cell-sub">{{ s.phone }}<span v-if="s.phone && s.email"> · </span>{{ s.email }}</div>
                            </td>
                            <td data-label="Cuentas">
                                <div v-for="(a, i) in s.bank_accounts" :key="i" class="cell-sub text-nowrap"><span class="fw-semibold text-body">{{ a.bank }}</span> · <span class="font-monospace">{{ a.account_number }}</span></div>
                                <span v-if="!s.bank_accounts.length" class="status-pill tone-amber">Sin cuentas</span>
                            </td>
                            <td data-label="Estado"><span class="status-pill" :class="s.is_active ? 'tone-emerald' : 'tone-neutral'">{{ s.is_active ? 'Activo' : 'Inactivo' }}</span></td>
                            <td v-if="can_manage" class="cell-actions">
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Editar proveedor" aria-label="Editar proveedor" @click="openEdit(s)"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="icon-btn icon-btn-danger" title="Eliminar proveedor" aria-label="Eliminar proveedor" @click="destroy(s)"><i class="bi bi-trash3"></i></button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!suppliers.data.length" class="row-empty">
                            <td :colspan="can_manage ? 6 : 5">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-truck"></i></span>
                                    <p class="dash-empty-title">{{ filters.search || filters.status ? 'Ningún proveedor coincide con el filtro' : 'Aún no hay proveedores registrados' }}</p>
                                    <p class="dash-empty-text">{{ filters.search || filters.status ? 'Prueba con otra búsqueda o con «Todos».' : 'Registra el primero con «Nuevo proveedor»: con el RUC se completan sus datos.' }}</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="surface-foot">
                <span class="small text-body-secondary">{{ suppliers.total }} proveedor(es)</span>
                <Pagination :paginator="suppliers" />
            </footer>
        </section>

        <AppModal :show="showForm" :title="form.id ? 'Editar proveedor' : 'Nuevo proveedor'" :subtitle="ruc_lookup ? 'Con el RUC o el DNI se completan la razón social y la dirección.' : ''" size="lg" :busy="form.processing" @close="closeForm">
            <form id="sup-form" class="row g-3" novalidate @submit.prevent="submit">
                <div class="col-sm-7">
                    <label class="form-label" for="sup-ruc">RUC</label>
                    <div class="input-group has-validation">
                        <input id="sup-ruc" v-model.trim="form.ruc" class="form-control font-monospace" :class="{ 'is-invalid': form.errors.ruc }" inputmode="numeric" maxlength="11" />
                        <button v-if="ruc_lookup" type="button" class="btn btn-light border" :disabled="lookup.loading" title="Consultar en SUNAT" aria-label="Consultar RUC en SUNAT" @click="lookupDoc('ruc')">
                            <span v-if="lookup.loading && lookup.kind === 'ruc'" class="spinner-border spinner-border-sm"></span>
                            <template v-else><i class="bi bi-cloud-download me-1"></i>SUNAT</template>
                        </button>
                        <div class="invalid-feedback">{{ form.errors.ruc }}</div>
                    </div>
                    <div v-if="lookup.message && lookup.kind === 'ruc'" class="small mt-1" :class="lookup.ok ? 'text-success' : 'text-danger'">{{ lookup.message }}</div>
                </div>
                <div class="col-sm-5">
                    <label class="form-label" for="sup-dni">DNI</label>
                    <div class="input-group has-validation">
                        <input id="sup-dni" v-model.trim="form.dni" class="form-control font-monospace" :class="{ 'is-invalid': form.errors.dni }" inputmode="numeric" maxlength="8" />
                        <button v-if="ruc_lookup" type="button" class="btn btn-light border" :disabled="lookup.loading" title="Consultar en RENIEC" aria-label="Consultar DNI en RENIEC" @click="lookupDoc('dni')">
                            <span v-if="lookup.loading && lookup.kind === 'dni'" class="spinner-border spinner-border-sm"></span>
                            <template v-else><i class="bi bi-cloud-download me-1"></i>RENIEC</template>
                        </button>
                        <div class="invalid-feedback">{{ form.errors.dni }}</div>
                    </div>
                    <div v-if="lookup.message && lookup.kind === 'dni'" class="small mt-1" :class="lookup.ok ? 'text-success' : 'text-danger'">{{ lookup.message }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="sup-name">Razón social *</label>
                    <input id="sup-name" v-model="form.name" class="form-control" :class="{ 'is-invalid': form.errors.name }" maxlength="250" required />
                    <div class="invalid-feedback">{{ form.errors.name }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="sup-address">Dirección</label>
                    <input id="sup-address" v-model="form.address" class="form-control" :class="{ 'is-invalid': form.errors.address }" maxlength="100" />
                    <div class="invalid-feedback">{{ form.errors.address }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="sup-city">Ciudad</label>
                    <input id="sup-city" v-model="form.city" class="form-control" maxlength="50" />
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="sup-category">Rubro</label>
                    <input id="sup-category" v-model="form.category" class="form-control" list="sup-categories" maxlength="100" />
                    <datalist id="sup-categories"><option v-for="c in categories" :key="c" :value="c" /></datalist>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="sup-contact">Contacto</label>
                    <input id="sup-contact" v-model="form.contact" class="form-control" maxlength="50" />
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="sup-phone">Teléfono</label>
                    <input id="sup-phone" v-model="form.phone" class="form-control" type="tel" maxlength="50" />
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="sup-email">Correo</label>
                    <input id="sup-email" v-model.trim="form.email" class="form-control" :class="{ 'is-invalid': form.errors.email }" type="email" maxlength="100" />
                    <div class="invalid-feedback">{{ form.errors.email }}</div>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch mb-0">
                        <input id="sup-active" v-model="form.is_active" class="form-check-input" type="checkbox" role="switch" />
                        <label class="form-check-label small" for="sup-active">Proveedor activo</label>
                    </div>
                </div>

                <div class="col-12">
                    <div class="concept-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold small">Cuentas bancarias</span>
                            <button type="button" class="btn btn-sm btn-light border" @click="addAccount"><i class="bi bi-plus-lg me-1"></i>Agregar cuenta</button>
                        </div>
                        <div v-for="(a, i) in form.bank_accounts" :key="i" class="row g-2 mb-2 align-items-start">
                            <div class="col-sm-4">
                                <input v-model="a.bank" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors[`bank_accounts.${i}.bank`] }" placeholder="Banco" aria-label="Banco" maxlength="50" list="sup-banks" />
                                <div class="invalid-feedback">{{ form.errors[`bank_accounts.${i}.bank`] }}</div>
                            </div>
                            <div class="col-sm-4 col-7">
                                <input v-model.trim="a.account_number" class="form-control form-control-sm font-monospace" :class="{ 'is-invalid': form.errors[`bank_accounts.${i}.account_number`] }" placeholder="N.º de cuenta / CCI" aria-label="Número de cuenta" maxlength="100" />
                                <div class="invalid-feedback">{{ form.errors[`bank_accounts.${i}.account_number`] }}</div>
                            </div>
                            <div class="col-sm-3 col-4">
                                <select v-model="a.account_type" class="form-select form-select-sm" aria-label="Tipo de cuenta">
                                    <option value="">Tipo</option>
                                    <option v-for="t in account_types" :key="t" :value="t">{{ t }}</option>
                                    <!-- ponytail: conserva un tipo escrito a mano antes del catálogo -->
                                    <option v-if="a.account_type && !account_types.includes(a.account_type)" :value="a.account_type">{{ a.account_type }}</option>
                                </select>
                            </div>
                            <div class="col-1 text-end">
                                <button type="button" class="icon-btn icon-btn-danger" title="Quitar cuenta" aria-label="Quitar cuenta" @click="form.bank_accounts.splice(i, 1)"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </div>
                        <datalist id="sup-banks"><option v-for="b in banks" :key="b" :value="b" /></datalist>
                        <p v-if="!form.bank_accounts.length" class="cell-sub mb-0">Sin cuentas: agrega al menos una para poder pagarle.</p>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="closeForm">Cancelar</button>
                <button type="submit" form="sup-form" class="btn btn-primary" :disabled="form.processing">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>{{ form.id ? 'Guardar cambios' : 'Registrar proveedor' }}
                </button>
            </template>
        </AppModal>
    </AppLayout>
</template>
