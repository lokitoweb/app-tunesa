<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    startDate: {
        type: String,
        default: '',
    },
    endDate: {
        type: String,
        default: '',
    },
    singleDate: {
        type: Boolean,
        default: false,
    },
    allowIndefinite: {
        type: Boolean,
        default: true,
    },
    label: {
        type: String,
        default: 'Vigencia y Programación',
    },
    dropUp: {
        type: Boolean,
        default: false,
    },
    align: {
        type: String,
        default: 'right', // 'right' | 'left'
    },
    today: {
        type: String,
        default: () => new Date().toISOString().substring(0, 10),
    },
});

const emit = defineEmits(['update:startDate', 'update:endDate', 'apply']);

const isOpen = ref(false);
const popoverRef = ref(null);
const triggerRef = ref(null);

// Estado temporal mientras el usuario selecciona en el calendario
const tempStart = ref(props.startDate || props.today);
const tempEnd = ref(props.endDate || '');
const activeInput = ref('start'); // 'start' | 'end'
const activePreset = ref('');

// Mes que se está visualizando en el calendario
const viewDate = ref(new Date((props.startDate || props.today) + 'T00:00:00'));

watch(
    () => [props.startDate, props.endDate],
    ([newStart, newEnd]) => {
        tempStart.value = newStart || props.today;
        tempEnd.value = newEnd || '';
    }
);

const MONTH_NAMES = [
    'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
];

const currentMonthTitle = computed(() => {
    const m = MONTH_NAMES[viewDate.value.getMonth()];
    const y = viewDate.value.getFullYear();
    return `${m} De ${y}`;
});

// Conversión segura de YYYY-MM-DD a DD/MM/AAAA
const formatDMY = (dateStr) => {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    if (parts.length === 3) {
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }
    return dateStr;
};

// Conversión segura de Date object a YYYY-MM-DD
const toISODate = (d) => {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

// Matriz de días del mes para el calendario
const calendarDays = computed(() => {
    const year = viewDate.value.getFullYear();
    const month = viewDate.value.getMonth();

    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);

    // Lunes = 1 ... Domingo = 7
    let startingDayOfWeek = firstDay.getDay();
    if (startingDayOfWeek === 0) startingDayOfWeek = 7; // Domingo al final

    const days = [];

    // Días del mes anterior para rellenar
    const prevMonthLastDay = new Date(year, month, 0).getDate();
    for (let i = startingDayOfWeek - 1; i > 0; i--) {
        const d = prevMonthLastDay - i + 1;
        const prevDate = new Date(year, month - 1, d);
        days.push({
            dayNumber: d,
            iso: toISODate(prevDate),
            isOutside: true,
        });
    }

    // Días del mes actual
    for (let d = 1; d <= lastDay.getDate(); d++) {
        const curDate = new Date(year, month, d);
        days.push({
            dayNumber: d,
            iso: toISODate(curDate),
            isOutside: false,
        });
    }

    // Días del mes siguiente para completar cuadrícula (múltiplo de 7)
    const remaining = (7 - (days.length % 7)) % 7;
    for (let d = 1; d <= remaining; d++) {
        const nextDate = new Date(year, month + 1, d);
        days.push({
            dayNumber: d,
            iso: toISODate(nextDate),
            isOutside: true,
        });
    }

    return days;
});

function prevMonth() {
    viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() - 1, 1);
}

function nextMonth() {
    viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() + 1, 1);
}

function selectDay(day) {
    activePreset.value = '';

    if (props.singleDate) {
        tempStart.value = day.iso;
        tempEnd.value = '';
        handleApply();
        return;
    }

    if (activeInput.value === 'start') {
        tempStart.value = day.iso;
        // Si hay una fecha fin y la nueva fecha de inicio es posterior, limpiamos fecha fin
        if (tempEnd.value && tempStart.value > tempEnd.value) {
            tempEnd.value = '';
        }
        activeInput.value = 'end';
    } else {
        // Seleccionando fecha fin
        if (day.iso < tempStart.value) {
            // Si hace clic antes del inicio, convertimos esa en inicio
            tempStart.value = day.iso;
            tempEnd.value = '';
            activeInput.value = 'end';
        } else {
            tempEnd.value = day.iso;
            activeInput.value = 'start';
        }
    }
}

function isDaySelectedStart(iso) {
    return tempStart.value === iso;
}

function isDaySelectedEnd(iso) {
    return tempEnd.value === iso;
}

function isDayInRange(iso) {
    if (!tempStart.value || !tempEnd.value) return false;
    return iso > tempStart.value && iso < tempEnd.value;
}

