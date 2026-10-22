<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    placeholder: {
        type: String,
        default: 'dd/mm/aaaa',
    },
    id: {
        type: String,
        default: () => 'date_picker_' + Math.random().toString(36).substring(2, 9),
    },
    icon: {
        type: String,
        default: 'bi bi-calendar-event',
    },
    allowClear: {
        type: Boolean,
        default: false,
    },
    clearLabel: {
        type: String,
        default: 'Quitar fecha',
    },
    align: {
        type: String,
        default: 'left', // 'left' | 'right'
    },
    dropUp: {
        type: Boolean,
        default: false,
    },
    today: {
        type: String,
        default: () => new Date().toISOString().substring(0, 10),
    },
});

const emit = defineEmits(['update:modelValue', 'change']);

const isOpen = ref(false);
const containerRef = ref(null);
const popoverRef = ref(null);
const triggerRef = ref(null);
const isFlippedUp = ref(props.dropUp);

// Mes y año que se está visualizando en el calendario
const viewDate = ref(new Date((props.modelValue || props.today) + 'T00:00:00'));

watch(
    () => props.modelValue,
    (newVal) => {
        if (newVal) {
            viewDate.value = new Date(newVal + 'T00:00:00');
        }
    }
);

const MONTH_NAMES = [
    'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
];

