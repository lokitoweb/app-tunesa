<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({ shift: Object });

const DAYS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const editing = props.shift.id !== null;
const form = useForm({ ...props.shift });

function submit() {
    editing ? form.put(`/shifts/${props.shift.id}`) : form.post('/shifts');
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar turno' : 'Nuevo turno'">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><Link href="/shifts">Turnos</Link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ editing ? 'Editar' : 'Nuevo' }}</li>
            </ol>
        </nav>
        <div class="page-header">
            <div>
                <h1>{{ editing ? `Editar turno: ${shift.name}` : 'Nuevo turno' }}</h1>
                <p>La tolerancia define cuántos minutos después de la hora de entrada se sigue considerando puntual.</p>
            </div>
        </div>

        <form class="card border-0" @submit.prevent="submit">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="name" class="form-label">Nombre</label>
                        <input id="name" v-model="form.name" class="form-control" :class="{ 'is-invalid': form.errors.name }" required>
                    </div>
                    <div class="col-md-4">
                        <label for="code" class="form-label">Código</label>
                        <input id="code" v-model="form.code" class="form-control" :class="{ 'is-invalid': form.errors.code }">
                    </div>
                    <div class="col-md-4">
                        <label for="shiftType" class="form-label">Tipo</label>
                        <select id="shiftType" v-model="form.type" class="form-select">
                            <option value="fixed">Fijo (días de la semana)</option>
                            <option value="rotating">Rotativo (N días de trabajo × M de descanso)</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <label for="start_time" class="form-label">Entrada</label>
                        <input id="start_time" v-model="form.start_time" type="time" class="form-control" :class="{ 'is-invalid': form.errors.start_time }" required>
                    </div>
                    <div class="col-6 col-md-4">
                        <label for="end_time" class="form-label">Salida</label>
                        <input id="end_time" v-model="form.end_time" type="time" class="form-control" :class="{ 'is-invalid': form.errors.end_time }" required>
                    </div>
                    <div class="col-md-4">
                        <label for="grace_period_minutes" class="form-label">Tolerancia de tardanza (min)</label>
                        <input id="grace_period_minutes" v-model="form.grace_period_minutes" type="number" min="0" max="240" class="form-control" :class="{ 'is-invalid': form.errors.grace_period_minutes }" required>
                    </div>
                    <div class="col-6 col-md-4">
                        <label for="daily_hours" class="form-label">Horas por día</label>
                        <input id="daily_hours" v-model="form.daily_hours" type="number" step="0.25" min="0.5" max="24" class="form-control" :class="{ 'is-invalid': form.errors.daily_hours }" required>
                    </div>
                    <div class="col-6 col-md-4">
                        <label for="break_duration_minutes" class="form-label">Descanso (min)</label>
                        <input id="break_duration_minutes" v-model="form.break_duration_minutes" type="number" min="0" max="480" class="form-control" :class="{ 'is-invalid': form.errors.break_duration_minutes }" required>
                    </div>
                    <div class="col-md-4">
                        <label for="color" class="form-label">Color en calendario</label>
                        <input id="color" v-model="form.color" type="color" class="form-control form-control-color w-100">
                    </div>

                    <div v-if="form.type === 'fixed'" class="col-12">
                        <span class="form-label d-block">Días laborables</span>
                        <div class="d-flex flex-wrap gap-2" role="group" aria-label="Días laborables">
                            <template v-for="(label, i) in DAYS" :key="i">
                                <input :id="`day${i}`" v-model="form.working_days" type="checkbox" class="btn-check" :value="i" autocomplete="off">
                                <label class="btn btn-outline-primary btn-sm" :for="`day${i}`">{{ label }}</label>
                            </template>
                        </div>
                        <div v-if="form.errors.working_days" class="text-danger small mt-1">{{ form.errors.working_days }}</div>
                    </div>

                    <div v-else class="col-12">
                        <div class="row g-3">
                            <div class="col-6 col-md-4">
                                <label for="rotation_work_days" class="form-label">Días seguidos de trabajo</label>
                                <input id="rotation_work_days" v-model="form.rotation_work_days" type="number" min="1" max="60" class="form-control" :class="{ 'is-invalid': form.errors.rotation_work_days }">
                            </div>
                            <div class="col-6 col-md-4">
                                <label for="rotation_rest_days" class="form-label">Días seguidos de descanso</label>
                                <input id="rotation_rest_days" v-model="form.rotation_rest_days" type="number" min="1" max="60" class="form-control" :class="{ 'is-invalid': form.errors.rotation_rest_days }">
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-flex flex-wrap gap-4">
                        <div class="form-check form-switch">
                            <input id="break_allowed" v-model="form.break_allowed" class="form-check-input" type="checkbox" role="switch">
                            <label class="form-check-label" for="break_allowed">Permite descanso</label>
                        </div>
                        <div class="form-check form-switch">
                            <input id="is_active" v-model="form.is_active" class="form-check-input" type="checkbox" role="switch">
                            <label class="form-check-label" for="is_active">Activo</label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label for="description" class="form-label">Descripción</label>
                        <textarea id="description" v-model="form.description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                <Link href="/shifts" class="btn btn-outline-secondary">Cancelar</Link>
                <button class="btn btn-primary" :disabled="form.processing">{{ editing ? 'Guardar cambios' : 'Crear turno' }}</button>
            </div>
        </form>
    </AppLayout>
</template>
