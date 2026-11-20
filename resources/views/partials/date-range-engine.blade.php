{{-- Motor reutilizable del selector de periodo (.calendar-pro-popover): accesos rápidos Hoy, Ayer, Últimos 7 días, Este mes, Mes anterior y rango personalizado. Lo usan Reportes y el Calendario de asistencia. Se incluye una sola vez por página. --}}
@once
<script>
{{-- Helper de formato de fechas DD/MM/AAAA --}}
function formatToDMY(isoDateStr) {
    if (!isoDateStr) return '';
    const parts = isoDateStr.split('-');
    if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
    return isoDateStr;
}

function getPresetDates(preset) {
    const today = new Date();
    const y = today.getFullYear();
    const m = today.getMonth();
    const d = today.getDate();

    let sDate = new Date(y, m, d);
    let eDate = new Date(y, m, d);
    let label = 'Este mes';

    switch (preset) {
        case 'today':
            label = 'Hoy';
            break;
        case 'yesterday':
            sDate.setDate(d - 1);
            eDate.setDate(d - 1);
            label = 'Ayer';
            break;
        case 'week':
        case 'last_7_days':
            sDate.setDate(d - 6);
            label = 'Últimos 7 días';
            break;
        case 'last_30_days':
            sDate.setDate(d - 29);
            label = 'Últimos 30 días';
            break;
        case 'month':
        case 'this_month':
            sDate = new Date(y, m, 1);
            label = 'Este mes';
            break;
        case 'prev_month':
            sDate = new Date(y, m - 1, 1);
            eDate = new Date(y, m, 0);
            label = 'Mes anterior';
            break;
        default:
            label = 'Personalizado';
            break;
    }

    const pad = n => String(n).padStart(2, '0');
    const toIso = dt => `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`;

    return {
        startIso: toIso(sDate),
        endIso: toIso(eDate),
        label
    };
}

