<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AudiencePicker from '@/Modules/Core/Components/AudiencePicker.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    announcement: {
        type: Object,
        required: true,
    },
    categories: {
        type: Object,
        default: () => ({}),
    },
    audience: {
        type: Object,
        default: () => ({}),
    },
});

const { ask } = useConfirm();

const editing = props.announcement.id !== null;
const form = useForm({ ...props.announcement });
const err = (key) => form.errors[key];

async function submit(mode) {
    if (mode === 'send') {
        const ok = await ask({
            title: '¿Enviar comunicado ahora?',
            message: 'El comunicado se difundirá inmediatamente a toda la audiencia asignada y cada persona recibirá una notificación push en la app móvil.',
            confirmText: 'Enviar ahora',
            variant: 'primary',
            icon: 'bi bi-send-fill',
        });
        if (!ok) return;
    }

    form.mode = mode;
    const options = { preserveScroll: true };
    if (editing) {
        form.put(`/announcements/${props.announcement.id}`, options);
    } else {
        form.post('/announcements', options);
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar comunicado' : 'Nuevo comunicado'">
        <div class="announcements-page-wrapper">
            <!-- =========================================================================
                 1. CABECERA EJECUTIVA DEL CREADOR / EDITOR
                 ========================================================================= -->
            <div class="announcements-header-card shadow-sm p-3 p-md-4 mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <Link href="/announcements" class="btn-executive-back">
                        <i class="bi bi-arrow-left" style="margin-right: 8px !important;"></i>
                        <span>Volver al listado</span>
                    </Link>
                    <span v-if="editing && form.status" class="badge rounded-pill px-2.5 py-1 text-secondary bg-light border">
                        Estado: {{ form.status }}
                    </span>
                </div>

                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center" style="gap: 16px !important;">
                        <div class="announcements-header-icon shadow-sm">
                            <i :class="editing ? 'bi bi-pencil-square' : 'bi bi-megaphone-fill'"></i>
                        </div>
                        <div>
                            <h1 class="h4 fw-bold mb-1 text-body">
                                {{ editing ? `Editar: ${announcement.title}` : 'Crear Nuevo Comunicado' }}
                            </h1>
                            <p class="text-secondary small mb-0">
                                Redacta el mensaje oficial, define el público objetivo y envíalo ahora o déjalo programado.
                            </p>
                        </div>
                    </div>

                    <!-- Botones de Acción Superiores -->
                    <div class="d-flex align-items-center flex-wrap" style="gap: 10px !important;">
                        <Link 
                            href="/announcements" 
                            class="btn btn-outline-secondary d-inline-flex align-items-center"
                            style="height: 40px; border-radius: 8px; font-weight: 500; font-size: 0.85rem;"
                        >
                            Cancelar
                        </Link>
                        <button 
                            type="button" 
                            class="btn btn-outline-primary d-inline-flex align-items-center"
                            style="height: 40px; border-radius: 8px; font-weight: 600; font-size: 0.85rem;"
                            :disabled="form.processing"
                            @click="submit('draft')"
                        >
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                            <i v-else class="bi bi-file-earmark-check" style="margin-right: 8px !important;"></i>
                            <span>Guardar borrador</span>
                        </button>
                        <button 
                            v-if="form.scheduled_at" 
                            type="button" 
                            class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                            style="height: 40px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;"
                            :disabled="form.processing"
                            @click="submit('schedule')"
                        >
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                            <i v-else class="bi bi-clock-fill" style="margin-right: 8px !important;"></i>
                            <span>Programar envío</span>
                        </button>
                        <button 
                            v-else 
                            type="button" 
                            class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                            style="height: 40px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;"
                            :disabled="form.processing"
                            @click="submit('send')"
                        >
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                            <i v-else class="bi bi-send-fill" style="margin-right: 8px !important;"></i>
                            <span>Enviar ahora</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 FORMULARIO PRINCIPAL
                 ========================================================================= -->
            <form @submit.prevent="submit(form.scheduled_at ? 'schedule' : 'send')">
                <div class="row g-4">
                    <!-- Columna Principal: Mensaje y Audiencia -->
                    <div class="col-lg-8">
                        <!-- SECCIÓN 1: EL MENSAJE -->
                        <div class="announcements-section-card shadow-sm p-3 p-md-4 mb-4">
                            <div class="d-flex align-items-center mb-3" style="gap: 12px !important;">
                                <span class="badge rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.85rem;">
                                    1
                                </span>
                                <div>
                                    <h2 class="h6 fw-bold mb-0 text-body">Contenido del Comunicado</h2>
                                    <span class="small text-secondary">Título, tipo de aviso y texto principal</span>
                                </div>
                            </div>

                            <div class="row g-3">
                                <!-- Título -->
                                <div class="col-md-8">
                                    <label for="title" class="modern-field-label">
                                        Título del comunicado <span class="required-star">*</span>
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-type-h1 modern-input-icon"></i>
                                        <input 
                                            id="title" 
                                            v-model="form.title" 
                                            class="modern-input-control" 
                                            :class="{ 'is-invalid': err('title') }" 
                                            maxlength="150" 
                                            required 
                                            placeholder="Ej.: Reunión general de fin de mes"
                                        >
                                    </div>
                                    <div v-if="err('title')" class="invalid-feedback d-block">{{ err('title') }}</div>
                                </div>

                                <!-- Tipo / Categoría -->
                                <div class="col-md-4">
                                    <label for="category" class="modern-field-label">
                                        Tipo de aviso <span class="required-star">*</span>
                                    </label>
                                    <select 
                                        id="category" 
                                        v-model="form.category" 
                                        class="form-select"
                                        style="height: 40px; border-radius: 8px; font-weight: 500; font-size: 0.875rem;"
                                    >
                                        <option v-for="(c, key) in categories" :key="key" :value="key">
                                            {{ c[0] }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Mensaje Principal -->
                                <div class="col-12">
                                    <label for="body" class="modern-field-label">
                                        Mensaje completo <span class="required-star">*</span>
                                    </label>
                                    <textarea 
                                        id="body" 
                                        v-model="form.body" 
                                        class="form-control" 
                                        :class="{ 'is-invalid': err('body') }" 
                                        rows="6" 
                                        maxlength="5000" 
                                        required
                                        style="border-radius: 8px; font-size: 0.875rem; line-height: 1.6;"
                                        placeholder="Escribe el cuerpo del comunicado aquí..."
                                    ></textarea>
                                    <div v-if="err('body')" class="invalid-feedback d-block">{{ err('body') }}</div>
                                    <div class="form-text small d-flex justify-content-between mt-1">
                                        <span>La notificación push móvil muestra las primeras líneas; el contenido íntegro se lee en la app.</span>
                                        <span class="text-muted fw-semibold">{{ form.body ? form.body.length : 0 }} / 5000</span>
                                    </div>
                                </div>

                                <!-- Fecha y hora del evento -->
                                <div class="col-md-6">
                                    <label for="event_at" class="modern-field-label">
                                        Fecha y hora del evento (opcional)
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-calendar-event modern-input-icon"></i>
                                        <input 
                                            id="event_at" 
                                            v-model="form.event_at" 
                                            type="datetime-local" 
                                            class="modern-input-control" 
                                            :class="{ 'is-invalid': err('event_at') }"
                                        >
                                    </div>
                                    <div class="form-text small" style="font-size: 0.725rem; margin-top: 3px;">Para reuniones o actividades con fecha fija.</div>
                                    <div v-if="err('event_at')" class="invalid-feedback d-block">{{ err('event_at') }}</div>
                                </div>

                                <!-- Lugar / Sala -->
                                <div class="col-md-6">
                                    <label for="location" class="modern-field-label">
                                        Lugar o sala de reunión
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-geo-alt modern-input-icon"></i>
                                        <input 
                                            id="location" 
                                            v-model="form.location" 
                                            class="modern-input-control" 
                                            maxlength="200" 
                                            placeholder="Ej.: Sala de capacitación – Sede Principal"
                                        >
                                    </div>
                                </div>

                                <!-- Enlace web -->
                                <div class="col-12">
                                    <label for="url" class="modern-field-label">
                                        Enlace web o reunión virtual (opcional)
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-link-45deg modern-input-icon"></i>
                                        <input 
                                            id="url" 
                                            v-model="form.url" 
                                            type="url" 
                                            class="modern-input-control" 
                                            :class="{ 'is-invalid': err('url') }" 
                                            maxlength="500" 
                                            placeholder="https://meet.google.com/... o https://..."
                                        >
                                    </div>
                                    <div v-if="err('url')" class="invalid-feedback d-block">{{ err('url') }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- SECCIÓN 2: AUDIENCIA Y DESTINATARIOS -->
                        <div class="announcements-section-card shadow-sm p-3 p-md-4 mb-4">
                            <div class="d-flex align-items-center mb-3" style="gap: 12px !important;">
                                <span class="badge rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.85rem;">
                                    2
                                </span>
                                <div>
                                    <h2 class="h6 fw-bold mb-0 text-body">Público y Destinatarios</h2>
                                    <span class="small text-secondary">Selecciona quiénes recibirán este comunicado</span>
                                </div>
                            </div>

                            <AudiencePicker 
                                v-model="form.targets" 
                                :options="audience" 
                                :errors="form.errors" 
                                id-prefix="announcement" 
                            />
                        </div>
                    </div>

                    <!-- Columna Lateral: Configuración de Envío -->
                    <div class="col-lg-4">
                        <div class="announcements-section-card shadow-sm p-3 p-md-4 mb-4">
                            <div class="d-flex align-items-center mb-3" style="gap: 10px !important;">
                                <div class="announcements-kpi-pill bg-primary-subtle text-primary" style="width: 32px; height: 32px; font-size: 0.95rem;">
                                    <i class="bi bi-sliders"></i>
                                </div>
                                <h2 class="h6 fw-bold mb-0 text-body">Parámetros de Difusión</h2>
                            </div>

                            <!-- Switch de Importante -->
                            <div 
                                class="payslip-executive-switch-card p-3 d-flex align-items-center justify-content-between mb-3"
                                :class="{ 'is-active': form.is_important }"
                                style="border-radius: 8px;"
                            >
                                <div class="d-flex align-items-center" style="gap: 10px !important;">
                                    <div class="announcements-kpi-pill" :class="form.is_important ? 'bg-warning-subtle text-warning-emphasis' : 'bg-secondary-subtle text-secondary'" style="width: 32px; height: 32px; font-size: 0.9rem;">
                                        <i class="bi bi-star-fill"></i>
                                    </div>
                                    <div>
                                        <label for="is_important" class="fw-bold text-dark mb-0 cursor-pointer" style="font-size: 0.85rem;">
                                            Destacado
                                        </label>
                                        <div class="small text-secondary" style="font-size: 0.725rem;">
                                            Fijar en el inicio de la app móvil.
                                        </div>
                                    </div>
                                </div>
                                <div class="form-check form-switch m-0 ps-0">
                                    <input 
                                        id="is_important" 
                                        v-model="form.is_important" 
                                        class="form-check-input custom-executive-switch" 
                                        type="checkbox" 
                                        role="switch"
                                    >
                                </div>
                            </div>

                            <div v-if="form.category === 'urgent'" class="alert alert-danger small py-2 d-flex align-items-start mb-3" style="border-radius: 8px; gap: 8px;">
                                <i class="bi bi-exclamation-octagon-fill text-danger mt-0.5"></i>
                                <span>Los avisos urgentes se notifican obligatoriamente aunque el colaborador tenga las alertas silenciadas.</span>
                            </div>

                            <!-- Programar envío -->
                            <div class="mb-3">
                                <label for="scheduled_at" class="modern-field-label">
                                    Programar para envío diferido
                                </label>
                                <div class="modern-input-wrapper">
                                    <i class="bi bi-clock-history modern-input-icon"></i>
                                    <input 
                                        id="scheduled_at" 
                                        v-model="form.scheduled_at" 
                                        type="datetime-local" 
                                        class="modern-input-control" 
                                        :class="{ 'is-invalid': err('scheduled_at') }"
                                    >
                                </div>
                                <div class="form-text small" style="font-size: 0.725rem; margin-top: 4px;">
                                    Déjalo vacío para realizar el envío de inmediato.
                                </div>
                                <div v-if="err('scheduled_at')" class="invalid-feedback d-block">{{ err('scheduled_at') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Botones Inferiores -->
                <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 mb-5">
                    <Link href="/announcements" class="btn btn-outline-secondary" style="height: 42px; border-radius: 8px; font-weight: 500;">
                        Cancelar
                    </Link>
                    <button 
                        type="button" 
                        class="btn btn-outline-primary d-inline-flex align-items-center"
                        style="height: 42px; border-radius: 8px; font-weight: 600;"
                        :disabled="form.processing"
                        @click="submit('draft')"
                    >
                        <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                        <i v-else class="bi bi-file-earmark-check" style="margin-right: 8px !important;"></i>
                        <span>Guardar borrador</span>
                    </button>
                    <button 
                        v-if="form.scheduled_at" 
                        type="button" 
                        class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                        style="height: 42px; border-radius: 8px; font-weight: 600; padding: 0 1.5rem;"
                        :disabled="form.processing"
                        @click="submit('schedule')"
                    >
                        <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                        <i v-else class="bi bi-clock-fill" style="margin-right: 8px !important;"></i>
                        <span>Programar envío</span>
                    </button>
                    <button 
                        v-else 
                        type="button" 
                        class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                        style="height: 42px; border-radius: 8px; font-weight: 600; padding: 0 1.5rem;"
                        :disabled="form.processing"
                        @click="submit('send')"
                    >
                        <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                        <i v-else class="bi bi-send-fill" style="margin-right: 8px !important;"></i>
                        <span>Enviar ahora</span>
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
