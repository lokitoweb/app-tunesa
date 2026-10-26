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
    deadline: { type: String, default: null },
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
    deadline: props.deadline,
    shifts: [...props.shifts], // cambian con la agencia (vienen también en la recarga)
});
const loading = ref(false);

// Regla 6: programar el mes hasta 5 días antes de que empiece (solo meses que aún no empiezan)
const deadlineInfo = computed(() => {
    if (!state.deadline || state.from <= props.today) return null;
    const [y, m, d] = state.deadline.split('-');
    return { label: `${d}/${m}/${y}`, overdue: props.today > state.deadline };
});
const saving = ref(false);
const dirty = ref(false);
const brush = ref(state.shifts[0]?.id ?? null); // id de turno, o null = día libre
const selected = ref(new Set());
const reason = ref('');
const toast = ref({ show: false, message: '', type: 'success' });
let toastTimer = null;

function showToast(message, type = 'success') {
    clearTimeout(toastTimer);
    toast.value = { show: true, message, type };
    toastTimer = setTimeout(() => (toast.value.show = false), 4000);
}

const shiftById = computed(() => Object.fromEntries(state.shifts.map((s) => [s.id, s])));

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

// Etiqueta de la celda: hora de entrada, corta si es en punto ("08" o "08:30")
const cellLabel = (shiftId) => (shiftById.value[shiftId]?.start || '?').replace(/:00$/, '');

// Vacaciones aprobadas (módulo de licencias): bloqueadas, el pincel no las cambia
const isLocked = (userId, date) => cell(userId, date).source === 'vacation';

// value: id de turno, o null = día libre (borrador)
function paint(userId, date, value = brush.value) {
    if (isLocked(userId, date)) return;
    state.grid[userId] = state.grid[userId] || {};
    state.grid[userId][date] = { shift_id: value, source: 'edited' };
    dirty.value = true;
}

// Pintar arrastrando el mouse. Si la primera celda ya tiene el horario del pincel, el gesto
// desmarca (vuelve a libre) en vez de pintar: clic de nuevo = quitar, igual al arrastrar.
const dragging = ref(false);
const dragValue = ref(null);
const unmarking = ref(false);
// Lo que vino del servidor: desmarcar una celda que estaba sin programar la deja como estaba
let saved = JSON.parse(JSON.stringify(state.grid));
function unpaint(userId, date) {
    if ((saved[userId]?.[date]?.source ?? 'none') !== 'none') return paint(userId, date, null);
    if (isLocked(userId, date)) return;
    state.grid[userId] = state.grid[userId] || {};
    state.grid[userId][date] = { shift_id: null, source: 'none' };
    dirty.value = true;
}
function startPaint(userId, date) {
    if (isLocked(userId, date)) return;
    const current = cell(userId, date).shift_id;
    unmarking.value = brush.value !== null && Number(current) === Number(brush.value);
    dragValue.value = unmarking.value ? null : brush.value;
    dragging.value = true;
    overPaint(userId, date);
}
function overPaint(userId, date) {
    if (!dragging.value) return;
    unmarking.value ? unpaint(userId, date) : paint(userId, date, dragValue.value);
}
function stopPaint() { dragging.value = false; }
window.addEventListener('mouseup', stopPaint);
onBeforeUnmount(() => window.removeEventListener('mouseup', stopPaint));

// Programar a varios a la vez: clic en el día pinta a los seleccionados (o a todos)
function targets() {
    return selected.value.size ? state.people.filter((p) => selected.value.has(p.id)) : state.people;
}
// Columna: si todos los elegidos ya tienen el horario del pincel ese día, se desmarca
function paintColumn(date) {
    const who = targets().filter((p) => !isLocked(p.id, date));
    const all = brush.value !== null && who.length && who.every((p) => Number(cell(p.id, date).shift_id) === Number(brush.value));
    who.forEach((p) => (all ? unpaint(p.id, date) : paint(p.id, date, brush.value)));
}
function toggle(id) {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
}
const allSelected = computed(() => state.people.length > 0 && selected.value.size === state.people.length);
function toggleAll() { selected.value = allSelected.value ? new Set() : new Set(state.people.map((p) => p.id)); }

