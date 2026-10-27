<script setup>
import { ref, onMounted } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
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
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Proveedores</h1>
                <p class="text-body-secondary small mb-0">Proveedores de la empresa con sus cuentas bancarias para la programación de pagos.</p>
            </div>
            <button v-if="can_manage" type="button" class="btn btn-primary" @click="openCreate">
                <i class="bi bi-plus-lg me-1"></i>Nuevo proveedor
            </button>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap gap-2">
                <div class="input-group flex-grow-1" style="max-width: 420px">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input v-model="search" type="search" class="form-control" placeholder="Buscar por razón social, RUC o DNI" aria-label="Buscar proveedor" @input="applyFilters" />
                </div>
                <select v-model="status" class="form-select w-auto" aria-label="Estado" @change="applyFilters">
                    <option value="">Todos</option>
                    <option value="active">Activos</option>
                    <option value="inactive">Inactivos</option>
                </select>
            </div>
        </div>

        <div class="row g-3">
            <div :class="showForm ? 'col-xl-7' : 'col-12'">
                <div class="card shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Razón social</th>
                                    <th>RUC / DNI</th>
                                    <th class="d-none d-md-table-cell">Contacto</th>
                                    <th class="d-none d-lg-table-cell">Cuentas</th>
                                    <th>Estado</th>
                                    <th v-if="can_manage" class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in suppliers.data" :key="s.id" :class="{ 'table-primary': form.id === s.id && showForm }">
                                    <td>
                                        <div class="fw-semibold">{{ s.name }}</div>
                                        <div class="small text-body-secondary">{{ s.category || '—' }}<span v-if="s.city"> · {{ s.city }}</span></div>
                                    </td>
                                    <td class="font-monospace small">{{ s.ruc || s.dni || '—' }}</td>
                                    <td class="d-none d-md-table-cell small">
                                        <div>{{ s.contact || '—' }}</div>
                                        <div class="text-body-secondary">{{ s.phone }}<span v-if="s.phone && s.email"> · </span>{{ s.email }}</div>
                                    </td>
                                    <td class="d-none d-lg-table-cell small">
                                        <div v-for="(a, i) in s.bank_accounts" :key="i">{{ a.bank }} · <span class="font-monospace">{{ a.account_number }}</span></div>
                                        <span v-if="!s.bank_accounts.length" class="text-body-secondary">Sin cuentas</span>
                                    </td>
                                    <td>
                                        <span class="badge" :class="s.is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'">
                                            {{ s.is_active ? 'Activo' : 'Inactivo' }}
                                        </span>
                                    </td>
                                    <td v-if="can_manage" class="text-end text-nowrap">
                                        <button type="button" class="btn-table-action btn-action-edit" title="Editar proveedor" @click="openEdit(s)">
                                            <i class="bi bi-pencil-fill"></i>
                                        </button>
                                        <button type="button" class="btn-table-action btn-table-delete" title="Eliminar proveedor" @click="destroy(s)">
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!suppliers.data.length">
                                    <td :colspan="can_manage ? 6 : 5" class="text-center text-body-secondary py-5">
                                        <i class="bi bi-truck fs-2 d-block mb-2"></i>
                                        {{ filters.search || filters.status ? 'Ningún proveedor coincide con el filtro.' : 'Aún no hay proveedores registrados.' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                        <span class="small text-body-secondary">{{ suppliers.total }} proveedor(es)</span>
                        <Pagination :paginator="suppliers" />
                    </div>
                </div>
            </div>

            <div v-if="showForm" class="col-xl-5">
                <form class="card shadow-sm" novalidate @submit.prevent="submit">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="h6 fw-bold mb-0">{{ form.id ? 'Editar proveedor' : 'Nuevo proveedor' }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="closeForm"></button>
                    </div>
                    <div class="card-body row g-3">
                        <div class="col-sm-7">
                            <label class="form-label small fw-semibold" for="sup-ruc">RUC</label>
                            <div class="input-group">
                                <input id="sup-ruc" v-model.trim="form.ruc" class="form-control font-monospace" :class="{ 'is-invalid': form.errors.ruc }" inputmode="numeric" maxlength="11" />
                                <button v-if="ruc_lookup" type="button" class="btn btn-outline-primary" :disabled="lookup.loading" title="Consultar en SUNAT" aria-label="Consultar RUC en SUNAT" @click="lookupDoc('ruc')">
                                    <span v-if="lookup.loading && lookup.kind === 'ruc'" class="spinner-border spinner-border-sm"></span>
                                    <i v-else class="bi bi-cloud-download"></i>
                                </button>
                                <div class="invalid-feedback">{{ form.errors.ruc }}</div>
                            </div>
                            <div v-if="lookup.message && lookup.kind === 'ruc'" class="small mt-1" :class="lookup.ok ? 'text-success' : 'text-danger'">{{ lookup.message }}</div>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small fw-semibold" for="sup-dni">DNI</label>
                            <div class="input-group">
                                <input id="sup-dni" v-model.trim="form.dni" class="form-control font-monospace" :class="{ 'is-invalid': form.errors.dni }" inputmode="numeric" maxlength="8" />
                                <button v-if="ruc_lookup" type="button" class="btn btn-outline-primary" :disabled="lookup.loading" title="Consultar en RENIEC" aria-label="Consultar DNI en RENIEC" @click="lookupDoc('dni')">
                                    <span v-if="lookup.loading && lookup.kind === 'dni'" class="spinner-border spinner-border-sm"></span>
                                    <i v-else class="bi bi-cloud-download"></i>
                                </button>
                                <div class="invalid-feedback">{{ form.errors.dni }}</div>
                            </div>
                            <div v-if="lookup.message && lookup.kind === 'dni'" class="small mt-1" :class="lookup.ok ? 'text-success' : 'text-danger'">{{ lookup.message }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="sup-name">Razón social *</label>
                            <input id="sup-name" v-model="form.name" class="form-control" :class="{ 'is-invalid': form.errors.name }" maxlength="250" required />
                            <div class="invalid-feedback">{{ form.errors.name }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="sup-address">Dirección</label>
                            <input id="sup-address" v-model="form.address" class="form-control" :class="{ 'is-invalid': form.errors.address }" maxlength="100" />
                            <div class="invalid-feedback">{{ form.errors.address }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="sup-city">Ciudad</label>
                            <input id="sup-city" v-model="form.city" class="form-control" maxlength="50" />
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="sup-category">Rubro</label>
                            <input id="sup-category" v-model="form.category" class="form-control" list="sup-categories" maxlength="100" />
                            <datalist id="sup-categories"><option v-for="c in categories" :key="c" :value="c" /></datalist>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="sup-contact">Contacto</label>
                            <input id="sup-contact" v-model="form.contact" class="form-control" maxlength="50" />
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="sup-phone">Teléfono</label>
                            <input id="sup-phone" v-model="form.phone" class="form-control" type="tel" maxlength="50" />
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="sup-email">Correo</label>
                            <input id="sup-email" v-model.trim="form.email" class="form-control" :class="{ 'is-invalid': form.errors.email }" type="email" maxlength="100" />
                            <div class="invalid-feedback">{{ form.errors.email }}</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input id="sup-active" v-model="form.is_active" class="form-check-input" type="checkbox" role="switch" />
                                <label class="form-check-label small" for="sup-active">Proveedor activo</label>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small fw-semibold">Cuentas bancarias</span>
                                <button type="button" class="btn btn-sm btn-outline-primary" @click="addAccount"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
                            </div>
                            <div v-for="(a, i) in form.bank_accounts" :key="i" class="row g-2 mb-2 align-items-start">
                                <div class="col-4">
                                    <input v-model="a.bank" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors[`bank_accounts.${i}.bank`] }" placeholder="Banco" aria-label="Banco" maxlength="50" />
                                    <div class="invalid-feedback">{{ form.errors[`bank_accounts.${i}.bank`] }}</div>
                                </div>
                                <div class="col-5">
                                    <input v-model.trim="a.account_number" class="form-control form-control-sm font-monospace" :class="{ 'is-invalid': form.errors[`bank_accounts.${i}.account_number`] }" placeholder="N.º de cuenta / CCI" aria-label="Número de cuenta" maxlength="100" />
                                    <div class="invalid-feedback">{{ form.errors[`bank_accounts.${i}.account_number`] }}</div>
                                </div>
                                <div class="col-2">
                                    <input v-model="a.account_type" class="form-control form-control-sm" placeholder="Tipo" aria-label="Tipo de cuenta" maxlength="50" />
                                </div>
                                <div class="col-1 text-end">
                                    <button type="button" class="btn btn-sm btn-link text-danger p-1" title="Quitar cuenta" @click="form.bank_accounts.splice(i, 1)"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                            <p v-if="!form.bank_accounts.length" class="small text-body-secondary mb-0">Sin cuentas registradas.</p>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" @click="closeForm">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                            {{ form.id ? 'Guardar cambios' : 'Registrar proveedor' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
