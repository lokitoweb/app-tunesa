<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

defineProps({
    folders: {
        type: Array,
        default: () => [],
    },
    icons: {
        type: Object,
        default: () => ({}),
    },
});

const { ask } = useConfirm();

const form = useForm({
    id: null,
    name: '',
    description: '',
    icon: 'folder',
    color: '#0284c7',
    position: null,
});

function editFolder(f) {
    Object.assign(form, {
        id: f.id,
        name: f.name,
        description: f.description ?? '',
        icon: f.icon,
        color: f.color,
        position: f.position,
    });
    form.clearErrors();
}

function resetForm() {
    form.reset();
    form.id = null;
    form.clearErrors();
}

function submitFolder() {
    const options = { preserveScroll: true, onSuccess: resetForm };
    if (form.id) {
        form.put(`/company-documents/folders/${form.id}`, options);
    } else {
        form.post('/company-documents/folders', options);
    }
}

async function destroyFolder(f) {
    const confirmed = await ask({
        title: '¿Eliminar carpeta?',
        message: `¿Estás seguro de eliminar permanentemente la carpeta "${f.name}"?`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });

    if (confirmed) {
        router.delete(`/company-documents/folders/${f.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Carpetas de Documentos">
        <div class="company-docs-page-wrapper">
            <!-- Cabecera Ejecutiva -->
            <div class="company-docs-header-card p-4 mb-4 shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <Link href="/company-documents" class="btn-executive-back" title="Volver a documentos">
                        <i class="bi bi-arrow-left"></i>
                    </Link>
                    <div class="company-docs-header-icon shadow-sm">
                        <i class="bi bi-folder-fill"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h1 class="h4 fw-bold mb-0 text-body">Carpetas de Documentos</h1>
                        </div>
                        <p class="text-body-secondary small mb-0">
                            Organiza los documentos institucionales en categorías lógicas (Políticas, Reglamentos, Manuales, SST).
                        </p>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Columna Izquierda: Listado de Carpetas -->
                <div class="col-lg-7">
                    <div class="company-docs-section-card shadow-sm overflow-hidden">
                        <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                            <h2 class="h6 fw-bold mb-0 text-body d-flex align-items-center">
                                <i class="bi bi-folder-symlink-fill me-2 text-warning"></i>Carpetas Registradas
                            </h2>
                            <span class="badge bg-primary-subtle text-primary">{{ folders.length }} carpetas</span>
                        </div>

                        <ul class="list-group list-group-flush">
                            <li
                                v-for="f in folders"
                                :key="f.id"
                                class="list-group-item d-flex align-items-center gap-3 p-3 border-bottom"
                                :class="{ 'bg-primary-subtle': form.id === f.id }"
                            >
                                <span
                                    class="kpi-icon flex-shrink-0"
                                    :style="{ background: `${f.color}22`, color: f.color, width: '42px', height: '42px', borderRadius: '10px' }"
                                >
                                    <i class="bi fs-5" :class="`bi-${f.icon}`"></i>
                                </span>

                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-bold text-body mb-1 d-flex align-items-center gap-2">
                                        <span>{{ f.name }}</span>
                                        <span class="badge rounded-pill border small" :style="{ borderColor: `${f.color}40`, color: f.color }">
                                            Posición {{ f.position }}
                                        </span>
                                    </div>
                                    <div class="small text-body-secondary text-truncate">
                                        {{ f.description || 'Sin descripción' }} • {{ f.documents_count }} documento(s)
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-1">
                                    <Link
                                        :href="`/company-documents?folder=${f.id}`"
                                        class="btn-table-action btn-action-view"
                                        title="Ver documentos de esta carpeta"
                                    >
                                        <i class="bi bi-eye-fill"></i>
                                    </Link>
                                    <button
                                        type="button"
                                        class="btn-table-action btn-action-edit"
                                        title="Editar carpeta"
                                        @click="editFolder(f)"
                                    >
                                        <i class="bi bi-pencil-fill"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-table-action btn-table-delete"
                                        :disabled="f.documents_count > 0"
                                        :title="f.documents_count > 0 ? 'No se puede eliminar porque contiene documentos' : 'Eliminar carpeta'"
                                        @click="destroyFolder(f)"
                                    >
                                        <i class="bi bi-trash3-fill"></i>
                                    </button>
                                </div>
                            </li>

                            <li v-if="!folders.length" class="list-group-item p-5 text-center">
                                <i class="bi bi-folder2-open fs-1 text-body-secondary d-block mb-2"></i>
                                <span class="text-body-secondary small">Sin carpetas configuradas. Crea la primera a la derecha.</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Columna Derecha: Formulario Crear / Editar -->
                <div class="col-lg-5">
                    <form class="company-docs-section-card shadow-sm p-4" @submit.prevent="submitFolder">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <h2 class="h6 fw-bold mb-0 text-body d-flex align-items-center">
                                <i class="bi me-2 text-primary" :class="form.id ? 'bi-pencil-square' : 'bi-plus-circle-fill'"></i>
                                {{ form.id ? 'Editar Carpeta' : 'Nueva Carpeta' }}
                            </h2>
                            <button v-if="form.id" type="button" class="btn btn-sm btn-outline-secondary" @click="resetForm">
                                Nueva
                            </button>
                        </div>

                        <div class="mb-3">
                            <label class="modern-field-label" for="f_name">
                                Nombre de la carpeta <span class="text-danger">*</span>
                            </label>
                            <div class="modern-input-wrapper">
                                <span class="modern-input-icon"><i class="bi bi-folder-fill text-warning"></i></span>
                                <input
                                    id="f_name"
                                    v-model="form.name"
                                    type="text"
                                    class="modern-input-control"
                                    :class="{ 'is-invalid': form.errors.name }"
                                    maxlength="100"
                                    required
                                    placeholder="Ej.: Políticas y Reglamentos"
                                >
                            </div>
                            <div v-if="form.errors.name" class="invalid-feedback d-block">{{ form.errors.name }}</div>
                        </div>

                        <div class="mb-3">
                            <label class="modern-field-label" for="f_desc">Descripción</label>
                            <div class="modern-input-wrapper">
                                <span class="modern-input-icon"><i class="bi bi-card-text"></i></span>
                                <input
                                    id="f_desc"
                                    v-model="form.description"
                                    type="text"
                                    class="modern-input-control"
                                    maxlength="255"
                                    placeholder="Finalidad de los documentos que contendrá..."
                                >
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="modern-field-label">Ícono distintivo</label>
                            <div class="d-flex flex-wrap gap-2 p-2 border rounded-3 bg-surface-elevated" style="max-height: 160px; overflow-y: auto;">
                                <button
                                    v-for="(label, key) in icons"
                                    :key="key"
                                    type="button"
                                    class="btn btn-sm d-flex align-items-center justify-content-center"
                                    :class="form.icon === key ? 'btn-primary' : 'btn-outline-secondary'"
                                    style="width: 38px; height: 38px; border-radius: 8px;"
                                    :title="label"
                                    @click="form.icon = key"
                                >
                                    <i class="bi fs-6" :class="`bi-${key}`"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <label class="modern-field-label" for="f_color">Color distintivo</label>
                                <input
                                    id="f_color"
                                    v-model="form.color"
                                    type="color"
                                    class="form-control form-control-color w-100"
                                    style="height: 38px; border-radius: 8px; cursor: pointer;"
                                >
                            </div>
                            <div class="col-6">
                                <label class="modern-field-label" for="f_pos">Posición / Orden</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon"><i class="bi bi-sort-numeric-down"></i></span>
                                    <input
                                        id="f_pos"
                                        v-model="form.position"
                                        type="number"
                                        min="0"
                                        max="999"
                                        class="modern-input-control"
                                        placeholder="Orden"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                            <button v-if="form.id" type="button" class="btn btn-outline-secondary px-3" @click="resetForm">
                                Cancelar
                            </button>
                            <button type="submit" class="btn btn-primary px-4 fw-semibold" :disabled="form.processing">
                                <i class="bi me-1" :class="form.id ? 'bi-check-lg' : 'bi-plus-lg'"></i>
                                {{ form.id ? 'Guardar Cambios' : 'Crear Carpeta' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