async function load(params) {
    if (dirty.value && !(await ask({ title: 'Cambios sin guardar', message: 'Tienes cambios sin guardar en este mes. ¿Descartarlos?', confirmText: 'Descartar', cancelText: 'Volver', variant: 'warning' }))) return;
    loading.value = true;
    try {
        const res = await axios.get('/shifts/schedule', { params: { branch_id: state.branchId, month: state.month, ...params }, headers });
        Object.assign(state, { ...res.data, grid: res.data.grid || {}, holidays: res.data.holidays || {}, shifts: res.data.shifts || [] });
        saved = JSON.parse(JSON.stringify(state.grid));
        state.branchId = res.data.branchId;
        // El pincel sigue si el horario existe en la nueva agencia; si no, el primero de ella
        if (brush.value !== null && !state.shifts.some((s) => s.id === brush.value)) brush.value = state.shifts[0]?.id ?? null;
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
        confirmText: 'Guardar', cancelText: 'Cancelar', variant: 'primary', icon: 'bi bi-floppy2-fill',
    });
    if (!ok) return;
    saving.value = true;
    try {
        // Los días de vacaciones no se envían (el servidor igual los respeta)
        const rows = who.map((p) => ({
            user_id: p.id,
            days: Object.fromEntries(days.value.filter((d) => !isLocked(p.id, d.date)).map((d) => [d.date, cell(p.id, d.date).shift_id ?? null])),
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

        <div class="mb-3">
            <h1 class="h2 fw-bold text-body-emphasis mb-1">Programación de horarios</h1>
            <p class="text-body-secondary mb-0 small">
                Elige la agencia y el horario, y marca los días que asiste cada colaborador. Clic otra vez sobre la celda para quitarlo.
            </p>
        </div>

        <div v-if="deadlineInfo || unscheduled.length" class="sched-alerts mb-3">
            <div v-if="deadlineInfo" class="sched-alert" :class="deadlineInfo.overdue && unscheduled.length ? 'is-danger' : 'is-info'" role="status">
                <i class="bi bi-calendar-check"></i>
                <span v-if="deadlineInfo.overdue && unscheduled.length">Venció el plazo para programar {{ monthLabel }} ({{ deadlineInfo.label }}) y aún hay días sin programar.</span>
                <span v-else>Plazo para programar {{ monthLabel }}: hasta el {{ deadlineInfo.label }}.</span>
            </div>
            <div v-if="unscheduled.length" class="sched-alert is-warning" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <span>
                    <strong>{{ unscheduled.length }} colaborador(es) con días sin programar:</strong>
                    {{ unscheduled.slice(0, 3).map((p) => `${p.name} (${p.count})`).join(' · ') }}<template v-if="unscheduled.length > 3"> y {{ unscheduled.length - 3 }} más</template>.
                </span>
            </div>
        </div>

        <!-- Agencia (obligatoria), mes, horario para marcar y la matriz en una sola tarjeta -->
        <div class="emp-table-card shadow-sm mb-3 sched-card">
            <div class="sched-toolbar">
                <div class="sched-field">
                    <label for="sched-branch" class="sched-label">Agencia <span class="text-danger">*</span></label>
                    <div class="sched-select">
                        <i class="bi bi-geo-alt"></i>
                        <select id="sched-branch" v-model.number="state.branchId" class="form-select" aria-label="Agencia" required
                            :class="{ 'is-invalid': !state.branchId }" @change="load({ branch_id: state.branchId })">
                            <option :value="null" disabled>Elige la agencia…</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="sched-field">
                    <span class="sched-label">Mes</span>
                    <div class="sched-month" role="group" aria-label="Mes y año">
                        <button type="button" class="sched-month-btn" :disabled="loading" title="Mes anterior" aria-label="Mes anterior" @click="shiftMonth(-1)"><i class="bi bi-chevron-left"></i></button>
                        <span class="sched-month-label text-capitalize">{{ monthLabel }}</span>
                        <button type="button" class="sched-month-btn" :disabled="loading" title="Mes siguiente" aria-label="Mes siguiente" @click="shiftMonth(1)"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>
                <div class="sched-legends ms-lg-auto">
                    <span><i class="sched-legend sched-vacation"></i>Vacaciones (bloqueado)</span>
                    <span><i class="sched-legend sched-legend-none"></i>Sin programar</span>
                    <span><i class="sched-legend sched-legend-free"></i>Día libre</span>
                </div>
            </div>

            <div v-if="state.branchId" class="sched-brushbar">
                <span class="sched-label me-1">Horario para marcar</span>
                <button v-for="s in state.shifts" :key="s.id" type="button" class="sched-chip" :class="{ active: brush === s.id }"
                    :style="{ '--chip': s.color }" :aria-pressed="brush === s.id" @click="brush = s.id">
                    <span class="sched-dot" :style="{ background: s.color }"></span>
                    <span class="fw-semibold">{{ s.name }}</span>
                    <span class="sched-chip-time">{{ s.start }}–{{ s.end }}</span>
                </button>
                <button type="button" class="sched-chip" :class="{ active: brush === null }" :aria-pressed="brush === null" @click="brush = null">
                    <span class="sched-dot sched-dot-free"></span><span class="fw-semibold">Día libre</span>
                </button>
                <span v-if="!state.shifts.length" class="small text-body-secondary">Esta agencia aún no tiene horarios. Créalos en <a href="/shifts">Horarios</a>.</span>
                <span class="sched-hint d-none d-xl-inline"><i class="bi bi-hand-index me-1"></i>Clic o arrastra · clic en el número del día marca a los seleccionados</span>
            </div>

            <div v-if="!state.branchId" class="text-center text-body-secondary py-5">
                <i class="bi bi-geo-alt fs-3 d-block mb-2"></i>
                Elige la agencia para ver y programar a sus colaboradores.
            </div>
            <div v-else class="sched-scroll" :class="{ 'sk-loading': loading }" @mouseleave="stopPaint">
                <table class="table table-sm mb-0 sched-table" style="user-select: none;">
                    <thead>
                        <tr>
                            <th class="sched-name">
                                <div class="sched-person">
                                    <input type="checkbox" class="form-check-input" :checked="allSelected" aria-label="Seleccionar todos" @change="toggleAll">
                                    <span class="sched-th">Colaborador</span>
                                </div>
                            </th>
                            <th v-for="d in days" :key="d.date" class="text-center sched-day" :class="{ 'sched-weekend': d.dow === 0 || d.dow === 6, 'sched-holiday': state.holidays[d.date] }"
                                :title="state.holidays[d.date] || 'Marcar este día a los seleccionados'" role="button" @click="paintColumn(d.date)">
                                <div class="sched-dow">{{ d.label }}</div>
                                <div class="sched-num">{{ d.num }}</div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in state.people" :key="p.id">
                            <td class="sched-name">
                                <div class="sched-person">
                                    <input type="checkbox" class="form-check-input" :checked="selected.has(p.id)" :aria-label="`Seleccionar ${p.name}`" @change="toggle(p.id)">
                                    <div class="text-truncate">
                                        <div class="sched-person-name text-truncate" :title="p.name">{{ p.name }}</div>
                                        <div v-if="p.position" class="sched-person-role text-truncate">{{ p.position }}</div>
                                    </div>
                                </div>
                            </td>
                            <td v-for="d in days" :key="d.date" class="sched-cell"
                                :class="{
                                    'sched-vacation': isLocked(p.id, d.date),
                                    'sched-none': cell(p.id, d.date).source === 'none' && !state.holidays[d.date],
                                    'sched-inherited': cell(p.id, d.date).source === 'assignment',
                                }"
                                :style="cell(p.id, d.date).shift_id ? { background: shiftById[cell(p.id, d.date).shift_id]?.color } : {}"
                                :aria-disabled="isLocked(p.id, d.date) || undefined"
                                :title="isLocked(p.id, d.date) ? 'Vacaciones aprobadas: bloqueado'
                                    : cell(p.id, d.date).shift_id ? `${shiftById[cell(p.id, d.date).shift_id]?.name} (${cell(p.id, d.date).source === 'assignment' ? 'de la asignación semanal' : 'programado'}) · clic para quitar`
                                    : (cell(p.id, d.date).source === 'none' ? 'Sin programación' : 'Día libre')"
                                @mousedown.prevent="startPaint(p.id, d.date)" @mouseenter="overPaint(p.id, d.date)">
                                <template v-if="isLocked(p.id, d.date)">VAC</template>
                                <template v-else>{{ cell(p.id, d.date).shift_id ? cellLabel(cell(p.id, d.date).shift_id) : '' }}</template>
                            </td>
                        </tr>
                        <tr v-if="!state.people.length">
                            <td :colspan="days.length + 1" class="text-center text-muted py-5">No hay colaboradores activos asignados a esta agencia.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="state.branchId" class="row g-3">
            <div class="col-12 col-xl-7">
                <div class="emp-table-card shadow-sm sched-panel h-100">
                    <div class="sched-panel-title"><i class="bi bi-floppy2"></i>Guardar programación</div>
                    <label for="sched-reason" class="sched-label">Motivo <span class="text-body-secondary fw-normal">(opcional, queda en la bitácora)</span></label>
                    <input id="sched-reason" v-model="reason" type="text" class="form-control sched-input mb-3" maxlength="300" placeholder="Ej.: Programación de noviembre">
                    <div class="d-flex align-items-center flex-wrap" style="gap: 12px;">
                        <button type="button" class="btn btn-primary sched-btn" :disabled="saving || !state.people.length" @click="save">
                            <span v-if="saving" class="spinner-border spinner-border-sm"></span>
                            <i v-else class="bi bi-floppy2-fill"></i>
                            <span>Guardar {{ monthLabel }} {{ selected.size ? `(${selected.size} seleccionados)` : '(todos)' }}</span>
                        </button>
                        <span v-if="dirty" class="sched-dirty"><i class="bi bi-circle-fill"></i>Cambios sin guardar</span>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="emp-table-card shadow-sm sched-panel h-100">
                    <div class="sched-panel-title"><i class="bi bi-copy"></i>Copiar o repetir semanas</div>
                    <div class="sched-segment mb-3" role="tablist" aria-label="Copiar o repetir">
                        <button type="button" role="tab" :class="{ active: copy.mode === 'copy' }" :aria-selected="copy.mode === 'copy'" @click="copy.mode = 'copy'">Copiar semana</button>
                        <button type="button" role="tab" :class="{ active: copy.mode === 'repeat' }" :aria-selected="copy.mode === 'repeat'" @click="copy.mode = 'repeat'">Repetir hasta…</button>
                    </div>
                    <div class="sched-copy-grid">
                        <div>
                            <label class="sched-label" for="copy-source">{{ copy.mode === 'copy' ? 'Semana origen' : 'Desde' }}</label>
                            <select id="copy-source" v-model.number="copy.source" class="form-select sched-input">
                                <option v-for="(w, i) in weeks" :key="w.from" :value="i">{{ w.label }}</option>
                            </select>
                        </div>
                        <div v-if="copy.mode === 'repeat'">
                            <label class="sched-label" for="copy-source-to">Hasta la</label>
                            <select id="copy-source-to" v-model.number="copy.sourceTo" class="form-select sched-input">
                                <option v-for="(w, i) in weeks" :key="w.from" :value="i">{{ w.label }}</option>
                            </select>
                        </div>
                        <div v-if="copy.mode === 'copy'">
                            <label class="sched-label" for="copy-target">Semana destino</label>
                            <select id="copy-target" v-model.number="copy.target" class="form-select sched-input">
                                <option v-for="(w, i) in weeks" :key="w.from" :value="i">{{ w.label }}</option>
                            </select>
                        </div>
                        <div v-else>
                            <label class="sched-label" for="copy-until">Repetir hasta</label>
                            <input id="copy-until" v-model="copy.until" type="date" class="form-control sched-input" :min="state.from">
                        </div>
                        <button type="button" class="btn btn-outline-primary sched-btn" @click="repeat"><i class="bi bi-copy"></i><span>Aplicar</span></button>
                    </div>
                    <div class="small text-body-secondary mt-2">Se aplica a los seleccionados (o a todos) y usa la programación ya guardada.</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Colores del tema (claro/oscuro): variables del panel con respaldo de Bootstrap */
.sched-card { overflow: hidden; }
.sched-label { display: block; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--bs-secondary-color); margin-bottom: 4px; }
.sched-brushbar .sched-label { display: inline; margin: 0; }

.sched-toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px; padding: 14px 16px; border-bottom: 1px solid var(--bs-border-color); }
.sched-field { min-width: 0; }
.sched-select { position: relative; width: min(320px, 100%); }
.sched-select > i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--bs-secondary-color); pointer-events: none; z-index: 1; }
.sched-select .form-select { height: 40px; padding-left: 36px; border-radius: 10px; }
.sched-month { display: inline-flex; align-items: stretch; height: 40px; border: 1px solid var(--bs-border-color); border-radius: 10px; overflow: hidden; background: var(--bs-body-bg); }
.sched-month-btn { width: 38px; border: 0; background: transparent; color: var(--bs-body-color); transition: background-color .15s; }
.sched-month-btn:hover:not(:disabled) { background: var(--bs-tertiary-bg); }
.sched-month-btn:disabled { opacity: .5; }
.sched-month-label { display: inline-flex; align-items: center; padding: 0 14px; min-width: 150px; justify-content: center; font-weight: 700; color: var(--bs-emphasis-color); border-left: 1px solid var(--bs-border-color); border-right: 1px solid var(--bs-border-color); }
.sched-legends { display: flex; flex-wrap: wrap; gap: 14px; font-size: 0.78rem; color: var(--bs-secondary-color); }
.sched-legends span { display: inline-flex; align-items: center; gap: 6px; }
.sched-legend { width: 14px; height: 14px; border-radius: 4px; display: inline-block; }
.sched-legend-none { box-shadow: inset 0 0 0 2px rgba(220, 38, 38, 0.6); }
.sched-legend-free { border: 1px solid var(--bs-border-color); background: var(--bs-body-bg); }

