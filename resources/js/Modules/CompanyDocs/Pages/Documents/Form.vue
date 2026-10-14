<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AudiencePicker from '@/Modules/Core/Components/AudiencePicker.vue';

const props = defineProps({ document: Object, folders: Array, audience: Object });

const editing = props.document.id !== null;
const form = useForm({ ...props.document });
const err = (key) => form.errors[key];

function submit(status) {
    if (status) form.status = status;
    const options = { forceFormData: true, preserveScroll: true };
    if (editing) {
        form.transform((data) => ({ ...data, _method: 'put' })).post(`/company-documents/${props.document.id}`, options);
    } else {
        form.post('/company-documents', options);
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar documento' : 'Subir documento'">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><Link href="/company-documents">Documentos de la empresa</Link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ editing ? 'Editar' : 'Subir' }}</li>
            </ol>
        </nav>
        <div class="page-header">
            <div>
                <h1>{{ editing ? `Editar: ${document.title}` : 'Subir documento' }}</h1>
                <p>El PDF se lee dentro de la app (también se puede descargar). Al publicarlo, su público recibe un aviso.</p>
            </div>
        </div>

        <form @submit.prevent="submit()">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">1. Documento</h2></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label for="title" class="form-label">Título <span class="text-danger">*</span></label>
                                    <input id="title" v-model="form.title" class="form-control" :class="{ 'is-invalid': err('title') }" maxlength="200" required placeholder="Ej.: Política de Calidad">
                                    <div class="invalid-feedback">{{ err('title') }}</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="version" class="form-label">Versión</label>
                                    <input id="version" v-model="form.version" class="form-control" maxlength="30" placeholder="Ej.: v3 · 2026">
                                </div>
                                <div class="col-md-6">
                                    <label for="folder_id" class="form-label">Carpeta <span class="text-danger">*</span></label>
                                    <select id="folder_id" v-model="form.folder_id" class="form-select" :class="{ 'is-invalid': err('folder_id') }" required>
                                        <option :value="null" disabled>Elige una carpeta</option>
                                        <option v-for="f in folders" :key="f.id" :value="f.id">{{ f.name }}</option>
                                    </select>
                                    <div class="invalid-feedback">{{ err('folder_id') }}</div>
                                    <Link href="/company-documents/folders" class="small">Crear otra carpeta</Link>
                                </div>
                                <div class="col-md-6">
                                    <label for="file" class="form-label">{{ document.file_name ? 'Reemplazar PDF' : 'Archivo PDF' }} <span v-if="!editing" class="text-danger">*</span></label>
                                    <input id="file" type="file" accept="application/pdf" class="form-control" :class="{ 'is-invalid': err('file') }" @input="form.file = $event.target.files[0]">
                                    <div class="invalid-feedback">{{ err('file') }}</div>
                                    <div class="form-text">Máx. 20 MB.<span v-if="document.file_name"> Actual: <a :href="`/company-documents/${document.id}/file`" target="_blank" rel="noopener">{{ document.file_name }}</a></span></div>
                                </div>
                                <div class="col-12">
                                    <label for="description" class="form-label">Descripción</label>
                                    <textarea id="description" v-model="form.description" class="form-control" rows="3" maxlength="5000" placeholder="De qué trata y a quién aplica"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">2. ¿A quién se le muestra?</h2></div>
                        <div class="card-body">
                            <AudiencePicker v-model="form.targets" :options="audience" :errors="form.errors" id-prefix="doc" />
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Confirmación de lectura</h2></div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-2">
                                <input id="requires_acknowledgement" v-model="form.requires_acknowledgement" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="requires_acknowledgement">Pedir "Leído y conforme"</label>
                            </div>
                            <p class="small text-body-secondary">Cada persona confirma en la app que leyó el documento. Queda la fecha y hora para auditorías (p. ej. RIT, Política de SST).</p>
                            <div v-if="form.requires_acknowledgement">
                                <label for="acknowledge_by" class="form-label">Fecha límite (opcional)</label>
                                <input id="acknowledge_by" v-model="form.acknowledge_by" type="date" class="form-control" :class="{ 'is-invalid': err('acknowledge_by') }">
                                <div class="invalid-feedback">{{ err('acknowledge_by') }}</div>
                            </div>
                            <div v-if="document.status !== 'published'" class="form-check form-switch mt-3">
                                <input id="notify" v-model="form.notify" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="notify">Avisar por la app al publicar</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-end gap-2 mb-5">
                <Link href="/company-documents" class="btn btn-outline-secondary">Cancelar</Link>
                <button type="button" class="btn btn-outline-primary" :disabled="form.processing" @click="submit(document.status === 'published' ? 'published' : 'draft')">
                    {{ document.status === 'published' ? 'Guardar cambios' : 'Guardar borrador' }}
                </button>
                <button v-if="document.status !== 'published'" type="button" class="btn btn-primary" :disabled="form.processing" @click="submit('published')"><i class="bi bi-send me-1"></i>Guardar y publicar</button>
            </div>
        </form>
    </AppLayout>
</template>
