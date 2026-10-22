<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AudiencePicker from '@/Modules/Core/Components/AudiencePicker.vue';

const props = defineProps({
    document: {
        type: Object,
        required: true,
    },
    folders: {
        type: Array,
        default: () => [],
    },
    audience: {
        type: Object,
        default: () => ({}),
    },
});

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
        <div class="company-docs-page-wrapper">
            <!-- Cabecera Ejecutiva -->
            <div class="company-docs-header-card p-4 mb-4 shadow-sm">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <Link href="/company-documents" class="btn-executive-back" title="Volver a documentos">
                            <i class="bi bi-arrow-left"></i>
                        </Link>
                        <div class="company-docs-header-icon shadow-sm">
                            <i class="bi bi-file-earmark-arrow-up-fill"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <h1 class="h4 fw-bold mb-0 text-body">
                                    {{ editing ? `Editar: ${document.title}` : 'Subir Documento Institucional' }}
                                </h1>
                            </div>
                            <p class="text-body-secondary small mb-0">
                                El documento en formato PDF estará disponible para lectura en la app móvil del personal designado.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <form @submit.prevent="submit()">
                <div class="row g-4">
                    <!-- Columna Izquierda: Formulario y Audiencia -->
                    <div class="col-lg-8">
                        <div class="company-docs-section-card p-4 mb-4 shadow-sm">
                            <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                                <span class="badge bg-primary-subtle text-primary rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">
                                    1
                                </span>
                                <h2 class="h6 fw-bold mb-0 text-body">Información del Documento</h2>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="modern-field-label" for="title">
                                        Título del documento <span class="text-danger">*</span>
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-file-earmark-text-fill"></i></span>
                                        <input
                                            id="title"
                                            v-model="form.title"
                                            type="text"
                                            class="modern-input-control"
                                            :class="{ 'is-invalid': err('title') }"
                                            maxlength="200"
                                            required
                                            placeholder="Ej.: Reglamento Interno de Seguridad y Salud"
                                        >
                                    </div>
                                    <div v-if="err('title')" class="invalid-feedback d-block">{{ err('title') }}</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="modern-field-label" for="version">Versión / Año</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-tag-fill"></i></span>
                                        <input
                                            id="version"
                                            v-model="form.version"
                                            type="text"
                                            class="modern-input-control"
                                            maxlength="30"
                                            placeholder="Ej.: v2.0 - 2026"
                                        >
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="modern-field-label mb-0" for="folder_id">
                                            Carpeta destino <span class="text-danger">*</span>
                                        </label>
                                        <Link href="/company-documents/folders" class="small text-primary fw-medium text-decoration-none">
                                            <i class="bi bi-plus-circle me-1"></i>Nueva carpeta
                                        </Link>
                                    </div>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-folder-fill text-warning"></i></span>
                                        <select
                                            id="folder_id"
                                            v-model="form.folder_id"
                                            class="modern-input-control"
                                            :class="{ 'is-invalid': err('folder_id') }"
                                            required
                                        >
                                            <option :value="null" disabled>Elige una carpeta</option>
                                            <option v-for="f in folders" :key="f.id" :value="f.id">{{ f.name }}</option>
                                        </select>
                                    </div>
                                    <div v-if="err('folder_id')" class="invalid-feedback d-block">{{ err('folder_id') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="modern-field-label" for="file">
                                        {{ document.file_name ? 'Reemplazar archivo PDF' : 'Archivo PDF' }}
                                        <span v-if="!editing" class="text-danger">*</span>
                                    </label>
                                    <input
                                        id="file"
                                        type="file"
                                        accept="application/pdf"
                                        class="form-control"
                                        :class="{ 'is-invalid': err('file') }"
                                        style="border-radius: 8px;"
                                        @input="form.file = $event.target.files[0]"
                                    >
                                    <div v-if="err('file')" class="invalid-feedback d-block">{{ err('file') }}</div>
                                    <div class="text-body-secondary small mt-1" style="font-size: 0.725rem;">
                                        Formato PDF (máximo 20 MB).
                                        <span v-if="document.file_name">
                                            Actual: <a :href="`/company-documents/${document.id}/file`" target="_blank" rel="noopener" class="text-primary fw-semibold">{{ document.file_name }}</a>
                                        </span>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="modern-field-label" for="description">Descripción / Alcance</label>
                                    <textarea
                                        id="description"
                                        v-model="form.description"
                                        class="form-control"
                                        rows="6"
                                        maxlength="5000"
                                        style="border-radius: 8px; font-size: 0.875rem; min-height: 150px; padding: 12px 14px; line-height: 1.55; resize: vertical;"
                                        placeholder="Breve resumen del objetivo del documento y a quién aplica..."
                                    ></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Público Destinatario -->
                        <div class="company-docs-section-card p-4 mb-4 shadow-sm">
                            <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                                <span class="badge bg-primary-subtle text-primary rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">
                                    2
                                </span>
                                <h2 class="h6 fw-bold mb-0 text-body">Público Asignado en la App</h2>
                            </div>
                            <AudiencePicker v-model="form.targets" :options="audience" :errors="form.errors" id-prefix="doc" />
                        </div>
                    </div>

                    <!-- Columna Derecha: Confirmación y Notificación -->
                    <div class="col-lg-4">
                        <div class="company-docs-section-card p-4 mb-4 shadow-sm">
                            <h2 class="h6 fw-bold mb-3 text-body d-flex align-items-center">
                                <i class="bi bi-shield-check me-2 text-primary"></i>Conformidad y Auditoría
                            </h2>

                            <div class="form-check form-switch mb-3">
                                <input
                                    id="requires_acknowledgement"
                                    v-model="form.requires_acknowledgement"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                >
                                <label class="form-check-label fw-semibold text-body ms-2" for="requires_acknowledgement">
                                    Pedir "Leído y Conforme"
                                    <div class="text-body-secondary small fw-normal">
                                        Exige que el colaborador acepte formalmente el contenido del documento.
                                    </div>
                                </label>
                            </div>

                            <div v-if="form.requires_acknowledgement" class="mb-3 p-3 rounded-3 bg-surface-elevated border">
                                <label class="modern-field-label" for="acknowledge_by">Fecha límite de confirmación</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon"><i class="bi bi-calendar-check"></i></span>
                                    <input
                                        id="acknowledge_by"
                                        v-model="form.acknowledge_by"
                                        type="date"
                                        class="modern-input-control"
                                        :class="{ 'is-invalid': err('acknowledge_by') }"
                                    >
                                </div>
                                <div v-if="err('acknowledge_by')" class="invalid-feedback d-block">{{ err('acknowledge_by') }}</div>
                                <div class="text-body-secondary small mt-1" style="font-size: 0.72rem;">
                                    Opcional. Se mostrará como fecha sugerida en la app.
                                </div>
                            </div>

                            <hr class="my-3 opacity-25">

                            <div v-if="document.status !== 'published'" class="form-check form-switch">
                                <input
                                    id="notify"
                                    v-model="form.notify"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                >
                                <label class="form-check-label fw-semibold text-body ms-2" for="notify">
                                    <i class="bi bi-bell-fill text-primary me-1"></i>Avisar por la app al publicar
                                    <div class="text-body-secondary small fw-normal">
                                        Envía una notificación push inmediata a los colaboradores designados.
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Botones de Acción -->
                <div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-5 pt-3 border-top">
                    <Link href="/company-documents" class="btn btn-outline-secondary px-4 py-2 fw-semibold">
                        Cancelar
                    </Link>
                    <button
                        type="button"
                        class="btn btn-outline-primary px-4 py-2 fw-semibold"
                        :disabled="form.processing"
                        @click="submit(document.status === 'published' ? 'published' : 'draft')"
                    >
                        <i class="bi bi-save me-1"></i>
                        {{ document.status === 'published' ? 'Guardar cambios' : 'Guardar borrador' }}
                    </button>
                    <button
                        v-if="document.status !== 'published'"
                        type="button"
                        class="btn btn-primary px-4 py-2 fw-semibold shadow-sm"
                        :disabled="form.processing"
                        @click="submit('published')"
                    >
                        <i class="bi bi-cloud-arrow-up-fill me-1"></i>Guardar y publicar
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
