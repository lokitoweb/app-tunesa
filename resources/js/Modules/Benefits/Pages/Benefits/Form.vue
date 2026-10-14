<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AudiencePicker from '@/Modules/Core/Components/AudiencePicker.vue';

const props = defineProps({ benefit: Object, categories: Array, audience: Object });

const editing = props.benefit.id !== null;
const form = useForm({ ...props.benefit });
const err = (key) => form.errors[key];

function onImage(e) {
    form.image = e.target.files[0] ?? null;
    form.remove_image = false;
}
function submit(status) {
    if (status) form.status = status;
    const options = { forceFormData: true, preserveScroll: true };
    if (editing) {
        form.transform((data) => ({ ...data, _method: 'put' })).post(`/benefits/${props.benefit.id}`, options);
    } else {
        form.post('/benefits', options);
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar beneficio' : 'Nuevo beneficio'">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><Link href="/benefits">Beneficios</Link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ editing ? 'Editar' : 'Nuevo' }}</li>
            </ol>
        </nav>
        <div class="page-header">
            <div>
                <h1>{{ editing ? `Editar: ${benefit.title}` : 'Nuevo beneficio' }}</h1>
                <p>Describe el beneficio, cómo se usa y a quién se le muestra. Al publicarlo, su público recibe un aviso en la app.</p>
            </div>
        </div>

        <form @submit.prevent="submit()">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">1. El beneficio</h2></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label for="title" class="form-label">Título <span class="text-danger">*</span></label>
                                    <input id="title" v-model="form.title" class="form-control" :class="{ 'is-invalid': err('title') }" maxlength="150" required placeholder="Ej.: Descuento en Smart Fit">
                                    <div class="invalid-feedback">{{ err('title') }}</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="highlight" class="form-label">Destacado corto</label>
                                    <input id="highlight" v-model="form.highlight" class="form-control" maxlength="40" placeholder="Ej.: 20% dscto, 2x1" aria-describedby="highlight_help">
                                    <div id="highlight_help" class="form-text">Se ve grande en la tarjeta.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="partner" class="form-label">Empresa aliada / proveedor</label>
                                    <input id="partner" v-model="form.partner" class="form-control" maxlength="120" placeholder="Ej.: Clínica San Pablo">
                                </div>
                                <div class="col-md-6">
                                    <label for="category_id" class="form-label">Categoría</label>
                                    <select id="category_id" v-model="form.category_id" class="form-select" :class="{ 'is-invalid': err('category_id') }">
                                        <option :value="null">Sin categoría</option>
                                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="summary" class="form-label">Resumen</label>
                                    <input id="summary" v-model="form.summary" class="form-control" maxlength="200" placeholder="Una frase que explique el beneficio">
                                </div>
                                <div class="col-12">
                                    <label for="description" class="form-label">Descripción</label>
                                    <textarea id="description" v-model="form.description" class="form-control" rows="4" maxlength="10000"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">2. ¿Cómo se usa?</h2></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="promo_code" class="form-label">Código promocional</label>
                                    <input id="promo_code" v-model="form.promo_code" class="form-control font-monospace" maxlength="60" placeholder="Ej.: TUNESA20">
                                </div>
                                <div class="col-md-6">
                                    <label for="url" class="form-label">Enlace</label>
                                    <input id="url" v-model="form.url" type="url" class="form-control" :class="{ 'is-invalid': err('url') }" maxlength="500" placeholder="https://…">
                                    <div class="invalid-feedback">{{ err('url') }}</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="contact" class="form-label">Contacto</label>
                                    <input id="contact" v-model="form.contact" class="form-control" maxlength="150" placeholder="Teléfono o correo">
                                </div>
                                <div class="col-md-6">
                                    <label for="location" class="form-label">Dirección / lugar</label>
                                    <input id="location" v-model="form.location" class="form-control" maxlength="200">
                                </div>
                                <div class="col-12">
                                    <label for="how_to_use" class="form-label">Pasos para usarlo</label>
                                    <textarea id="how_to_use" v-model="form.how_to_use" class="form-control" rows="3" maxlength="5000" placeholder="Ej.: Muestra tu fotocheck en caja y menciona el código."></textarea>
                                </div>
                                <div class="col-12">
                                    <label for="terms" class="form-label">Términos y condiciones</label>
                                    <textarea id="terms" v-model="form.terms" class="form-control" rows="3" maxlength="5000"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">3. ¿A quién se le muestra?</h2></div>
                        <div class="card-body">
                            <AudiencePicker v-model="form.targets" :options="audience" :errors="form.errors" id-prefix="benefit" />
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Imagen</h2></div>
                        <div class="card-body">
                            <img v-if="benefit.image_url && !form.remove_image && !form.image" :src="benefit.image_url" alt="Imagen actual" class="img-fluid rounded-3 mb-2">
                            <label for="image" class="form-label small">JPG, PNG o WebP (máx. 4 MB). Horizontal se ve mejor.</label>
                            <input id="image" type="file" accept="image/jpeg,image/png,image/webp" class="form-control" :class="{ 'is-invalid': err('image') }" @input="onImage">
                            <div class="invalid-feedback">{{ err('image') }}</div>
                            <div v-if="benefit.image_url" class="form-check mt-2">
                                <input id="remove_image" v-model="form.remove_image" class="form-check-input" type="checkbox">
                                <label for="remove_image" class="form-check-label small">Quitar la imagen</label>
                            </div>
                        </div>
                    </div>
                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Vigencia</h2></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="valid_from" class="form-label">Desde</label>
                                <input id="valid_from" v-model="form.valid_from" type="date" class="form-control" :class="{ 'is-invalid': err('valid_from') }">
                            </div>
                            <div class="mb-3">
                                <label for="valid_until" class="form-label">Hasta</label>
                                <input id="valid_until" v-model="form.valid_until" type="date" class="form-control" :class="{ 'is-invalid': err('valid_until') }" aria-describedby="valid_help">
                                <div class="invalid-feedback">{{ err('valid_until') }}</div>
                                <div id="valid_help" class="form-text">Vacío: sin vencimiento. Al vencer deja de verse en la app.</div>
                            </div>
                            <div class="form-check form-switch">
                                <input id="is_featured" v-model="form.is_featured" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="is_featured">Destacado (aparece primero)</label>
                            </div>
                            <div v-if="benefit.status !== 'published'" class="form-check form-switch mt-2">
                                <input id="notify" v-model="form.notify" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="notify">Avisar por la app al publicar</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-end gap-2 mb-5">
                <Link href="/benefits" class="btn btn-outline-secondary">Cancelar</Link>
                <button type="button" class="btn btn-outline-primary" :disabled="form.processing" @click="submit(benefit.status === 'published' ? 'published' : 'draft')">
                    {{ benefit.status === 'published' ? 'Guardar cambios' : 'Guardar borrador' }}
                </button>
                <button v-if="benefit.status !== 'published'" type="button" class="btn btn-primary" :disabled="form.processing" @click="submit('published')"><i class="bi bi-send me-1"></i>Guardar y publicar</button>
            </div>
        </form>
    </AppLayout>
</template>
