<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

defineProps({ folders: Array, icons: Object });

const form = useForm({ id: null, name: '', description: '', icon: 'folder', color: '#6C4CE0', position: null });

function edit(f) {
    Object.assign(form, { id: f.id, name: f.name, description: f.description ?? '', icon: f.icon, color: f.color, position: f.position });
    form.clearErrors();
}
function reset() {
    form.reset();
    form.clearErrors();
}
function submit() {
    const options = { preserveScroll: true, onSuccess: reset };
    if (form.id) form.put(`/company-documents/folders/${form.id}`, options);
    else form.post('/company-documents/folders', options);
}
function destroy(f) {
    if (confirm(`¿Eliminar la carpeta "${f.name}"?`)) router.delete(`/company-documents/folders/${f.id}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Carpetas de documentos">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><Link href="/company-documents">Documentos de la empresa</Link></li>
                <li class="breadcrumb-item active" aria-current="page">Carpetas</li>
            </ol>
        </nav>
        <div class="page-header">
            <div>
                <h1>Carpetas de documentos</h1>
                <p>Organizan los documentos en la app (Políticas, Reglamentos, Manuales…). Crea las que tu empresa necesite.</p>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0">
                    <ul class="list-group list-group-flush">
                        <li v-for="f in folders" :key="f.id" class="list-group-item d-flex align-items-center gap-3 py-3">
                            <span class="kpi-icon" :style="{ background: `${f.color}22`, color: f.color }"><i class="bi" :class="`bi-${f.icon}`"></i></span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">{{ f.name }}</div>
                                <div class="small text-body-secondary">{{ f.description || 'Sin descripción' }} · {{ f.documents_count }} documento(s)</div>
                            </div>
                            <Link :href="`/company-documents?folder=${f.id}`" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> Ver</Link>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="edit(f)"><i class="bi bi-pencil"></i> Editar</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" :disabled="f.documents_count > 0" :title="f.documents_count > 0 ? 'Tiene documentos' : null" :aria-label="`Eliminar ${f.name}`" @click="destroy(f)"><i class="bi bi-trash"></i></button>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="col-lg-5">
                <form class="card border-0" @submit.prevent="submit">
                    <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">{{ form.id ? 'Editar carpeta' : 'Nueva carpeta' }}</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="f_name" class="form-label">Nombre</label>
                            <input id="f_name" v-model="form.name" class="form-control" :class="{ 'is-invalid': form.errors.name }" maxlength="100" required>
                            <div class="invalid-feedback">{{ form.errors.name }}</div>
                        </div>
                        <div class="mb-3">
                            <label for="f_desc" class="form-label">Descripción</label>
                            <input id="f_desc" v-model="form.description" class="form-control" maxlength="255">
                        </div>
                        <div class="mb-3">
                            <span class="form-label d-block">Ícono</span>
                            <div class="d-flex flex-wrap gap-2" role="radiogroup" aria-label="Ícono">
                                <button v-for="(label, key) in icons" :key="key" type="button" class="btn btn-sm" :class="form.icon === key ? 'btn-primary' : 'btn-outline-secondary'" role="radio" :aria-checked="form.icon === key" :aria-label="label" :title="label" @click="form.icon = key">
                                    <i class="bi" :class="`bi-${key}`"></i>
                                </button>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <label for="f_color" class="form-label">Color</label>
                                <input id="f_color" v-model="form.color" type="color" class="form-control form-control-color w-100">
                            </div>
                            <div class="col-6">
                                <label for="f_pos" class="form-label">Posición</label>
                                <input id="f_pos" v-model="form.position" type="number" min="0" max="999" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-0 d-flex justify-content-end gap-2">
                        <button v-if="form.id" type="button" class="btn btn-outline-secondary" @click="reset">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.id ? 'Guardar' : 'Crear carpeta' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