function applyPreset(presetKey) {
    activePreset.value = presetKey;
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();
    const d = now.getDate();

    if (presetKey === 'today') {
        tempStart.value = toISODate(now);
        tempEnd.value = '';
        viewDate.value = new Date(now);
        if (props.singleDate) {
            handleApply();
        }
    } else if (presetKey === 'yesterday') {
        const yest = new Date(y, m, d - 1);
        tempStart.value = toISODate(yest);
        tempEnd.value = toISODate(yest);
        viewDate.value = new Date(yest);
    } else if (presetKey === 'last_7_days') {
        const start7 = new Date(y, m, d - 6);
        tempStart.value = toISODate(start7);
        tempEnd.value = toISODate(now);
        viewDate.value = new Date(now);
    } else if (presetKey === 'last_30_days') {
        const start30 = new Date(y, m, d - 29);
        tempStart.value = toISODate(start30);
        tempEnd.value = toISODate(now);
        viewDate.value = new Date(now);
    } else if (presetKey === 'this_month') {
        const first = new Date(y, m, 1);
        const last = new Date(y, m + 1, 0);
        tempStart.value = toISODate(first);
        tempEnd.value = toISODate(last);
        viewDate.value = new Date(first);
    } else if (presetKey === 'prev_month') {
        const first = new Date(y, m - 1, 1);
        const last = new Date(y, m, 0);
        tempStart.value = toISODate(first);
        tempEnd.value = toISODate(last);
        viewDate.value = new Date(first);
    } else if (presetKey === 'indefinite') {
        tempEnd.value = '';
    }
}

const isFlippedUp = ref(props.dropUp);

function openPopover() {
    tempStart.value = props.startDate || props.today;
    tempEnd.value = props.endDate || '';
    activeInput.value = 'start';
    viewDate.value = new Date((tempStart.value || props.today) + 'T00:00:00');

    if (triggerRef.value) {
        const rect = triggerRef.value.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        isFlippedUp.value = props.dropUp || spaceBelow < 280;
    } else {
        isFlippedUp.value = props.dropUp;
    }

    isOpen.value = true;
}

function closePopover() {
    isOpen.value = false;
}

function handleApply() {
    emit('update:startDate', tempStart.value);
    emit('update:endDate', tempEnd.value);
    emit('apply', { start: tempStart.value, end: tempEnd.value });
    closePopover();
}

function clearEnd() {
    tempEnd.value = '';
    activeInput.value = 'end';
}

