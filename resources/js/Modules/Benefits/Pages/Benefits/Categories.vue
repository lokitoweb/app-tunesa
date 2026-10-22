<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    categories: {
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
    icon: 'gift',
    color: '#6C4CE0',
    position: null,
});

function editCategory(c) {
    Object.assign(form, {
        id: c.id,
        name: c.name,
        icon: c.icon,
        color: c.color,
        position: c.position,
    });
    form.clearErrors();
}

function resetForm() {
    form.reset();
    form.id = null;
    form.clearErrors();
}

function submitCategory() {
    const options = { preserveScroll: true, onSuccess: resetForm };
    if (form.id) {
        form.put(`/benefits/categories/${form.id}`, options);
    } else {
        form.post('/benefits/categories', options);
    }
}

async function destroyCategory(c) {
    const confirmed = await ask({
        title: '¿Eliminar categoría?',
        message: `¿Estás seguro de eliminar "${c.name}"? Sus ${c.benefits_count} beneficio(s) asociados quedarán sin categoría.`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });

    if (confirmed) {
        router.delete(`/benefits/categories/${c.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Categorías de Beneficios">
        <div class="benefits-page-wrapper">
            <!-- Cabecera Ejecutiva -->
            <div class="benefits-header-card p-4 mb-4 shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <Link href="/benefits" class="btn-executive-back" title="Volver al catálogo de beneficios">
                        <i class="bi bi-arrow-left"></i>
                    </Link>
                    <div class="benefits-header-icon shadow-sm">
                        <i class="bi bi-tags-fill"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h1 class="h4 fw-bold mb-0 text-body">Categorías de Beneficios</h1>
                        </div>
                        <p class="text-body-secondary small mb-0">
                            Organiza y agrupa los beneficios disponibles en la app móvil. El orden determina su secuencia en los filtros de la aplicación.
                        </p>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Columna Izquierda: Listado de Categorías -->
                <div class="col-lg-7">
                    <div class="benefits-section-card shadow-sm overflow-hidden">
                        <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                            <h2 class="h6 fw-bold mb-0 text-body d-flex align-items-center">
                                <i class="bi bi-collection-fill me-2 text-primary"></i>Categorías Configuradas
                            </h2>
                            <span class="badge bg-primary-subtle text-primary">{{ categories.length }} categorías</span>
                        </div>

                        <ul class="list-group list-group-flush">
                            <li
                                v-for="c in categories"
                                :key="c.id"
                                class="list-group-item d-flex align-items-center gap-3 p-3 border-bottom"
                                :class="{ 'bg-primary-subtle': form.id === c.id }"
                            >
                                <span
                                    class="kpi-icon flex-shrink-0"
                                    :style="{ background: `${c.color}22`, color: c.color, width: '42px', height: '42px', borderRadius: '10px' }"
                                >
                                    <i class="bi fs-5" :class="`bi-${c.icon}`"></i>
                                </span>

                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-bold text-body mb-1 d-flex align-items-center gap-2">
                                        <span>{{ c.name }}</span>
                                        <span class="badge rounded-pill border small" :style="{ borderColor: `${c.color}40`, color: c.color }">
                                            Posición {{ c.position }}
                                        </span>
                                    </div>
                                    <div class="small text-body-secondary">
                                        <i class="bi bi-gift me-1"></i>{{ c.benefits_count }} beneficio(s) asignados
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-1">
                                    <button
                                        type="button"
                                        class="btn-table-action btn-action-edit"
                                        title="Editar categoría"
                                        @click="editCategory(c)"
                                    >
                                        <i class="bi bi-pencil-fill"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-table-action btn-table-delete"
                                        title="Eliminar categoría"
                                        @click="destroyCategory(c)"
                                    >
                                        <i class="bi bi-trash3-fill"></i>
                                    </button>
                                </div>
                            </li>

                            <li v-if="!categories.length" class="list-group-item p-5 text-center">
                                <i class="bi bi-tags fs-1 text-body-secondary d-block mb-2"></i>
                                <span class="text-body-secondary small">Aún no hay categorías registradas. Crea la primera a la derecha.</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Columna Derecha: Formulario Crear / Editar -->
                <div class="col-lg-5">
                    <form class="benefits-section-card shadow-sm p-4" @submit.prevent="submitCategory">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <h2 class="h6 fw-bold mb-0 text-body d-flex align-items-center">
                                <i class="bi me-2 text-primary" :class="form.id ? 'bi-pencil-square' : 'bi-plus-circle-fill'"></i>
                                {{ form.id ? 'Editar Categoría' : 'Nueva Categoría' }}
                            </h2>
                            <button v-if="form.id" type="button" class="btn btn-sm btn-outline-secondary" @click="resetForm">
                                Nueva
                            </button>
                        </div>

                        <div class="mb-3">
                            <label class="modern-field-label" for="cat_name">
                                Nombre de la categoría <span class="text-danger">*</span>
                            </label>
                            <div class="modern-input-wrapper">
                                <span class="modern-input-icon"><i class="bi bi-tag-fill"></i></span>
                                <input
                                    id="cat_name"
                                    v-model="form.name"
                                    type="text"
                                    class="modern-input-control"
                                    :class="{ 'is-invalid': form.errors.name }"
                                    maxlength="80"
                                    required
                                    placeholder="Ej.: Salud, Entretenimiento, Educación"
                                >
                            </div>
                            <div v-if="form.errors.name" class="invalid-feedback d-block">{{ form.errors.name }}</div>
                        </div>

                        <!-- Selector Visual de Íconos -->
                        <div class="mb-3">
                            <label class="modern-field-label">Ícono distintivo</label>
                            <div class="d-flex flex-wrap gap-2 p-2 border rounded-3 bg-surface-elevated" style="max-height: 180px; overflow-y: auto;">
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
                                <label class="modern-field-label" for="cat_color">Color distintivo</label>
                                <div class="d-flex align-items-center gap-2">
                                    <input
                                        id="cat_color"
                                        v-model="form.color"
                                        type="color"
                                        class="form-control form-control-color w-100"
                                        :class="{ 'is-invalid': form.errors.color }"
                                        style="height: 38px; border-radius: 8px; cursor: pointer;"
                                    >
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="modern-field-label" for="cat_position">Posición</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon"><i class="bi bi-sort-numeric-down"></i></span>
                                    <input
                                        id="cat_position"
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
                                {{ form.id ? 'Guardar Cambios' : 'Crear Categoría' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
