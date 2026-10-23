<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({
    requisition: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    options: { type: Object, required: true },
});

const editing = !!props.requisition.id;
const form = useForm({
    requested_at: props.requisition.requested_at,
    detail: props.requisition.detail || '',
    area: props.requisition.area || '',
    requested_by: props.requisition.requested_by || '',
    email: props.requisition.email || '',
    classification: props.requisition.classification || '',
    priority: props.requisition.priority || 'NORMAL',
    plate: props.requisition.plate || '',
    km: props.requisition.km || '',
    agency: props.requisition.agency || '',
    site: props.requisition.site || '',
    delivery_place: props.requisition.delivery_place || '',
    items: props.requisition.items.length ? props.requisition.items : [{ product: '', unit: 'UNIDAD (BIENES)', quantity: 1, justification: '' }],
});

const unitByProduct = Object.fromEntries(props.products.map((p) => [p.name, p.unit]));

function addItem() {
    form.items.push({ product: '', unit: 'UNIDAD (BIENES)', quantity: 1, justification: '' });
}

// Un producto conocido trae su unidad
function onProduct(item) {
    if (unitByProduct[item.product]) item.unit = unitByProduct[item.product];
}

function submit() {
    if (editing) form.put(`/requisitions/${props.requisition.id}`);
    else form.post('/requisitions');
}

const err = (k) => form.errors[k];
</script>

<template>
    <AppLayout :title="editing ? `Editar ${requisition.code}` : 'Nuevo requerimiento'">
        <div class="d-flex align-items-center gap-3 mb-4">
            <Link :href="editing ? `/requisitions/${requisition.id}` : '/requisitions'" class="btn btn-light" title="Volver"><i class="bi bi-arrow-left"></i></Link>
            <div>
                <h1 class="h4 fw-bold mb-0">{{ editing ? `Editar ${requisition.code}` : 'Nuevo requerimiento' }}</h1>
                <p class="text-body-secondary small mb-0">Lo revisa gerencia; luego logística cotiza y emite la orden.</p>
            </div>
        </div>

        <form novalidate @submit.prevent="submit">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-transparent fw-semibold">Datos del requerimiento</div>
                <div class="card-body row g-3">
                    <div class="col-sm-4 col-lg-3">
                        <label class="form-label small fw-semibold" for="rq-date">Fecha *</label>
                        <input id="rq-date" v-model="form.requested_at" type="date" class="form-control" :class="{ 'is-invalid': err('requested_at') }" />
                        <div class="invalid-feedback">{{ err('requested_at') }}</div>
                    </div>
                    <div class="col-sm-8 col-lg-5">
                        <label class="form-label small fw-semibold" for="rq-class">Clasificación *</label>
                        <select id="rq-class" v-model="form.classification" class="form-select" :class="{ 'is-invalid': err('classification') }">
                            <option value="" disabled>Elige…</option>
                            <option v-for="c in options.classifications" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <div class="invalid-feedback">{{ err('classification') }}</div>
                    </div>
                    <div class="col-sm-4 col-lg-4">
                        <label class="form-label small fw-semibold" for="rq-priority">Prioridad *</label>
                        <select id="rq-priority" v-model="form.priority" class="form-select">
                            <option v-for="p in options.priorities" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold" for="rq-detail">Detalle / motivo *</label>
                        <textarea id="rq-detail" v-model="form.detail" rows="2" class="form-control" :class="{ 'is-invalid': err('detail') }" maxlength="1000"></textarea>
                        <div class="invalid-feedback">{{ err('detail') }}</div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <label class="form-label small fw-semibold" for="rq-by">Solicitado por *</label>
                        <input id="rq-by" v-model="form.requested_by" class="form-control" :class="{ 'is-invalid': err('requested_by') }" maxlength="50" />
                        <div class="invalid-feedback">{{ err('requested_by') }}</div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <label class="form-label small fw-semibold" for="rq-area">Área</label>
                        <input id="rq-area" v-model="form.area" class="form-control" maxlength="50" />
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <label class="form-label small fw-semibold" for="rq-email">Correo</label>
                        <input id="rq-email" v-model.trim="form.email" type="email" class="form-control" :class="{ 'is-invalid': err('email') }" maxlength="100" />
                        <div class="invalid-feedback">{{ err('email') }}</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label small fw-semibold" for="rq-plate">Placa</label>
                        <input id="rq-plate" v-model="form.plate" class="form-control text-uppercase" maxlength="50" />
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label small fw-semibold" for="rq-km">Kilometraje</label>
                        <input id="rq-km" v-model="form.km" class="form-control" maxlength="50" />
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label small fw-semibold" for="rq-site">Sede / agencia</label>
                        <input id="rq-site" v-model="form.site" class="form-control" maxlength="100" />
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label small fw-semibold" for="rq-delivery">Lugar de entrega</label>
                        <input id="rq-delivery" v-model="form.delivery_place" class="form-control" maxlength="100" />
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Productos o servicios</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" @click="addItem"><i class="bi bi-plus-lg me-1"></i>Agregar línea</button>
                </div>
                <div class="card-body">
                    <div v-if="err('items')" class="alert alert-danger py-2 small">{{ err('items') }}</div>
                    <datalist id="rq-products"><option v-for="p in products" :key="p.name" :value="p.name" /></datalist>
                    <div v-for="(item, i) in form.items" :key="i" class="row g-2 align-items-start mb-2 pb-2 border-bottom">
                        <div class="col-md-5">
                            <input v-model="item.product" list="rq-products" class="form-control form-control-sm" :class="{ 'is-invalid': err(`items.${i}.product`) }" placeholder="Producto o servicio" aria-label="Producto" maxlength="100" @change="onProduct(item)" />
                            <div class="invalid-feedback">{{ err(`items.${i}.product`) }}</div>
                        </div>
                        <div class="col-4 col-md-2">
                            <input v-model="item.quantity" type="number" min="0" step="any" class="form-control form-control-sm" :class="{ 'is-invalid': err(`items.${i}.quantity`) }" aria-label="Cantidad" />
                            <div class="invalid-feedback">{{ err(`items.${i}.quantity`) }}</div>
                        </div>
                        <div class="col-8 col-md-2">
                            <select v-model="item.unit" class="form-select form-select-sm" aria-label="Unidad">
                                <option v-for="u in options.units" :key="u" :value="u">{{ u }}</option>
                            </select>
                        </div>
                        <div class="col-10 col-md-2">
                            <input v-model="item.justification" class="form-control form-control-sm" placeholder="Justificación" aria-label="Justificación" maxlength="100" />
                        </div>
                        <div class="col-2 col-md-1 text-end">
                            <button type="button" class="btn btn-sm btn-link text-danger" title="Quitar línea" :disabled="form.items.length === 1" @click="form.items.splice(i, 1)">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <Link :href="editing ? `/requisitions/${requisition.id}` : '/requisitions'" class="btn btn-light">Cancelar</Link>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                    {{ editing ? 'Guardar cambios' : 'Registrar requerimiento' }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
