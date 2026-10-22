<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AudiencePicker from '@/Modules/Core/Components/AudiencePicker.vue';
import ModernDatePicker from '@/Modules/Core/Components/ModernDatePicker.vue';

const props = defineProps({
    benefit: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    audience: {
        type: Object,
        default: () => ({}),
    },
});

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
        <div class="benefits-page-wrapper">
            <!-- Cabecera Ejecutiva -->
            <div class="benefits-header-card p-4 mb-4 shadow-sm">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <Link href="/benefits" class="btn-executive-back" title="Volver al catálogo">
                            <i class="bi bi-arrow-left"></i>
                        </Link>
                        <div class="benefits-header-icon shadow-sm">
                            <i class="bi bi-gift-fill"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <h1 class="h4 fw-bold mb-0 text-body">
                                    {{ editing ? `Editar: ${benefit.title}` : 'Nuevo Beneficio Corporativo' }}
                                </h1>
                            </div>
                            <p class="text-body-secondary small mb-0">
                                Define la información del beneficio, condiciones de uso y el público que lo verá en la app móvil.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <form @submit.prevent="submit()">
                <div class="row g-4">
                    <!-- Columna Izquierda: Formulario Principal -->
                    <div class="col-lg-8">
                        <!-- 1. Información General -->
                        <div class="benefits-section-card p-4 mb-4 shadow-sm">
                            <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                                <span class="badge bg-primary-subtle text-primary rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">
                                    1
                                </span>
                                <h2 class="h6 fw-bold mb-0 text-body">Detalles del Beneficio</h2>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label class="modern-field-label" for="title">
                                        Título del beneficio <span class="text-danger">*</span>
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-gift-fill"></i></span>
                                        <input
                                            id="title"
                                            v-model="form.title"
                                            type="text"
                                            class="modern-input-control"
                                            :class="{ 'is-invalid': err('title') }"
                                            maxlength="150"
                                            required
                                            placeholder="Ej.: Descuento en Smart Fit"
                                        >
                                    </div>
                                    <div v-if="err('title')" class="invalid-feedback d-block">{{ err('title') }}</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="modern-field-label" for="highlight">
                                        Destacado corto
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-tag-fill"></i></span>
                                        <input
                                            id="highlight"
                                            v-model="form.highlight"
                                            type="text"
                                            class="modern-input-control"
                                            maxlength="40"
                                            placeholder="Ej.: 30% DSCTO, 2x1"
                                        >
                                    </div>
                                    <div class="text-body-secondary small mt-1" style="font-size: 0.725rem;">
                                        Insignia visible en la tarjeta del beneficio.
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="modern-field-label" for="partner">
                                        Empresa aliada / Proveedor
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-building"></i></span>
                                        <input
                                            id="partner"
                                            v-model="form.partner"
                                            type="text"
                                            class="modern-input-control"
                                            maxlength="120"
                                            placeholder="Ej.: Clínica San Pablo"
                                        >
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="modern-field-label" for="category_id">
                                        Categoría
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-grid-fill"></i></span>
                                        <select
                                            id="category_id"
                                            v-model="form.category_id"
                                            class="modern-input-control"
                                            :class="{ 'is-invalid': err('category_id') }"
                                        >
                                            <option :value="null">Sin categoría</option>
                                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                                        </select>
                                    </div>
                                    <div v-if="err('category_id')" class="invalid-feedback d-block">{{ err('category_id') }}</div>
                                </div>

                                <div class="col-12">
                                    <label class="modern-field-label" for="summary">
                                        Resumen breve
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-chat-left-text-fill"></i></span>
                                        <input
                                            id="summary"
                                            v-model="form.summary"
                                            type="text"
                                            class="modern-input-control"
                                            maxlength="200"
                                            placeholder="Una frase atractiva que resuma el beneficio"
                                        >
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="modern-field-label" for="description">
                                        Descripción completa
                                    </label>
                                    <textarea
                                        id="description"
                                        v-model="form.description"
                                        class="form-control"
                                        rows="5"
                                        maxlength="10000"
                                        style="border-radius: 8px; min-height: 125px; padding: 10px 14px; line-height: 1.5; resize: vertical;"
                                        placeholder="Detalla toda la información relevante del beneficio para los colaboradores..."
                                    ></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Modo de Uso y Canje -->
                        <div class="benefits-section-card p-4 mb-4 shadow-sm">
                            <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                                <span class="badge bg-primary-subtle text-primary rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">
                                    2
                                </span>
                                <h2 class="h6 fw-bold mb-0 text-body">¿Cómo se usa y términos?</h2>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="modern-field-label" for="promo_code">
                                        Código promocional / Cupón
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-qr-code"></i></span>
                                        <input
                                            id="promo_code"
                                            v-model="form.promo_code"
                                            type="text"
                                            class="modern-input-control font-monospace"
                                            maxlength="60"
                                            placeholder="Ej.: NEGREIROS20"
                                        >
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="modern-field-label" for="url">
                                        Enlace web / URL oficial
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-link-45deg"></i></span>
                                        <input
                                            id="url"
                                            v-model="form.url"
                                            type="url"
                                            class="modern-input-control"
                                            :class="{ 'is-invalid': err('url') }"
                                            maxlength="500"
                                            placeholder="https://..."
                                        >
                                    </div>
                                    <div v-if="err('url')" class="invalid-feedback d-block">{{ err('url') }}</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="modern-field-label" for="contact">
                                        Contacto / Atención al cliente
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-telephone-fill"></i></span>
                                        <input
                                            id="contact"
                                            v-model="form.contact"
                                            type="text"
                                            class="modern-input-control"
                                            maxlength="150"
                                            placeholder="Teléfono o correo de soporte"
                                        >
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="modern-field-label" for="location">
                                        Ubicación / Sedes de canje
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-geo-alt-fill"></i></span>
                                        <input
                                            id="location"
                                            v-model="form.location"
                                            type="text"
                                            class="modern-input-control"
                                            maxlength="200"
                                            placeholder="Ej.: Todas las sedes Lima Metropolitana"
                                        >
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="modern-field-label" for="how_to_use">
                                        Instrucciones de canje
                                    </label>
                                    <textarea
                                        id="how_to_use"
                                        v-model="form.how_to_use"
                                        class="form-control"
                                        rows="4"
                                        maxlength="5000"
                                        style="border-radius: 8px; min-height: 110px; padding: 10px 14px; line-height: 1.5; resize: vertical;"
                                        placeholder="Ej.: Presenta tu fotocheck en caja y menciona el código para aplicar el descuento..."
                                    ></textarea>
                                </div>

                                <div class="col-12">
                                    <label class="modern-field-label" for="terms">
                                        Términos y condiciones
                                    </label>
                                    <textarea
                                        id="terms"
                                        v-model="form.terms"
                                        class="form-control"
                                        rows="4"
                                        maxlength="5000"
                                        style="border-radius: 8px; min-height: 110px; padding: 10px 14px; line-height: 1.5; resize: vertical;"
                                        placeholder="Restricciones, horarios de validez, no acumulable con otras promociones..."
                                    ></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Destinatarios y Audiencia -->
                        <div class="benefits-section-card p-4 mb-4 shadow-sm">
                            <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                                <span class="badge bg-primary-subtle text-primary rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">
                                    3
                                </span>
                                <h2 class="h6 fw-bold mb-0 text-body">Público Destinatario en la App</h2>
                            </div>
                            <AudiencePicker v-model="form.targets" :options="audience" :errors="form.errors" id-prefix="benefit" />
                        </div>
                    </div>

                    <!-- Columna Derecha: Imagen y Configuración -->
                    <div class="col-lg-4">
                        <!-- Portada e Imagen -->
                        <div class="benefits-section-card p-4 mb-4 shadow-sm">
                            <h2 class="h6 fw-bold mb-3 text-body d-flex align-items-center">
                                <i class="bi bi-image-fill me-2 text-primary"></i>Imagen de Portada
                            </h2>

                            <div v-if="benefit.image_url && !form.remove_image && !form.image" class="mb-3 position-relative rounded-3 overflow-hidden border">
                                <img :src="benefit.image_url" alt="Imagen actual" class="img-fluid w-100" style="max-height: 180px; object-fit: cover;">
                            </div>

                            <label for="image" class="form-label small text-body-secondary mb-2">
                                Formatos soportados: JPG, PNG o WebP (máx. 4 MB). Recomendado horizontal (800x400 px).
                            </label>
                            <input
                                id="image"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="form-control"
                                :class="{ 'is-invalid': err('image') }"
                                style="border-radius: 8px;"
                                @input="onImage"
                            >
                            <div v-if="err('image')" class="invalid-feedback d-block">{{ err('image') }}</div>

                            <div v-if="benefit.image_url" class="form-check mt-3">
                                <input id="remove_image" v-model="form.remove_image" class="form-check-input" type="checkbox">
                                <label for="remove_image" class="form-check-label small text-body">Quitar la imagen actual</label>
                            </div>
                        </div>

                        <!-- Fechas de Vigencia y Opciones -->
                        <div class="benefits-section-card p-4 mb-4 shadow-sm">
                            <h2 class="h6 fw-bold mb-3 text-body d-flex align-items-center">
                                <i class="bi bi-calendar3 me-2 text-primary"></i>Vigencia y Visibilidad
                            </h2>

                            <div class="mb-3">
                                <label class="modern-field-label mb-1" for="valid_from">Fecha de inicio</label>
                                <ModernDatePicker
                                    id="valid_from"
                                    v-model="form.valid_from"
                                    placeholder="dd/mm/aaaa"
                                    icon="bi bi-calendar-event"
                                    align="left"
                                />
                                <div v-if="err('valid_from')" class="invalid-feedback d-block">{{ err('valid_from') }}</div>
                            </div>

                            <div class="mb-3">
                                <label class="modern-field-label mb-1" for="valid_until">
                                    Fecha de vencimiento <span class="text-muted fw-normal" style="font-size: 0.75rem;">(Opcional)</span>
                                </label>
                                <ModernDatePicker
                                    id="valid_until"
                                    v-model="form.valid_until"
                                    placeholder="dd/mm/aaaa"
                                    icon="bi bi-calendar-check"
                                    :allow-clear="true"
                                    clear-label="Sin fecha fin"
                                    align="left"
                                />
                                <div v-if="err('valid_until')" class="invalid-feedback d-block">{{ err('valid_until') }}</div>
                                <div class="text-body-secondary small mt-1" style="font-size: 0.725rem;">
                                    Vacío: vigencia indefinida. Al vencer se oculta automáticamente.
                                </div>
                            </div>

                            <hr class="my-3 opacity-25">

                            <!-- Interruptores Modernos -->
                            <div class="form-check form-switch mb-3">
                                <input id="is_featured" v-model="form.is_featured" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label fw-semibold text-body ms-2" for="is_featured">
                                    <i class="bi bi-star-fill text-warning me-1"></i>Destacar beneficio
                                    <div class="text-body-secondary small fw-normal">Aparecerá en los primeros lugares de la app.</div>
                                </label>
                            </div>

                            <div v-if="benefit.status !== 'published'" class="form-check form-switch">
                                <input id="notify" v-model="form.notify" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label fw-semibold text-body ms-2" for="notify">
                                    <i class="bi bi-bell-fill text-primary me-1"></i>Notificar al publicar
                                    <div class="text-body-secondary small fw-normal">Envía un push a los colaboradores que pueden usarlo.</div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Botones de Acción Inferiores -->
                <div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-5 pt-3 border-top">
                    <Link href="/benefits" class="btn btn-outline-secondary px-4 py-2 fw-semibold">
                        Cancelar
                    </Link>
                    <button
                        type="button"
                        class="btn btn-outline-primary px-4 py-2 fw-semibold"
                        :disabled="form.processing"
                        @click="submit(benefit.status === 'published' ? 'published' : 'draft')"
                    >
                        <i class="bi bi-save me-1"></i>
                        {{ benefit.status === 'published' ? 'Guardar cambios' : 'Guardar borrador' }}
                    </button>
                    <button
                        v-if="benefit.status !== 'published'"
                        type="button"
                        class="btn btn-primary px-4 py-2 fw-semibold shadow-sm"
                        :disabled="form.processing"
                        @click="submit('published')"
                    >
                        <i class="bi bi-send-fill me-1"></i>Guardar y publicar
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