const currentMonthTitle = computed(() => {
    const m = MONTH_NAMES[viewDate.value.getMonth()];
    const y = viewDate.value.getFullYear();
    return `${m} de ${y}`;
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

const displayDate = computed(() => formatDMY(props.modelValue));

// Conversión segura de Date object a YYYY-MM-DD
const toISODate = (d) => {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

// Matriz de días del mes
const calendarDays = computed(() => {
    const year = viewDate.value.getFullYear();
    const month = viewDate.value.getMonth();

    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);

    // Lunes = 1 ... Domingo = 7
    let startingDayOfWeek = firstDay.getDay();
    if (startingDayOfWeek === 0) startingDayOfWeek = 7;

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
        const iso = toISODate(curDate);
        days.push({
            dayNumber: d,
            iso,
            isOutside: false,
            isToday: iso === props.today,
            isSelected: iso === props.modelValue,
        });
    }

    // Días del mes siguiente para completar múltiplo de 7
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
    emit('update:modelValue', day.iso);
    emit('change', day.iso);
    closePopover();
}

function selectToday() {
    emit('update:modelValue', props.today);
    emit('change', props.today);
    closePopover();
}

function clearDate() {
    emit('update:modelValue', '');
    emit('change', '');
    closePopover();
}

function togglePopover() {
    if (isOpen.value) {
        closePopover();
    } else {
        openPopover();
    }
}

function openPopover() {
    viewDate.value = new Date((props.modelValue || props.today) + 'T00:00:00');

    if (triggerRef.value) {
        const rect = triggerRef.value.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        isFlippedUp.value = props.dropUp || spaceBelow < 290;
    } else {
        isFlippedUp.value = props.dropUp;
    }

    isOpen.value = true;
}

function closePopover() {
    isOpen.value = false;
}

function handleClickOutside(e) {
    if (
        isOpen.value &&
        containerRef.value &&
        !containerRef.value.contains(e.target)
    ) {
        closePopover();
    }
}

function handleKeydown(e) {
    if (e.key === 'Escape' && isOpen.value) {
        closePopover();
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    document.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
    document.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <div ref="containerRef" class="modern-datepicker-container position-relative" :style="{ zIndex: isOpen ? 1060 : 'auto' }">
        <!-- Input Normal Elegante (Exacto como los otros campos del formulario) -->
        <div
            ref="triggerRef"
            class="modern-input-wrapper position-relative cursor-pointer"
            :class="{ 'is-focused': isOpen }"
            @click="togglePopover"
        >
            <i :class="[icon, 'modern-input-icon']"></i>
            <input
                :id="id"
                type="text"
                class="modern-input-control modern-datepicker-input cursor-pointer"
                :class="{ 'has-clear': allowClear && modelValue }"
                readonly
                :value="displayDate"
                :placeholder="placeholder"
            >
            <div class="modern-datepicker-trailing">
                <button
                    v-if="allowClear && modelValue"
                    type="button"
                    class="btn btn-sm p-0 border-0 bg-transparent btn-clear-icon"
                    :title="clearLabel"
                    @click.stop="clearDate"
                >
                    <i class="bi bi-x-circle-fill"></i>
                </button>
                <i class="bi bi-calendar3 modern-datepicker-trailing-icon"></i>
            </div>
        </div>

        <!-- =========================================================================
             POPOVER DE CALENDARIO PRO (SOLO APARECE AL ABRIR EL CAMPO)
             ========================================================================= -->
        <div
            v-if="isOpen"
            ref="popoverRef"
            class="modern-datepicker-popover shadow-lg position-absolute"
            :class="{ 'popover-up': isFlippedUp }"
            :style="{
                [isFlippedUp ? 'bottom' : 'top']: 'calc(100% + 6px)',
                [align === 'right' ? 'right' : 'left']: 0,
            }"
        >
            <!-- Cabecera del Mes con Navegación -->
            <div class="cal-nav-header">
                <button type="button" class="cal-nav-btn" title="Mes anterior" @click.stop="prevMonth">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <span class="cal-month-title">{{ currentMonthTitle }}</span>
                <button type="button" class="cal-nav-btn" title="Mes siguiente" @click.stop="nextMonth">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>

            <!-- Días de la Semana -->
            <div class="cal-weekdays-grid">
                <span>Lu</span>
                <span>Ma</span>
                <span>Mi</span>
                <span>Ju</span>
                <span>Vi</span>
                <span>Sá</span>
                <span>Do</span>
            </div>

            <!-- Cuadrícula Dinámica de Días -->
            <div class="cal-days-grid">
                <button
                    v-for="d in calendarDays"
                    :key="d.iso + '-' + d.dayNumber"
                    type="button"
                    class="cal-day-cell"
                    :class="{
                        'is-outside': d.isOutside,
                        'is-today': d.isToday,
                        'is-selected': d.isSelected,
                    }"
                    @click.stop="selectDay(d)"
                >
                    {{ d.dayNumber }}
                </button>
            </div>

            <!-- Barra Inferior de Acciones Rápidas -->
            <div class="cal-footer-bar">
                <button type="button" class="btn-cal-action text-primary" @click.stop="selectToday">
                    <i class="bi bi-calendar-check me-1"></i>Hoy
                </button>
                <button v-if="allowClear && modelValue" type="button" class="btn-cal-action text-danger" @click.stop="clearDate">
                    <i class="bi bi-x me-0.5"></i>{{ clearLabel }}
                </button>
                <button type="button" class="btn-cal-close ms-auto" @click.stop="closePopover">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.modern-datepicker-container {
    width: 100%;
}

.modern-input-wrapper.is-focused .modern-input-control {
    border-color: var(--accent-cyan, #0284c7) !important;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15) !important;
}

.modern-datepicker-input {
    width: 100% !important;
    height: 40px !important;
    padding-left: 38px !important;
    padding-right: 44px !important;
    border: none !important;
    background: transparent !important;
    font-size: 0.875rem !important;
    font-weight: 500 !important;
    outline: none !important;
    box-shadow: none !important;
    color: var(--text-primary, #0f172a) !important;
    cursor: pointer !important;
}

.modern-datepicker-input.has-clear {
    padding-right: 68px !important;
}

.modern-datepicker-trailing {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    gap: 8px;
    pointer-events: auto;
    z-index: 2;
}

.modern-datepicker-trailing-icon {
    font-size: 0.95rem;
    color: var(--text-muted, #94a3b8);
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    transition: color 0.15s ease;
}

.modern-input-wrapper:hover .modern-datepicker-trailing-icon,
.modern-input-wrapper.is-focused .modern-datepicker-trailing-icon {
    color: var(--accent-cyan, #0284c7);
}

.btn-clear-icon {
    line-height: 1;
    color: var(--text-muted, #94a3b8) !important;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.95rem;
    transition: color 0.15s ease, transform 0.15s ease;
}

.btn-clear-icon:hover {
    color: #ef4444 !important;
    transform: scale(1.1);
}

/* POPOVER CALENDARIO MODERNO */
.modern-datepicker-popover {
    width: 295px;
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 0.85rem;
    z-index: 1070;
    animation: popoverFadeIn 0.15s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes popoverFadeIn {
    from {
        opacity: 0;
        transform: translateY(4px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

[data-bs-theme="dark"] .modern-datepicker-popover {
    background: #181d28 !important;
    border-color: #2a3447 !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.55) !important;
}

/* CABECERA */
.cal-nav-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.65rem;
    padding: 0 0.2rem;
}

.cal-month-title {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--text-primary, #0f172a);
    text-transform: capitalize;
}

[data-bs-theme="dark"] .cal-month-title {
    color: #f1f5f9;
}

.cal-nav-btn {
    background: transparent;
    border: none;
    color: var(--text-muted, #64748b);
    width: 28px;
    height: 28px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.15s ease;
}

.cal-nav-btn:hover {
    background: var(--bg-surface-elevated, #f1f5f9);
    color: var(--text-primary, #0f172a);
}

[data-bs-theme="dark"] .cal-nav-btn:hover {
    background: #242c3d;
    color: #ffffff;
}

/* ENCABEZADOS DE DÍAS */
.cal-weekdays-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
    text-align: center;
    margin-bottom: 0.4rem;
}

.cal-weekdays-grid span {
    font-size: 0.68rem;
    font-weight: 700;
    color: var(--text-muted, #94a3b8);
    text-transform: uppercase;
}

/* MATRIZ DE DÍAS */
.cal-days-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 3px;
    text-align: center;
    margin-bottom: 0.65rem;
}

.cal-day-cell {
    width: 32px;
    height: 32px;
    margin: 0 auto;
    border: none;
    background: transparent;
    border-radius: 7px;
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--text-primary, #1e293b);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.12s ease;
    padding: 0;
}

[data-bs-theme="dark"] .cal-day-cell {
    color: #cbd5e1;
}

.cal-day-cell:hover:not(.is-outside) {
    background: var(--bg-surface-elevated, #e2e8f0);
    color: var(--text-primary, #0f172a);
}

[data-bs-theme="dark"] .cal-day-cell:hover:not(.is-outside) {
    background: #263147;
    color: #ffffff;
}

.cal-day-cell.is-outside {
    color: var(--text-muted, #94a3b8);
    opacity: 0.35;
}

.cal-day-cell.is-today {
    font-weight: 700;
    border: 1px solid var(--accent-cyan, #0284c7);
}

.cal-day-cell.is-selected {
    background: var(--accent-cyan, #0284c7) !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    box-shadow: 0 2px 8px rgba(2, 132, 199, 0.4);
}

/* BARRA INFERIOR */
.cal-footer-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-top: 0.5rem;
    border-top: 1px solid var(--border-color, #e2e8f0);
}

[data-bs-theme="dark"] .cal-footer-bar {
    border-top-color: #242c3d;
}

.btn-cal-action {
    background: transparent;
    border: none;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    transition: background 0.15s ease;
}

.btn-cal-action:hover {
    background: rgba(2, 132, 199, 0.1);
}

.btn-cal-close {
    background: transparent;
    border: 1px solid var(--border-color, #cbd5e1);
    color: var(--text-secondary, #64748b);
    font-size: 0.725rem;
    font-weight: 600;
    padding: 0.2rem 0.6rem;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.btn-cal-close:hover {
    background: var(--bg-surface-elevated, #f1f5f9);
    color: var(--text-primary, #0f172a);
}

[data-bs-theme="dark"] .btn-cal-close {
    border-color: #334155;
    color: #94a3b8;
}

[data-bs-theme="dark"] .btn-cal-close:hover {
    background: #242c3d;
    color: #ffffff;
}
</style>
