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
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Plantillas de documentos</h1>
                <p class="page-subtitle">
                    Textos de la liquidación, el certificado de trabajo y la carta de CTS. Los campos entre llaves se llenan solos con los datos de la
                    empresa, del colaborador y de quien firma. Deja una línea en blanco para separar párrafos.
                </p>
            </div>
        </header>

        <section v-for="t in templates" :key="t.key" class="surface mb-3">
            <form @submit.prevent="save(t)">
                <header class="surface-head">
                    <div class="d-flex align-items-center gap-2">
                        <span class="list-plain-icon"><i class="bi bi-file-earmark-text"></i></span>
                        <h2 class="surface-title">{{ t.standard_title }}</h2>
                        <span v-if="t.custom" class="status-pill tone-amber">Personalizado</span>
                        <span v-if="forms[t.key].isDirty" class="status-pill tone-brand">Sin guardar</span>
                    </div>
                    <div class="d-flex gap-2">
                        <button v-if="t.custom" type="button" class="btn btn-sm btn-light border" @click="reset(t)"><i class="bi bi-arrow-counterclockwise me-1"></i>Texto estándar</button>
                        <button type="submit" class="btn btn-sm btn-primary" :disabled="!forms[t.key].isDirty || forms[t.key].processing">Guardar</button>
                    </div>
                </header>
                <div class="surface-pad">
                    <template v-if="t.titled">
                        <label class="form-label" :for="`tpl-title-${t.key}`">Título del documento</label>
                        <input :id="`tpl-title-${t.key}`" v-model="forms[t.key].title" class="form-control mb-3" :class="{ 'is-invalid': forms[t.key].errors.title }" maxlength="150" required />
                        <div class="invalid-feedback">{{ forms[t.key].errors.title }}</div>
                    </template>
                    <label class="form-label" :for="`tpl-body-${t.key}`">Texto</label>
                    <textarea :id="`tpl-body-${t.key}`" :ref="(el) => { areas[t.key] = el; }" v-model="forms[t.key].body" rows="10"
                        class="form-control font-monospace tpl-body" :class="{ 'is-invalid': forms[t.key].errors.body }" required></textarea>
                    <div class="invalid-feedback">{{ forms[t.key].errors.body }}</div>
                    <div class="tpl-fields" role="group" aria-label="Insertar campo">
                        <span class="cell-sub me-1">Insertar:</span>
                        <button v-for="(label, field) in fields" :key="field" type="button" class="tpl-field" :title="label" @click="insert(t.key, field)" v-text="'{' + field + '}'"></button>
                    </div>
                </div>
            </form>
        </section>
    </AppLayout>
</template>