{{-- ------------------------------------------------------------------------- --}}
{{-- MOTOR REUTILIZABLE DE CALENDARIO MODERNO INTERACTIVO (.calendar-pro-popover) --}}
{{-- ------------------------------------------------------------------------- --}}
function setupInteractiveCalendar(cfg) {
    const dropdownEl = document.getElementById(cfg.dropdownId);
    if (!dropdownEl) return null;

    const triggerBtn = document.getElementById(cfg.triggerBtnId);
    const triggerLabel = document.getElementById(cfg.triggerLabelId);
    const triggerFrom = document.getElementById(cfg.triggerFromId);
    const triggerTo = document.getElementById(cfg.triggerToId);
    const inputFrom = document.getElementById(cfg.inputFromId);
    const inputTo = document.getElementById(cfg.inputToId);
    const monthTitle = document.getElementById(cfg.monthTitleId);
    const daysGrid = document.getElementById(cfg.daysGridId);
    const prevBtn = document.getElementById(cfg.prevMonthBtnId);
    const nextBtn = document.getElementById(cfg.nextMonthBtnId);
    const cancelBtn = document.getElementById(cfg.cancelBtnId);
    const applyBtn = document.getElementById(cfg.applyBtnId);
    const fromWrapper = document.getElementById(cfg.fromWrapperId);
    const toWrapper = document.getElementById(cfg.toWrapperId);
    const presetBtns = dropdownEl.querySelectorAll('.calendar-preset-btn');

    let currentStart = cfg.initialStart;
    let currentEnd = cfg.initialEnd;
    let activePreset = cfg.initialPreset || 'month';

    let calViewDate = new Date(currentStart ? currentStart + 'T00:00:00' : Date.now());
    let selectedStart = currentStart;
    let selectedEnd = currentEnd;
    let selectingEndStep = false;

    const monthNamesEs = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    function renderGrid() {
        if (!daysGrid) return;
        const year = calViewDate.getFullYear();
        const month = calViewDate.getMonth();

        if (monthTitle) {
            monthTitle.textContent = `${monthNamesEs[month]} De ${year}`;
        }

        daysGrid.innerHTML = '';

        const firstDayOfMonth = new Date(year, month, 1);
        let startingDayOfWeek = firstDayOfMonth.getDay();
        startingDayOfWeek = startingDayOfWeek === 0 ? 7 : startingDayOfWeek;

        const daysInCurrentMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        {{-- Rellenar días anteriores --}}
        for (let i = startingDayOfWeek - 1; i > 0; i--) {
            const dayNum = daysInPrevMonth - i + 1;
            const cell = document.createElement('div');
            cell.className = 'calendar-day-cell is-outside-month';
            cell.textContent = dayNum;
            daysGrid.appendChild(cell);
        }

        {{-- Rellenar días del mes --}}
        for (let d = 1; d <= daysInCurrentMonth; d++) {
            const dStr = String(d).padStart(2, '0');
            const mStr = String(month + 1).padStart(2, '0');
            const iso = `${year}-${mStr}-${dStr}`;

            const cell = document.createElement('div');
            cell.className = 'calendar-day-cell';
            cell.textContent = d;
            cell.dataset.date = iso;

            const isStart = iso === selectedStart;
            const isEnd = iso === selectedEnd;
            const inRange = selectedStart && selectedEnd && iso > selectedStart && iso < selectedEnd;

            if (isStart) cell.classList.add('is-selected-start');
            if (isEnd) cell.classList.add('is-selected-end');
            if (inRange) cell.classList.add('is-in-range');

            cell.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (!selectingEndStep || !selectedStart) {
                    selectedStart = iso;
                    selectedEnd = iso;
                    selectingEndStep = true;
                    fromWrapper?.classList.remove('is-active');
                    toWrapper?.classList.add('is-active');
                } else {
                    if (iso < selectedStart) {
                        selectedEnd = selectedStart;
                        selectedStart = iso;
                    } else {
                        selectedEnd = iso;
                    }
                    selectingEndStep = false;
                    toWrapper?.classList.remove('is-active');
                    fromWrapper?.classList.add('is-active');
                }

                if (inputFrom) inputFrom.value = formatToDMY(selectedStart);
                if (inputTo) inputTo.value = formatToDMY(selectedEnd);

                presetBtns.forEach(b => {
                    b.classList.remove('is-active');
                    b.querySelector('.bi-check-lg')?.classList.add('d-none');
                });
                activePreset = '';

                renderGrid();
            });

            daysGrid.appendChild(cell);
        }

        {{-- Rellenar días siguientes (múltiplo de 7) --}}
        const totalCells = (startingDayOfWeek - 1) + daysInCurrentMonth;
        const remaining = (7 - (totalCells % 7)) % 7;
        for (let nextD = 1; nextD <= remaining; nextD++) {
            const cell = document.createElement('div');
            cell.className = 'calendar-day-cell is-outside-month';
            cell.textContent = nextD;
            daysGrid.appendChild(cell);
        }
    }

    prevBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        calViewDate.setMonth(calViewDate.getMonth() - 1);
        renderGrid();
    });

    nextBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        calViewDate.setMonth(calViewDate.getMonth() + 1);
        renderGrid();
    });

    cancelBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        selectedStart = currentStart;
        selectedEnd = currentEnd;
        if (inputFrom) inputFrom.value = formatToDMY(selectedStart);
        if (inputTo) inputTo.value = formatToDMY(selectedEnd);
        renderGrid();
        if (triggerBtn) bootstrap.Dropdown.getInstance(triggerBtn)?.hide();
    });

    applyBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        currentStart = selectedStart;
        currentEnd = selectedEnd;

        if (triggerFrom) triggerFrom.textContent = formatToDMY(currentStart);
        if (triggerTo) triggerTo.textContent = formatToDMY(currentEnd);
        const appliedLabel = activePreset ? (dropdownEl.querySelector(`.calendar-preset-btn[data-preset="${activePreset}"]`)?.textContent?.trim() || 'Personalizado') : 'Personalizado';
        if (triggerLabel) triggerLabel.textContent = appliedLabel;

        if (triggerBtn) bootstrap.Dropdown.getInstance(triggerBtn)?.hide();

        cfg.onApply?.(currentStart, currentEnd, activePreset, appliedLabel);
    });

    presetBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const p = btn.dataset.preset;
            if (!p) return;

            presetBtns.forEach(b => {
                b.classList.remove('is-active');
                b.querySelector('.bi-check-lg')?.classList.add('d-none');
            });
            btn.classList.add('is-active');
            btn.querySelector('.bi-check-lg')?.classList.remove('d-none');

            activePreset = p;
            const dates = getPresetDates(p);
            currentStart = dates.startIso;
            currentEnd = dates.endIso;
            selectedStart = dates.startIso;
            selectedEnd = dates.endIso;
            calViewDate = new Date(dates.startIso + 'T00:00:00');

            if (inputFrom) inputFrom.value = formatToDMY(dates.startIso);
            if (inputTo) inputTo.value = formatToDMY(dates.endIso);
            if (triggerLabel) triggerLabel.textContent = dates.label;
            if (triggerFrom) triggerFrom.textContent = formatToDMY(dates.startIso);
            if (triggerTo) triggerTo.textContent = formatToDMY(dates.endIso);

            renderGrid();

            if (triggerBtn) bootstrap.Dropdown.getInstance(triggerBtn)?.hide();

            cfg.onApply?.(currentStart, currentEnd, p, dates.label);
        });
    });

    renderGrid();

    return {
        resetToPreset(preset) {
            presetBtns.forEach(b => {
                if (b.dataset.preset === preset) {
                    b.classList.add('is-active');
                    b.querySelector('.bi-check-lg')?.classList.remove('d-none');
                } else {
                    b.classList.remove('is-active');
                    b.querySelector('.bi-check-lg')?.classList.add('d-none');
                }
            });
            activePreset = preset;
            const dates = getPresetDates(preset);
            currentStart = dates.startIso;
            currentEnd = dates.endIso;
            selectedStart = dates.startIso;
            selectedEnd = dates.endIso;
            calViewDate = new Date(dates.startIso + 'T00:00:00');

            if (inputFrom) inputFrom.value = formatToDMY(dates.startIso);
            if (inputTo) inputTo.value = formatToDMY(dates.endIso);
            if (triggerLabel) triggerLabel.textContent = dates.label;
            if (triggerFrom) triggerFrom.textContent = formatToDMY(dates.startIso);
            if (triggerTo) triggerTo.textContent = formatToDMY(dates.endIso);

            renderGrid();
            return dates;
        }
    };
}
</script>
@endonce
