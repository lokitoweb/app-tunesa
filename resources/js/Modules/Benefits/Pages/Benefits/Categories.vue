<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({ categories: Array, icons: Object });

const form = useForm({ id: null, name: '', icon: 'gift', color: '#6C4CE0', position: null });

function edit(c) {
    Object.assign(form, { id: c.id, name: c.name, icon: c.icon, color: c.color, position: c.position });
    form.clearErrors();
}
function reset() {
    form.reset();
    form.clearErrors();
}
function submit() {
    const options = { preserveScroll: true, onSuccess: reset };
    if (form.id) form.put(`/benefits/categories/${form.id}`, options);
    else form.post('/benefits/categories', options);
}
function destroy(c) {
    if (confirm(`¿Eliminar la categoría "${c.name}"? Sus ${c.benefits_count} beneficio(s) quedarán sin categoría.`)) {
        router.delete(`/benefits/categories/${c.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Categorías de beneficios">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><Link href="/benefits">Beneficios</Link></li>
                <li class="breadcrumb-item active" aria-current="page">Categorías</li>
            </ol>
        </nav>
        <div class="page-header">
            <div>
                <h1>Categorías de beneficios</h1>
                <p>Agrupan los beneficios en la app (filtros por categoría). El orden define cómo aparecen.</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0">
                    <ul class="list-group list-group-flush">
                        <li v-for="c in categories" :key="c.id" class="list-group-item d-flex align-items-center gap-3 py-3">
                            <span class="kpi-icon" :style="{ background: `${c.color}22`, color: c.color }"><i class="bi" :class="`bi-${c.icon}`"></i></span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">{{ c.name }}</div>
                                <div class="small text-body-secondary">{{ c.benefits_count }} beneficio(s) · posición {{ c.position }}</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="edit(c)"><i class="bi bi-pencil"></i> Editar</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" :aria-label="`Eliminar ${c.name}`" @click="destroy(c)"><i class="bi bi-trash"></i></button>
                        </li>
                        <li v-if="!categories.length" class="list-group-item"><div class="empty-state"><i class="bi bi-tags"></i>Sin categorías.</div></li>
                    </ul>
                </div>
            </div>
            <div class="col-lg-5">
                <form class="card border-0" @submit.prevent="submit">
                    <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">{{ form.id ? 'Editar categoría' : 'Nueva categoría' }}</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="cat_name" class="form-label">Nombre</label>
                            <input id="cat_name" v-model="form.name" class="form-control" :class="{ 'is-invalid': form.errors.name }" maxlength="80" required>
                            <div class="invalid-feedback">{{ form.errors.name }}</div>
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
                                <label for="cat_color" class="form-label">Color</label>
                                <input id="cat_color" v-model="form.color" type="color" class="form-control form-control-color w-100" :class="{ 'is-invalid': form.errors.color }">
                            </div>
                            <div class="col-6">
                                <label for="cat_position" class="form-label">Posición</label>
                                <input id="cat_position" v-model="form.position" type="number" min="0" max="999" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-0 d-flex justify-content-end gap-2">
                        <button v-if="form.id" type="button" class="btn btn-outline-secondary" @click="reset">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.id ? 'Guardar' : 'Crear categoría' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
