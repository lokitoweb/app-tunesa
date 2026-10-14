<script setup>
// Selector de público (a quién se le muestra un contenido en la app).
// Reglas: todo el personal, sucursal, área, cargo o persona; basta con cumplir una.
// Opciones: Core\Support\Audience::formOptions() (types, branches, departments, positions, employees).
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: Array, required: true },
    options: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    idPrefix: { type: String, default: 'aud' },
});

const targets = computed(() => props.modelValue);
const hasAll = computed(() => targets.value.some((t) => t.type === 'all'));
const err = (key) => props.errors[key];

function firstFor(type) {
    return {
        id: type === 'branch' ? props.options.branches[0]?.id ?? null
            : type === 'department' ? props.options.departments[0]?.id ?? null
                : type === 'user' ? props.options.employees[0]?.id ?? null : null,
        value: type === 'position' ? props.options.positions[0] ?? '' : null,
    };
}
function add() {
    targets.value.push({ type: 'branch', ...firstFor('branch') });
}
function onType(t) {
    Object.assign(t, firstFor(t.type));
    if (t.type === 'all') targets.value.splice(0, targets.value.length, t);
}
</script>

<template>
    <div>
        <div v-if="err('targets')" class="alert alert-danger py-2 small">{{ err('targets') }}</div>
        <div v-for="(t, i) in targets" :key="i" class="d-flex flex-wrap gap-2 mb-2">
            <select v-model="t.type" class="form-select w-auto" :aria-label="`Tipo de público ${i + 1}`" @change="onType(t)">
                <option v-for="(label, key) in options.types" :key="key" :value="key">{{ label }}</option>
            </select>
            <select v-if="t.type === 'branch'" v-model="t.id" class="form-select flex-grow-1 w-auto" :class="{ 'is-invalid': err(`targets.${i}.id`) }" aria-label="Sucursal">
                <option v-for="b in options.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
            <select v-else-if="t.type === 'department'" v-model="t.id" class="form-select flex-grow-1 w-auto" :class="{ 'is-invalid': err(`targets.${i}.id`) }" aria-label="Área">
                <option v-for="d in options.departments" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
            <input v-else-if="t.type === 'position'" v-model="t.value" class="form-control flex-grow-1 w-auto" :class="{ 'is-invalid': err(`targets.${i}.value`) }" :list="`${idPrefix}-positions`" placeholder="Ej.: Flebotomista, Analista de laboratorio" maxlength="150" aria-label="Cargo">
            <select v-else-if="t.type === 'user'" v-model="t.id" class="form-select flex-grow-1 w-auto" :class="{ 'is-invalid': err(`targets.${i}.id`) }" aria-label="Persona">
                <option v-for="e in options.employees" :key="e.id" :value="e.id">{{ e.last_name }}, {{ e.first_name }}{{ e.employee_code ? ` · ${e.employee_code}` : '' }}</option>
            </select>
            <span v-else class="form-control-plaintext flex-grow-1 w-auto text-body-secondary small">Todo el personal activo, incluidas las nuevas contrataciones.</span>
            <button type="button" class="btn btn-outline-danger" aria-label="Quitar regla" :disabled="targets.length === 1" @click="targets.splice(i, 1)"><i class="bi bi-x-lg"></i></button>
        </div>
        <datalist :id="`${idPrefix}-positions`"><option v-for="p in options.positions" :key="p" :value="p" /></datalist>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <p class="form-text mb-0">Se muestra a quien cumpla al menos una regla. Quien entre después a esa sucursal, área o cargo también lo verá.</p>
            <button v-if="!hasAll" type="button" class="btn btn-sm btn-outline-primary" @click="add"><i class="bi bi-plus-lg me-1"></i>Agregar regla</button>
        </div>
    </div>
</template>
