<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({
    people: Array, month: Number, months: Array, todayPeople: Array, upcoming: Array, missing: Number,
    branches: Array, settings: Object, scopes: Object, sentThisYear: Number, filters: Object, canEdit: Boolean, needsTenant: Boolean,
});

const search = ref(props.filters.search ?? '');
const settingsForm = useForm({ ...props.settings });

function apply(changes) {
    const params = { month: props.month, ...props.filters, search: search.value, ...changes };
    Object.keys(params).forEach((k) => (params[k] === null || params[k] === '' || params[k] === undefined) && delete params[k]);
    router.get('/birthdays', params, { preserveState: true, preserveScroll: true });
}
function saveSettings() {
    settingsForm.put('/birthdays/settings', { preserveScroll: true });
}
const hourLabel = (h) => `${((h + 11) % 12) + 1}:00 ${h < 12 ? 'a. m.' : 'p. m.'}`;
</script>

<template>
    <AppLayout title="Cumpleaños">
        <div class="page-header">
            <div>
                <h1>Cumpleaños</h1>
                <p>El personal ve los cumpleaños en la app (solo día y mes) y recibe un aviso el día del cumpleaños de sus compañeros.</p>
            </div>
            <a :href="`/birthdays/export?month=${month}`" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i>Exportar mes</a>
        </div>

        <div v-if="needsTenant" class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Estás en la vista de todas las empresas: elige una empresa en el selector para ver sus cumpleaños.</div>

        <div v-if="missing" class="alert alert-warning d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle"></i>
            <span>{{ missing }} colaborador(es) activos no tienen fecha de nacimiento: cárgala en <Link href="/employees">Colaboradores</Link> para que aparezcan.</span>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card border-0 h-100">
                    <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0"><i class="bi bi-balloon-heart me-1 text-danger"></i>Hoy</h2></div>
                    <div class="card-body">
                        <div v-for="p in todayPeople" :key="p.id" class="d-flex align-items-center gap-3 mb-2">
                            <img v-if="p.avatar_url" :src="p.avatar_url" :alt="p.name" class="rounded-circle" width="44" height="44" style="object-fit: cover">
                            <span v-else class="rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-subtle text-danger-emphasis fw-bold" style="width: 44px; height: 44px">{{ p.initials }}</span>
                            <div><div class="fw-semibold">{{ p.name }}</div><div class="small text-body-secondary">{{ p.position ?? '—' }}<span v-if="p.branch"> · {{ p.branch }}</span></div></div>
                        </div>
                        <p v-if="!todayPeople.length" class="text-body-secondary small mb-0">Nadie cumple años hoy.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 h-100">
                    <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0"><i class="bi bi-calendar-heart me-1"></i>Próximos 7 días</h2></div>
                    <div class="card-body">
                        <div v-for="p in upcoming" :key="p.id" class="d-flex justify-content-between small py-1 border-bottom">
                            <span class="fw-semibold">{{ p.name }}</span><span class="text-body-secondary">{{ p.date_label }} · en {{ p.days_until }} día(s)</span>
                        </div>
                        <p v-if="!upcoming.length" class="text-body-secondary small mb-0">Sin cumpleaños esta semana.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Meses">
            <button v-for="m in months" :key="m.value" type="button" role="tab" :aria-selected="m.value === month" class="btn btn-sm rounded-pill" :class="m.value === month ? 'btn-primary' : 'btn-outline-secondary'" @click="apply({ month: m.value })">
                {{ m.label }} <span class="badge rounded-pill ms-1" :class="m.value === month ? 'text-bg-light' : 'text-bg-secondary'">{{ m.count }}</span>
            </button>
        </div>

        <div class="card border-0 mb-4">
            <div class="card-body d-flex flex-wrap gap-2">
                <select class="form-select form-select-sm w-auto" aria-label="Sucursal" :value="filters.branch ?? ''" @change="apply({ branch: $event.target.value })">
                    <option value="">Todas las sucursales</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
                <form class="ms-auto d-flex gap-2" role="search" @submit.prevent="apply({})">
                    <input v-model="search" type="search" class="form-control form-control-sm" placeholder="Buscar persona o cargo" aria-label="Buscar">
                    <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Buscar"><i class="bi bi-search"></i></button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Fecha</th><th>Colaborador</th><th>Cargo</th><th>Área</th><th>Sucursal</th></tr></thead>
                    <tbody>
                        <tr v-for="p in people" :key="p.id" :class="{ 'table-danger': p.is_today }">
                            <td class="text-nowrap fw-semibold">{{ p.date_label }} <span v-if="p.is_today" class="badge text-bg-danger ms-1">Hoy</span></td>
                            <td>{{ p.name }}</td>
                            <td class="small">{{ p.position ?? '—' }}</td>
                            <td class="small">{{ p.department ?? '—' }}</td>
                            <td class="small">{{ p.branch ?? '—' }}</td>
                        </tr>
                        <tr v-if="!people.length"><td colspan="5"><div class="empty-state"><i class="bi bi-balloon"></i>Sin cumpleaños este mes.</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <form class="card border-0" @submit.prevent="saveSettings">
            <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0"><i class="bi bi-bell me-1"></i>Aviso push del día del cumpleaños</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label for="notify_colleagues" class="form-label">¿A quién avisar?</label>
                        <select id="notify_colleagues" v-model="settingsForm.notify_colleagues" class="form-select" :disabled="!canEdit">
                            <option v-for="(label, key) in scopes" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="hour" class="form-label">Hora del aviso</label>
                        <select id="hour" v-model.number="settingsForm.hour" class="form-select" :disabled="!canEdit">
                            <option v-for="h in 24" :key="h - 1" :value="h - 1">{{ hourLabel(h - 1) }}</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input id="greet_celebrant" v-model="settingsForm.greet_celebrant" class="form-check-input" type="checkbox" role="switch" :disabled="!canEdit">
                            <label class="form-check-label" for="greet_celebrant">Saludar a quien cumple años</label>
                        </div>
                    </div>
                </div>
                <p class="form-text mb-0 mt-2">Cada persona puede apagar estos avisos en la app (Mi perfil › Notificaciones). Saludos enviados este año: {{ sentThisYear }}.</p>
            </div>
            <div v-if="canEdit" class="card-footer bg-transparent border-0 text-end">
                <button type="submit" class="btn btn-primary" :disabled="settingsForm.processing">Guardar ajustes</button>
            </div>
        </form>
    </AppLayout>
</template>
