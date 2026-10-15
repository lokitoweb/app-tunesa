<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AudiencePicker from '@/Modules/Core/Components/AudiencePicker.vue';

const props = defineProps({ announcement: Object, categories: Object, audience: Object });

const editing = props.announcement.id !== null;
const form = useForm({ ...props.announcement });
const err = (key) => form.errors[key];

function submit(mode) {
    if (mode === 'send' && !confirm('¿Enviar el comunicado ahora? Llegará por notificación a todo su público.')) return;
    form.mode = mode;
    const options = { preserveScroll: true };
    if (editing) form.put(`/announcements/${props.announcement.id}`, options);
    else form.post('/announcements', options);
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar comunicado' : 'Nuevo comunicado'">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><Link href="/announcements">Comunicados</Link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ editing ? 'Editar' : 'Nuevo' }}</li>
            </ol>
        </nav>
        <div class="page-header">
            <div>
                <h1>{{ editing ? `Editar: ${announcement.title}` : 'Nuevo comunicado' }}</h1>
                <p>Escribe el aviso, elige a quién le llega y envíalo ahora o prográmalo.</p>
            </div>
        </div>

        <form @submit.prevent="submit('send')">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">1. El mensaje</h2></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <label for="title" class="form-label">Título <span class="text-danger">*</span></label>
                                    <input id="title" v-model="form.title" class="form-control" :class="{ 'is-invalid': err('title') }" maxlength="150" required placeholder="Ej.: Reunión general de fin de mes">
                                    <div class="invalid-feedback">{{ err('title') }}</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="category" class="form-label">Tipo</label>
                                    <select id="category" v-model="form.category" class="form-select">
                                        <option v-for="(c, key) in categories" :key="key" :value="key">{{ c[0] }}</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="body" class="form-label">Mensaje <span class="text-danger">*</span></label>
                                    <textarea id="body" v-model="form.body" class="form-control" :class="{ 'is-invalid': err('body') }" rows="6" maxlength="5000" required></textarea>
                                    <div class="invalid-feedback">{{ err('body') }}</div>
                                    <div class="form-text">{{ form.body.length }} / 5000. La notificación muestra las primeras líneas; el texto completo se lee en la app.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="event_at" class="form-label">Fecha y hora del evento</label>
                                    <input id="event_at" v-model="form.event_at" type="datetime-local" class="form-control" :class="{ 'is-invalid': err('event_at') }" aria-describedby="event_help">
                                    <div id="event_help" class="form-text">Para reuniones o capacitaciones (opcional).</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="location" class="form-label">Lugar o enlace de la reunión</label>
                                    <input id="location" v-model="form.location" class="form-control" maxlength="200" placeholder="Ej.: Sala 2 – Sede Lima">
                                </div>
                                <div class="col-12">
                                    <label for="url" class="form-label">Enlace</label>
                                    <input id="url" v-model="form.url" type="url" class="form-control" :class="{ 'is-invalid': err('url') }" maxlength="500" placeholder="https://…">
                                    <div class="invalid-feedback">{{ err('url') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">2. ¿A quién le llega?</h2></div>
                        <div class="card-body">
                            <AudiencePicker v-model="form.targets" :options="audience" :errors="form.errors" id-prefix="announcement" />
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card border-0 mb-4">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Envío</h2></div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input id="is_important" v-model="form.is_important" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="is_important">Importante (se destaca en el inicio de la app)</label>
                            </div>
                            <div v-if="form.category === 'urgent'" class="alert alert-warning small py-2">Los urgentes llegan aunque la persona haya apagado los comunicados.</div>
                            <label for="scheduled_at" class="form-label">Programar para</label>
                            <input id="scheduled_at" v-model="form.scheduled_at" type="datetime-local" class="form-control" :class="{ 'is-invalid': err('scheduled_at') }" aria-describedby="schedule_help">
                            <div class="invalid-feedback">{{ err('scheduled_at') }}</div>
                            <div id="schedule_help" class="form-text">Hora de la empresa. Déjalo vacío para enviarlo ahora.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-end gap-2 mb-5">
                <Link href="/announcements" class="btn btn-outline-secondary">Cancelar</Link>
                <button type="button" class="btn btn-outline-primary" :disabled="form.processing" @click="submit('draft')">Guardar borrador</button>
                <button v-if="form.scheduled_at" type="button" class="btn btn-primary" :disabled="form.processing" @click="submit('schedule')"><i class="bi bi-clock me-1"></i>Programar envío</button>
                <button v-else type="submit" class="btn btn-primary" :disabled="form.processing"><i class="bi bi-send me-1"></i>Enviar ahora</button>
            </div>
        </form>
    </AppLayout>
</template>
