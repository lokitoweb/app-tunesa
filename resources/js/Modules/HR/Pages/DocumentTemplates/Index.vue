<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    templates: { type: Array, required: true },
    fields: { type: Object, required: true },
});

const { ask } = useConfirm();
const forms = Object.fromEntries(props.templates.map((t) => [t.key, useForm({ title: t.title, body: t.body })]));
const areas = ref({});

// Inserta {campo} donde está el cursor del texto
function insert(key, field) {
    const el = areas.value[key];
    const f = forms[key];
    const at = el ? el.selectionStart : f.body.length;
    f.body = `${f.body.slice(0, at)}{${field}}${f.body.slice(el ? el.selectionEnd : at)}`;
    requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(at + field.length + 2, at + field.length + 2); });
}
const save = (t) => forms[t.key].put(`/document-templates/${t.key}`, { preserveScroll: true, onSuccess: () => forms[t.key].defaults() });
async function reset(t) {
    const ok = await ask({
        title: '¿Volver al texto estándar?',
        message: `«${t.standard_title}» recupera el texto estándar y se pierde lo que escribiste.`,
        confirmText: 'Sí, volver al estándar',
        variant: 'danger',
        icon: 'bi bi-arrow-counterclockwise',
    });
    if (ok) {
        router.delete(`/document-templates/${t.key}`, {
            preserveScroll: true,
            onSuccess: () => { forms[t.key].defaults({ title: t.standard_title, body: t.standard_body }); forms[t.key].reset(); },
        });
    }
}
</script>

<template>
    <AppLayout title="Plantillas de documentos">
        <div class="mb-3">
            <h1 class="h4 fw-bold mb-1">Plantillas de documentos</h1>
            <p class="text-body-secondary small mb-0">
                Textos de la liquidación, el certificado de trabajo y la carta de CTS. Los campos entre llaves se llenan solos con los datos de la
                empresa, del colaborador y de quien firma. Deja una línea en blanco para separar párrafos.
            </p>
        </div>

        <div v-for="t in templates" :key="t.key" class="card shadow-sm mb-3">
            <form class="card-body" @submit.prevent="save(t)">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h2 class="h6 fw-bold mb-0">
                        {{ t.standard_title }}
                        <span v-if="t.custom" class="badge bg-warning-subtle text-warning-emphasis ms-1">personalizado</span>
                    </h2>
                    <div class="d-flex gap-2">
                        <button v-if="t.custom" type="button" class="btn btn-sm btn-outline-secondary" @click="reset(t)">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Texto estándar
                        </button>
                        <button type="submit" class="btn btn-sm btn-primary" :disabled="!forms[t.key].isDirty || forms[t.key].processing">Guardar</button>
                    </div>
                </div>
                <template v-if="t.titled">
                    <label class="form-label small fw-semibold" :for="`tpl-title-${t.key}`">Título del documento</label>
                    <input :id="`tpl-title-${t.key}`" v-model="forms[t.key].title" class="form-control form-control-sm mb-2" :class="{ 'is-invalid': forms[t.key].errors.title }" maxlength="150" required />
                    <div class="invalid-feedback">{{ forms[t.key].errors.title }}</div>
                </template>
                <label class="form-label small fw-semibold" :for="`tpl-body-${t.key}`">Texto</label>
                <textarea :id="`tpl-body-${t.key}`" :ref="(el) => { areas[t.key] = el; }" v-model="forms[t.key].body" rows="10"
                    class="form-control form-control-sm font-monospace" :class="{ 'is-invalid': forms[t.key].errors.body }" required></textarea>
                <div class="invalid-feedback">{{ forms[t.key].errors.body }}</div>
                <div class="d-flex flex-wrap gap-1 mt-2" role="group" aria-label="Insertar campo">
                    <button v-for="(label, field) in fields" :key="field" type="button" class="btn btn-sm btn-light border" :title="label" @click="insert(t.key, field)" v-text="'{' + field + '}'"></button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