.sched-brushbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 12px 16px; border-bottom: 1px solid var(--bs-border-color); background: var(--bs-tertiary-bg); }
.sched-chip {
    display: inline-flex; align-items: center; gap: 7px; height: 34px; padding: 0 12px;
    border: 1px solid var(--bs-border-color); border-radius: 999px; background: var(--bs-body-bg); color: var(--bs-body-color);
    font-size: 0.82rem; transition: border-color .15s, box-shadow .15s;
}
.sched-chip:hover { border-color: var(--chip, var(--bs-secondary-color)); }
.sched-chip.active { border-color: var(--chip, var(--bs-primary)); box-shadow: 0 0 0 2px var(--chip, var(--bs-primary)); }
.sched-chip:focus-visible { outline: 2px solid var(--bs-primary); outline-offset: 2px; }
.sched-chip-time { color: var(--bs-secondary-color); font-variant-numeric: tabular-nums; }
.sched-hint { margin-left: auto; font-size: 0.75rem; color: var(--bs-secondary-color); }
.sched-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.sched-dot-free { background: var(--bs-body-bg); border: 1.5px solid var(--bs-secondary-color); }

/* Matriz: columnas fijas que reparten el ancho (sin scroll en pantallas normales) */
.sched-scroll { overflow-x: auto; }
.sched-table { table-layout: fixed; width: 100%; min-width: 860px; --bs-table-bg: transparent; }
.sched-table th, .sched-table td { border-color: var(--bs-border-color); padding: 0; vertical-align: middle; }
.sched-name { position: sticky; left: 0; z-index: 2; width: 200px; background: var(--bs-body-bg) !important; padding: 6px 10px !important; }
.sched-person { display: flex; align-items: center; gap: 8px; min-width: 0; }
.sched-person .form-check-input { flex-shrink: 0; margin: 0; }
.sched-person-name { font-size: 0.8rem; font-weight: 600; color: var(--bs-emphasis-color); }
.sched-person-role { font-size: 0.7rem; color: var(--bs-secondary-color); }
.sched-th { font-size: 0.72rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--bs-secondary-color); }
.sched-day { cursor: pointer; text-align: center; padding: 6px 0 !important; color: var(--bs-body-color); }
.sched-day:hover { background: var(--bs-tertiary-bg); }
.sched-dow { font-size: 0.62rem; color: var(--bs-secondary-color); line-height: 1; }
.sched-num { font-size: 0.78rem; font-weight: 700; line-height: 1.3; }
.sched-weekend { background: rgba(148, 163, 184, 0.12); }
.sched-holiday { background: rgba(239, 68, 68, 0.12); }
.sched-cell { height: 32px; text-align: center; font-size: 0.6rem; font-weight: 700; color: #fff; cursor: crosshair; border: 1px solid var(--bs-border-color); overflow: hidden; white-space: nowrap; font-variant-numeric: tabular-nums; }
.sched-inherited { opacity: 0.55; }
.sched-none { box-shadow: inset 0 0 0 2px rgba(220, 38, 38, 0.55); }
@media (max-width: 1399.98px) { .sched-name { width: 170px; } .sched-cell { font-size: 0.55rem; } }

.sched-alerts { display: flex; flex-direction: column; gap: 8px; }
.sched-alert { display: flex; align-items: flex-start; gap: 10px; padding: 10px 14px; border-radius: 10px; font-size: 0.82rem; border: 1px solid; }
.sched-alert > i { margin-top: 1px; }
.sched-alert.is-info { background: var(--bs-info-bg-subtle); border-color: var(--bs-info-border-subtle); color: var(--bs-info-text-emphasis); }
.sched-alert.is-warning { background: var(--bs-warning-bg-subtle); border-color: var(--bs-warning-border-subtle); color: var(--bs-warning-text-emphasis); }
.sched-alert.is-danger { background: var(--bs-danger-bg-subtle); border-color: var(--bs-danger-border-subtle); color: var(--bs-danger-text-emphasis); }

.sched-panel { padding: 16px; }
.sched-panel-title { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 0.9rem; color: var(--bs-emphasis-color); margin-bottom: 12px; }
.sched-panel-title i { color: var(--bs-primary); }
.sched-input { height: 40px; border-radius: 10px; }
.sched-btn { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 18px; border-radius: 10px; font-weight: 600; }
.sched-dirty { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; font-weight: 600; color: var(--bs-warning-text-emphasis); }
.sched-dirty i { font-size: 0.45rem; }
.sched-segment { display: inline-flex; padding: 4px; border-radius: 10px; background: var(--bs-tertiary-bg); gap: 4px; }
.sched-segment button { border: 0; background: transparent; padding: 6px 14px; border-radius: 8px; font-size: 0.82rem; font-weight: 600; color: var(--bs-secondary-color); }
.sched-segment button.active { background: var(--bs-body-bg); color: var(--bs-primary); box-shadow: 0 1px 3px rgba(15, 23, 42, 0.15); }
.sched-copy-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; align-items: end; }
/* Vacaciones aprobadas: franja diagonal y sin cursor de pincel (no se puede sobrescribir) */
.sched-vacation {
    cursor: not-allowed;
    color: #fff;
    background: repeating-linear-gradient(135deg, #7c3aed 0 6px, #6d28d9 6px 12px) !important;
    font-size: 0.6rem;
    letter-spacing: 0.02em;
}
</style>