function handleClickOutside(e) {
    if (
        isOpen.value &&
        popoverRef.value &&
        !popoverRef.value.contains(e.target) &&
        triggerRef.value &&
        !triggerRef.value.contains(e.target)
    ) {
        closePopover();
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div class="date-range-picker-container position-relative" :style="{ zIndex: isOpen ? 1060 : 'auto' }">
        <!-- Tarjeta Trigger Ejecutiva (Click para abrir el popover del calendario) -->
        <div 
            ref="triggerRef" 
            class="calendar-trigger-card p-3 rounded-3 border transition cursor-pointer shadow-sm d-flex align-items-center justify-content-between"
            :class="{ 'is-focused': isOpen }"
            @click="isOpen ? closePopover() : openPopover()"
        >
            <div class="d-flex align-items-center" style="gap: 12px !important;">
                <div class="calendar-trigger-icon">
                    <i class="bi bi-calendar3"></i>
                </div>
                <div>
                    <div class="calendar-trigger-sub text-uppercase fw-bold text-muted mb-0.5">
                        {{ label }}
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-bold text-dark" style="font-size: 0.925rem;">
                            {{ formatDMY(startDate) || 'Fecha inicio' }}
                        </span>
                        <template v-if="!singleDate">
                            <span class="text-muted" style="font-size: 0.85rem;">→</span>
                            <span 
                                class="fw-semibold" 
                                :class="endDate ? 'text-dark' : 'text-primary'"
                                style="font-size: 0.925rem;"
                            >
                                {{ endDate ? formatDMY(endDate) : 'Indefinido (Sin término)' }}
                            </span>
                        </template>
                    </div>
                </div>
            </div>

            <button 
                type="button" 
                class="btn btn-sm btn-outline-primary d-inline-flex align-items-center px-3 py-1.5" 
                style="border-radius: 8px; font-weight: 600; font-size: 0.8rem; gap: 8px !important;"
            >
                <i class="bi bi-calendar2-week" style="margin-right: 4px !important;"></i>
                <span>Cambiar</span>
            </button>
        </div>

        <!-- =========================================================================
             POPOVER DE CALENDARIO PRO (DISEÑO SCREENSHOT 1 — ADAPTADO DARK & LIGHT)
             ========================================================================= -->
        <div 
            v-if="isOpen" 
            ref="popoverRef" 
            class="calendar-pro-popover shadow-lg position-absolute"
            :class="{ 'popover-up': isFlippedUp, 'popover-single-date': singleDate }"
            :style="{ [isFlippedUp ? 'bottom' : 'top']: 'calc(100% + 8px)', [align === 'left' ? 'left' : 'right']: 0, zIndex: 1070 }"
        >
            <div class="calendar-pro-grid">
                <!-- Columna Izquierda: ACCESOS RÁPIDOS (Solo para rango de fechas) -->
                <div v-if="!singleDate" class="calendar-sidebar-presets">
                    <div class="calendar-presets-heading">ACCESOS RÁPIDOS</div>
                    
                    <button 
                        type="button" 
                        class="calendar-preset-btn" 
                        :class="{ 'is-active': activePreset === 'today' }" 
                        @click="applyPreset('today')"
                    >
                        <span>Hoy</span>
                        <i v-if="activePreset === 'today'" class="bi bi-check-lg"></i>
                    </button>

                    <button 
                        type="button" 
                        class="calendar-preset-btn" 
                        :class="{ 'is-active': activePreset === 'yesterday' }" 
                        @click="applyPreset('yesterday')"
                    >
                        <span>Ayer</span>
                        <i v-if="activePreset === 'yesterday'" class="bi bi-check-lg"></i>
                    </button>

                    <button 
                        type="button" 
                        class="calendar-preset-btn" 
                        :class="{ 'is-active': activePreset === 'last_7_days' }" 
                        @click="applyPreset('last_7_days')"
                    >
                        <span>Últimos 7 días</span>
                        <i v-if="activePreset === 'last_7_days'" class="bi bi-check-lg"></i>
                    </button>

                    <button 
                        type="button" 
                        class="calendar-preset-btn" 
                        :class="{ 'is-active': activePreset === 'last_30_days' }" 
                        @click="applyPreset('last_30_days')"
                    >
                        <span>Últimos 30 días</span>
                        <i v-if="activePreset === 'last_30_days'" class="bi bi-check-lg"></i>
                    </button>

                    <button 
                        type="button" 
                        class="calendar-preset-btn" 
                        :class="{ 'is-active': activePreset === 'this_month' }" 
                        @click="applyPreset('this_month')"
                    >
                        <span>Este mes</span>
                        <i v-if="activePreset === 'this_month'" class="bi bi-check-lg"></i>
                    </button>

                    <button 
                        type="button" 
                        class="calendar-preset-btn" 
                        :class="{ 'is-active': activePreset === 'prev_month' }" 
                        @click="applyPreset('prev_month')"
                    >
                        <span>Mes anterior</span>
                        <i v-if="activePreset === 'prev_month'" class="bi bi-check-lg"></i>
                    </button>

                    <button 
                        v-if="allowIndefinite && !singleDate"
                        type="button" 
                        class="calendar-preset-btn mt-2 border-top pt-2" 
                        :class="{ 'is-active': activePreset === 'indefinite' || !tempEnd }" 
                        @click="applyPreset('indefinite')"
                    >
                        <span class="text-primary fw-semibold">Sin fecha fin</span>
                        <i v-if="!tempEnd" class="bi bi-check-lg text-primary"></i>
                    </button>
                </div>

                <!-- Columna Derecha: Selector de Fechas y Calendario del Mes -->
                <div class="calendar-content-pane">
                    <!-- Fila de Inputs Desde / Hasta (Solo para rango de fechas) -->
                    <div v-if="!singleDate" class="calendar-date-inputs-row">
                        <div 
                            class="calendar-input-field cursor-pointer" 
                            :class="{ 'is-active': activeInput === 'start' }"
                            @click="activeInput = 'start'"
                        >
                            <label class="calendar-input-label">{{ singleDate ? 'Fecha' : 'Desde' }}</label>
                            <input 
                                type="text" 
                                :value="formatDMY(tempStart)" 
                                placeholder="DD/MM/AAAA" 
                                readonly 
                                style="cursor: pointer;"
                            >
                        </div>
                        <div 
                            v-if="!singleDate"
                            class="calendar-input-field cursor-pointer position-relative" 
                            :class="{ 'is-active': activeInput === 'end' }"
                            @click="activeInput = 'end'"
                        >
                            <label class="calendar-input-label">Hasta</label>
                            <input 
                                type="text" 
                                :value="tempEnd ? formatDMY(tempEnd) : 'Indefinido'" 
                                placeholder="DD/MM/AAAA" 
                                readonly 
                                style="cursor: pointer;"
                            >
                            <button 
                                v-if="tempEnd" 
                                type="button" 
                                class="btn-clear-date position-absolute end-0 top-50 translate-middle-y me-1.5" 
                                title="Quitar fecha fin"
                                @click.stop="clearEnd"
                            >
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Navegación del Mes (< y >) -->
                    <div class="calendar-nav-header">
                        <button type="button" class="calendar-nav-btn" title="Mes anterior" @click="prevMonth">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <span class="calendar-month-title">{{ currentMonthTitle }}</span>
                        <button type="button" class="calendar-nav-btn" title="Mes siguiente" @click="nextMonth">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>

                    <!-- Encabezados de Días de la Semana -->
                    <div class="calendar-days-grid mb-1">
                        <div class="calendar-day-header">L</div>
                        <div class="calendar-day-header">M</div>
                        <div class="calendar-day-header">X</div>
                        <div class="calendar-day-header">J</div>
                        <div class="calendar-day-header">V</div>
                        <div class="calendar-day-header">S</div>
                        <div class="calendar-day-header">D</div>
                    </div>

                    <!-- Cuadrícula Dinámica de Días del Mes -->
                    <div class="calendar-days-grid">
                        <div 
                            v-for="d in calendarDays" 
                            :key="d.iso + '-' + d.dayNumber"
                            class="calendar-day-cell"
                            :class="{
                                'is-outside-month': d.isOutside,
                                'is-selected-start': isDaySelectedStart(d.iso),
                                'is-selected-end': isDaySelectedEnd(d.iso),
                                'is-in-range': isDayInRange(d.iso)
                            }"
                            @click="selectDay(d)"
                        >
                            {{ d.dayNumber }}
                        </div>
                    </div>

                    <!-- Nota Informativa Inferior (Solo para rangos) -->
                    <div v-if="!singleDate" class="calendar-helper-note">
                        <span>Selecciona la fecha inicial y opcionalmente la fecha de término.</span>
                    </div>

                    <!-- Botones de Acción: Cancelar y Aplicar -->
                    <div class="calendar-footer-actions">
                        <button v-if="singleDate" type="button" class="btn btn-sm btn-link text-decoration-none p-0 text-primary small fw-semibold me-auto" @click="applyPreset('today')">
                            <i class="bi bi-calendar-event me-1"></i>Hoy
                        </button>
                        <button type="button" class="calendar-btn-cancel" @click="closePopover">
                            Cancelar
                        </button>
                        <button type="button" class="calendar-btn-apply" @click="handleApply">
                            {{ singleDate ? 'Seleccionar' : 'Aplicar' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.calendar-pro-popover.popover-single-date {
    width: 290px !important;
    max-width: 100% !important;
}

.calendar-pro-popover.popover-single-date .calendar-pro-grid {
    min-height: auto !important;
}

.calendar-pro-popover.popover-single-date .calendar-content-pane {
    padding: 0.75rem 0.85rem !important;
}

.popover-single-date .calendar-days-grid {
    gap: 3px !important;
    margin-bottom: 0.5rem !important;
}

.popover-single-date .calendar-day-cell {
    width: 30px !important;
    height: 30px !important;
    font-size: 0.825rem !important;
    border-radius: 8px !important;
}

.popover-single-date .calendar-footer-actions {
    padding-top: 0.5rem !important;
    margin-top: 0.25rem !important;
}
.calendar-trigger-card {
    background: var(--bg-surface);
    border-color: var(--border-color);
}

.calendar-trigger-card:hover,
.calendar-trigger-card.is-focused {
    border-color: var(--accent-cyan) !important;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15) !important;
}

.calendar-trigger-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    background: rgba(2, 132, 199, 0.1);
    color: var(--accent-cyan);
    border: 1px solid rgba(2, 132, 199, 0.2);
}

.calendar-trigger-sub {
    font-size: 0.68rem;
    letter-spacing: 0.05em;
}

.btn-clear-date {
    background: transparent;
    border: none;
    color: var(--text-muted);
    font-size: 1rem;
    padding: 0 4px;
    cursor: pointer;
    line-height: 1;
}

.btn-clear-date:hover {
    color: var(--accent-rose, #dc2626);
}

.calendar-btn-apply {
    background: var(--accent-cyan, #0284c7) !important;
    border: none !important;
    color: #ffffff !important;
    font-size: 0.775rem !important;
    font-weight: 700 !important;
    padding: 0.35rem 1rem !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
}

.calendar-btn-apply:hover {
    filter: brightness(1.1);
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.35);
}

.calendar-pro-popover.popover-up {
    box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.18) !important;
}

[data-bs-theme="dark"] .calendar-trigger-card {
    background: #141822 !important;
    border-color: #242c3d !important;
}

[data-bs-theme="dark"] .calendar-trigger-card .text-dark {
    color: #f1f5f9 !important;
}
</style>
