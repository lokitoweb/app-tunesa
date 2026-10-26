<script setup>
import { ref, computed, reactive, onBeforeUnmount } from 'vue';
import axios from 'axios';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

// Programación por calendario (QA Ronda 2, HQ-05): agencia → horario → días.
// Los días en blanco del mes guardado quedan como día libre.
const props = defineProps({
    branches: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
    branchId: { type: Number, default: null },
    month: { type: String, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
    people: { type: Array, default: () => [] },
    grid: { type: [Object, Array], default: () => ({}) },
    holidays: { type: [Object, Array], default: () => ({}) },
    today: { type: String, required: true },
    flagEnabled: { type: Boolean, default: false },
});

const { ask } = useConfirm();
const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

const state = reactive({
    branchId: props.branchId,
    month: props.month,
    from: props.from,
    to: props.to,
    people: [...props.people],
    grid: JSON.parse(JSON.stringify(props.grid || {})),
    holidays: { ...(props.holidays || {}) },
});
const loading = ref(false);
const saving = ref(false);
const dirty = ref(false);
const brush = ref(props.shifts[0]?.id ?? null); // id de turno, o null = día libre
const selected = ref(new Set());
const reason = ref('');
const toast = ref({ show: false, message: '', type: 'success' });
let toastTimer = null;

function showToast(message, type = 'success') {
    clearTimeout(toastTimer);
    toast.value = { show: true, message, type };
    toastTimer = setTimeout(() => (toast.value.show = false), 4000);
}

const shiftById = computed(() => Object.fromEntries(props.shifts.map((s) => [s.id, s])));

const days = computed(() => {
    const out = [];
    const end = new Date(state.to + 'T00:00:00');
    for (let d = new Date(state.from + 'T00:00:00'); d <= end; d.setDate(d.getDate() + 1)) {
        const local = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        out.push({ date: local, num: d.getDate(), dow: d.getDay(), label: ['D', 'L', 'M', 'M', 'J', 'V', 'S'][d.getDay()] });
    }
    return out;
});

// Semanas del mes (lunes a domingo) para copiar o repetir
const weeks = computed(() => {
    const list = [];
    days.value.forEach((d) => {
        if (d.dow === 1 || !list.length) list.push({ from: d.date, to: d.date });
        list[list.length - 1].to = d.date;
    });
    return list.map((w, i) => ({ ...w, label: `Semana ${i + 1} (${w.from.substring(8)}–${w.to.substring(8)})` }));
});

const monthLabel = computed(() => {
    const [y, m] = state.month.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('es-PE', { month: 'long', year: 'numeric' });
});

function cell(userId, date) {
    return state.grid[userId]?.[date] ?? { shift_id: null, source: 'none' };
}

// Alerta: colaboradores con días sin ninguna programación (ni día libre ni horario)
const unscheduled = computed(() => state.people
    .map((p) => ({ ...p, count: days.value.filter((d) => cell(p.id, d.date).source === 'none' && !state.holidays[d.date]).length }))
    .filter((p) => p.count > 0));

function paint(userId, date) {
    state.grid[userId] = state.grid[userId] || {};
    state.grid[userId][date] = { shift_id: brush.value, source: 'edited' };
    dirty.value = true;
}

// Pintar arrastrando el mouse
const dragging = ref(false);
function startPaint(userId, date) { dragging.value = true; paint(userId, date); }
function overPaint(userId, date) { if (dragging.value) paint(userId, date); }
function stopPaint() { dragging.value = false; }
window.addEventListener('mouseup', stopPaint);
onBeforeUnmount(() => window.removeEventListener('mouseup', stopPaint));

// Programar a varios a la vez: clic en el día pinta a los seleccionados (o a todos)
function targets() {
    return selected.value.size ? state.people.filter((p) => selected.value.has(p.id)) : state.people;
}
function paintColumn(date) { targets().forEach((p) => paint(p.id, date)); }
function toggle(id) {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
}
const allSelected = computed(() => state.people.length > 0 && selected.value.size === state.people.length);
function toggleAll() { selected.value = allSelected.value ? new Set() : new Set(state.people.map((p) => p.id)); }

async function load(params) {
    if (dirty.value && !(await ask({ title: 'Cambios sin guardar', message: 'Tienes cambios sin guardar en este mes. ¿Descartarlos?', confirmText: 'Descartar', cancelText: 'Volver', variant: 'danger' }))) return;
    loading.value = true;
    try {
        const res = await axios.get('/shifts/schedule', { params: { branch_id: state.branchId, month: state.month, ...params }, headers });
        Object.assign(state, { ...res.data, grid: res.data.grid || {}, holidays: res.data.holidays || {} });
        state.branchId = res.data.branchId;
        selected.value = new Set();
        dirty.value = false;
    } catch (e) {
        showToast('No se pudo cargar la programación.', 'danger');
    } finally {
        loading.value = false;
    }
}
function shiftMonth(delta) {
    const [y, m] = state.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    load({ month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
}

function errorText(e, fallback) {
    const errs = e.response?.data?.errors;
    return errs ? Object.values(errs).flat()[0] : (e.response?.data?.message || fallback);
}

async function save() {
    const who = targets();
    if (!who.length) return;
    const ok = await ask({
        title: 'Guardar programación',
        message: `Se guardará ${monthLabel.value} completo para ${who.length} colaborador(es) de la agencia. Los días en blanco quedarán como día libre.`,
        confirmText: 'Guardar', cancelText: 'Cancelar',
    });
    if (!ok) return;
    saving.value = true;
    try {
        const rows = who.map((p) => ({
            user_id: p.id,
            days: Object.fromEntries(days.value.map((d) => [d.date, cell(p.id, d.date).shift_id ?? null])),
        }));
        const res = await axios.post('/shifts/schedule', { branch_id: state.branchId, from: state.from, to: state.to, reason: reason.value, rows }, { headers });
        dirty.value = false;
        showToast(res.data.message);
        await load({});
    } catch (e) {
        showToast(errorText(e, 'No se pudo guardar la programación.'), 'danger');
    } finally {
        saving.value = false;
    }
}

// Copiar semana / repetir semanas hasta una fecha
const copy = reactive({ mode: 'copy', source: 0, sourceTo: 0, target: 1, until: '' });
async function repeat() {
    if (dirty.value) { showToast('Guarda primero los cambios del mes: la copia usa lo guardado.', 'danger'); return; }
    const w = weeks.value;
    const src = w[copy.source];
    const srcTo = copy.mode === 'copy' ? src : w[Math.max(copy.source, copy.sourceTo)];
    let targetFrom; let until;
    if (copy.mode === 'copy') {
        const tgt = w[copy.target];
        if (!tgt || copy.target <= copy.source) { showToast('Elige una semana destino posterior a la de origen.', 'danger'); return; }
        targetFrom = tgt.from; until = tgt.to;
    } else {
        const next = new Date(srcTo.to + 'T00:00:00'); next.setDate(next.getDate() + 1);
        targetFrom = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`;
        until = copy.until;
        if (!until) { showToast('Indica hasta qué fecha repetir.', 'danger'); return; }
    }
    try {
        const res = await axios.post('/shifts/schedule/repeat', {
            branch_id: state.branchId, user_ids: [...selected.value], source_from: src.from, source_to: srcTo.to,
            target_from: targetFrom, until, reason: reason.value,
        }, { headers });
        showToast(res.data.message);
        await load({});
    } catch (e) {
        showToast(errorText(e, 'No se pudo copiar la programación.'), 'danger');
    }
}
</script>

<template>
    <AppLayout title="Programación de horarios">
        <div v-if="toast.show" class="position-fixed top-0 end-0 p-4" style="z-index: 9999;">
            <div class="toast show text-white border-0 shadow-lg px-3 py-2" :class="toast.type === 'danger' ? 'bg-danger' : 'bg-success'" role="alert" style="border-radius: 10px; min-width: 280px;">
                <div class="d-flex align-items-center justify-content-between" style="gap: 10px;">
                    <span style="font-size: 0.875rem; font-weight: 500;">{{ toast.message }}</span>
                    <button type="button" class="btn-close btn-close-white" @click="toast.show = false" aria-label="Cerrar"></button>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-start justify-content-between flex-wrap mb-3" style="gap: 1rem;">
            <div>
                <h1 class="h2 fw-bold text-dark mb-1">Programación de horarios</h1>
                <p class="text-secondary mb-0" style="font-size: 0.875rem;">
                    1. Elige la agencia · 2. Elige el horario · 3. Marca los días que asiste con ese horario · 4. Repite con otro horario. Los días en blanco son días sin labor.
                </p>
            </div>
            <div class="d-flex align-items-center flex-wrap" style="gap: 10px;">
                <select v-model.number="state.branchId" class="form-select" style="min-width: 220px; height: 38px;" aria-label="Agencia" @change="load({ branch_id: state.branchId })">
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
                <div class="btn-group shadow-sm" role="group" aria-label="Mes">
                    <button type="button" class="btn btn-outline-secondary" :disabled="loading" title="Mes anterior" @click="shiftMonth(-1)"><i class="bi bi-chevron-left"></i></button>
                    <span class="btn btn-outline-secondary disabled fw-bold text-dark text-capitalize" style="opacity: 1; min-width: 150px;">{{ monthLabel }}</span>
                    <button type="button" class="btn btn-outline-secondary" :disabled="loading" title="Mes siguiente" @click="shiftMonth(1)"><i class="bi bi-chevron-right"></i></button>
                </div>
            </div>
        </div>

        <div v-if="!flagEnabled" class="alert alert-warning py-2 small" role="status">
            <i class="bi bi-info-circle me-1"></i>
            Esta empresa todavía evalúa la asistencia con la asignación semanal. Lo que programes aquí se guarda y empieza a regir cuando se active el horario por fecha (<code>schedule_v2</code>).
        </div>

        <div v-if="unscheduled.length" class="alert alert-danger py-2 small" role="alert">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>{{ unscheduled.length }} colaborador(es) con días sin programación</strong> en {{ monthLabel }}:
            {{ unscheduled.map((p) => `${p.name} (${p.count})`).join(' · ') }}.
            Asigna un horario o márcalos como día libre.
        </div>

        <!-- 2. Elegir el horario (pincel) -->
        <div class="emp-table-card shadow-sm p-3 mb-3">
            <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
                <span class="small fw-semibold text-secondary me-1">Horario para marcar:</span>
                <button v-for="s in shifts" :key="s.id" type="button" class="btn btn-sm sched-chip" :class="{ active: brush === s.id }"
                    :style="{ '--chip': s.color }" :aria-pressed="brush === s.id" @click="brush = s.id">
                    <span class="sched-dot" :style="{ background: s.color }"></span>{{ s.name }} <span class="text-muted">{{ s.start }}–{{ s.end }}</span>
                </button>
                <button type="button" class="btn btn-sm sched-chip" :class="{ active: brush === null }" :aria-pressed="brush === null" @click="brush = null">
                    <span class="sched-dot sched-dot-free"></span>Día libre
                </button>
            </div>
            <div class="small text-muted mt-2">
                Clic o arrastra sobre las celdas para marcar. Clic en el número del día marca ese día a los colaboradores seleccionados (o a todos si no seleccionas ninguno).
            </div>
        </div>

        <!-- 3. Marcar los días -->
        <div class="emp-table-card shadow-sm mb-3">
            <div class="emp-table-responsive" :class="{ 'sk-loading': loading }" style="overflow-x: auto;" @mouseleave="stopPaint">
                <table class="table table-sm mb-0 sched-table" style="user-select: none;">
                    <thead>
                        <tr>
                            <th class="sched-name">
                                <input type="checkbox" class="form-check-input me-2" :checked="allSelected" aria-label="Seleccionar todos" @change="toggleAll">
                                Colaborador
                            </th>
                            <th v-for="d in days" :key="d.date" class="text-center sched-day" :class="{ 'sched-weekend': d.dow === 0 || d.dow === 6, 'sched-holiday': state.holidays[d.date] }"
                                :title="state.holidays[d.date] || 'Marcar este día a los seleccionados'" role="button" @click="paintColumn(d.date)">
                                <div class="small text-muted">{{ d.label }}</div>
                                <div class="fw-bold">{{ d.num }}</div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in state.people" :key="p.id">
                            <td class="sched-name">
                                <input type="checkbox" class="form-check-input me-2" :checked="selected.has(p.id)" :aria-label="`Seleccionar ${p.name}`" @change="toggle(p.id)">
                                <span class="fw-semibold">{{ p.name }}</span>
                                <div v-if="p.position" class="small text-muted ms-4">{{ p.position }}</div>
                            </td>
                            <td v-for="d in days" :key="d.date" class="sched-cell"
                                :class="{ 'sched-none': cell(p.id, d.date).source === 'none' && !state.holidays[d.date], 'sched-inherited': cell(p.id, d.date).source === 'assignment' }"
                                :style="cell(p.id, d.date).shift_id ? { background: shiftById[cell(p.id, d.date).shift_id]?.color } : {}"
                                :title="cell(p.id, d.date).shift_id ? `${shiftById[cell(p.id, d.date).shift_id]?.name} (${cell(p.id, d.date).source === 'assignment' ? 'de la asignación semanal' : 'programado'})` : (cell(p.id, d.date).source === 'none' ? 'Sin programación' : 'Día libre')"
                                @mousedown.prevent="startPaint(p.id, d.date)" @mouseenter="overPaint(p.id, d.date)">
                                {{ cell(p.id, d.date).shift_id ? (shiftById[cell(p.id, d.date).shift_id]?.name || '?').substring(0, 3) : '' }}
                            </td>
                        </tr>
                        <tr v-if="!state.people.length">
                            <td :colspan="days.length + 1" class="text-center text-muted py-5">No hay colaboradores activos asignados a esta agencia.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <div class="emp-table-card shadow-sm p-3 h-100">
                    <label for="sched-reason" class="form-label small fw-semibold">Motivo (queda en la bitácora) <span class="text-danger">*</span></label>
                    <input id="sched-reason" v-model="reason" type="text" class="form-control mb-3" maxlength="300" placeholder="Ej.: Programación de noviembre, Ag. Trujillo">
                    <button type="button" class="btn btn-primary" :disabled="saving || !state.people.length" @click="save">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="bi bi-check2-circle me-1"></i>
                        Guardar {{ monthLabel }} {{ selected.size ? `(${selected.size} seleccionados)` : '(todos)' }}
                    </button>
                    <span v-if="dirty" class="small text-warning ms-2"><i class="bi bi-pencil"></i> Cambios sin guardar</span>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="emp-table-card shadow-sm p-3 h-100">
                    <div class="btn-group btn-group-sm mb-2" role="group" aria-label="Copiar o repetir">
                        <button type="button" class="btn" :class="copy.mode === 'copy' ? 'btn-primary' : 'btn-outline-primary'" @click="copy.mode = 'copy'">Copiar semana</button>
                        <button type="button" class="btn" :class="copy.mode === 'repeat' ? 'btn-primary' : 'btn-outline-primary'" @click="copy.mode = 'repeat'">Repetir semanas hasta…</button>
                    </div>
                    <div class="d-flex flex-wrap align-items-end" style="gap: 8px;">
                        <div>
                            <label class="form-label small mb-1">{{ copy.mode === 'copy' ? 'Semana origen' : 'Desde' }}</label>
                            <select v-model.number="copy.source" class="form-select form-select-sm">
                                <option v-for="(w, i) in weeks" :key="w.from" :value="i">{{ w.label }}</option>
                            </select>
                        </div>
                        <div v-if="copy.mode === 'repeat'">
                            <label class="form-label small mb-1">Hasta la</label>
                            <select v-model.number="copy.sourceTo" class="form-select form-select-sm">
                                <option v-for="(w, i) in weeks" :key="w.from" :value="i">{{ w.label }}</option>
                            </select>
                        </div>
                        <div v-if="copy.mode === 'copy'">
                            <label class="form-label small mb-1">Semana destino</label>
                            <select v-model.number="copy.target" class="form-select form-select-sm">
                                <option v-for="(w, i) in weeks" :key="w.from" :value="i">{{ w.label }}</option>
                            </select>
                        </div>
                        <div v-else>
                            <label class="form-label small mb-1">Repetir hasta</label>
                            <input v-model="copy.until" type="date" class="form-control form-control-sm" :min="state.from">
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" @click="repeat"><i class="bi bi-copy me-1"></i>Aplicar</button>
                    </div>
                    <div class="small text-muted mt-2">Se aplica a los seleccionados (o a todos) y usa la programación ya guardada.</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.sched-table th, .sched-table td { border-color: var(--border-color, #e2e8f0); }
.sched-name { position: sticky; left: 0; background: var(--bg-surface, #fff); min-width: 220px; z-index: 2; white-space: nowrap; }
.sched-day { min-width: 34px; cursor: pointer; }
.sched-weekend { background: rgba(148, 163, 184, 0.12); }
.sched-holiday { background: rgba(239, 68, 68, 0.12); }
.sched-cell { min-width: 34px; height: 34px; text-align: center; font-size: 0.7rem; font-weight: 700; color: #fff; cursor: crosshair; border: 1px solid var(--border-color, #e2e8f0); }
.sched-inherited { opacity: 0.55; }
.sched-none { box-shadow: inset 0 0 0 2px rgba(220, 38, 38, 0.55); }
.sched-chip { border: 1.5px solid var(--border-color, #cbd5e1); border-radius: 20px; display: inline-flex; align-items: center; gap: 6px; }
.sched-chip.active { border-color: var(--chip, #0d6efd); box-shadow: 0 0 0 2px var(--chip, #0d6efd); font-weight: 600; }
.sched-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
.sched-dot-free { background: #fff; border: 1.5px solid #94a3b8; }
</style>
