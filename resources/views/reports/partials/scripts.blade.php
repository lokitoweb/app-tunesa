<script>
document.addEventListener('DOMContentLoaded', () => {
    const tabContainer = document.getElementById('reportsTabContent');
    const headerTitle = document.getElementById('reportsHeaderTitle');
    const headerSubtitle = document.getElementById('reportsHeaderSubtitle');
    const exportExcelBtn = document.getElementById('reportExportExcelBtn');
    const exportPdfBtn = document.getElementById('reportExportPdfBtn');

    let activeTabName = '{{ $initialTab ?? "marcaciones" }}';

    let marcacionesState = {
        status: '{{ $status ?? "" }}',
        startDate: '{{ $startDate ?? ($filters->from ?? Carbon\Carbon::today()->startOfMonth()->toDateString()) }}',
        endDate: '{{ $endDate ?? ($filters->to ?? Carbon\Carbon::today()->toDateString()) }}',
        preset: '{{ $preset ?? "month" }}',
        presetLabel: '{{ $presetLabel ?? "Este mes" }}',
        branchId: '{{ $branchId ?? "" }}',
        search: '{{ $search ?? "" }}',
        perPage: 25,
        page: 1
    };

    let hoursState = {
        from: '{{ isset($filters) ? $filters->from : Carbon\Carbon::today()->startOfMonth()->toDateString() }}',
        to: '{{ isset($filters) ? $filters->to : Carbon\Carbon::today()->toDateString() }}',
        branchId: '{{ isset($filters) ? ($filters->branchId ?? "") : "" }}',
        employeeId: '{{ isset($filters) ? ($filters->userId ?? "") : "" }}',
        q: '{{ isset($filters) ? ($filters->search ?? "") : "" }}',
        perPage: 25,
        page: 1
    };

    let searchDebounceTimeout = null;

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

    {{-- ------------------------------------------------------------------------- --}}
    {{-- 1. MANEJO DE PESTAÑAS PRINCIPALES VÍA AJAX --}}
    {{-- ------------------------------------------------------------------------- --}}
    function initTabsNavigation() {
        const tabLinks = document.querySelectorAll('#moduleMainTabs .seg-tab');
        tabLinks.forEach(tab => {
            tab.addEventListener('click', (e) => {
                if (e.ctrlKey || e.metaKey || e.which === 2) return;

                e.preventDefault();
                const targetTab = tab.dataset.tab;
                const targetUrl = tab.dataset.url;

                if (targetTab === activeTabName) return;

                switchTab(targetTab, targetUrl, true);
            });
        });
    }

    async function switchTab(tabName, url, pushHistory = true) {
        if (!tabContainer) return;

        tabContainer.classList.add('is-loading');

        document.querySelectorAll('#moduleMainTabs .seg-tab').forEach(t => {
            if (t.dataset.tab === tabName) {
                t.classList.add('is-active');
                t.setAttribute('aria-selected', 'true');
            } else {
                t.classList.remove('is-active');
                t.setAttribute('aria-selected', 'false');
            }
        });

        try {
            const reqUrl = new URL(url, window.location.origin);
            reqUrl.searchParams.set('tab_content', '1');

            const response = await fetch(reqUrl.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                }
            });

            if (response.ok) {
                const html = await response.text();
                tabContainer.innerHTML = html;
                activeTabName = tabName;

                if (pushHistory) {
                    history.pushState({ tab: tabName }, '', url);
                }

                updateHeaderInfo(tabName);

                if (tabName === 'marcaciones') {
                    initMarcacionesHandlers();
                } else if (tabName === 'hours') {
                    initHoursHandlers();
                }
            } else {
                console.error('Error al cargar la pestaña:', response.status);
            }
        } catch (err) {
            console.error('Error de red al cambiar de pestaña:', err);
        } finally {
            tabContainer.classList.remove('is-loading');
        }
    }

    function updateHeaderInfo(tabName) {
        if (tabName === 'marcaciones') {
            if (headerTitle) headerTitle.textContent = 'Reportes de asistencia y jornadas';
            if (headerSubtitle) headerSubtitle.textContent = 'Cómputo de horas trabajadas, sobretiempos, tardanzas, tolerancias y cumplimiento de geocercas.';
            updateMarcacionesExportLinks();
        } else {
            if (headerTitle) headerTitle.textContent = 'Reporte de horas y faltas';
            if (headerSubtitle) headerSubtitle.textContent = 'Horas efectivas, tardanzas, horas extra diurnas y nocturnas (22:00–06:00) y faltas por colaborador.';
            updateHoursExportLinks();
        }
    }

    window.addEventListener('popstate', (e) => {
        const isHours = window.location.pathname.includes('/hours');
        const targetTab = isHours ? 'hours' : 'marcaciones';
        if (targetTab !== activeTabName) {
            switchTab(targetTab, window.location.href, false);
        }
    });

    {{-- ------------------------------------------------------------------------- --}}
    {{-- 2. LÓGICA DEL MÓDULO DE MARCACIONES (AJAX) --}}
    {{-- ------------------------------------------------------------------------- --}}
    function initMarcacionesHandlers() {
        const branchSelect = document.getElementById('repBranchSelect');
        const searchInput = document.getElementById('repSearchInput');
        const resetBtn = document.getElementById('repResetBtn');
        const statusTabs = document.querySelectorAll('#reportStatusTabs .seg-tab');
        const tableCard = document.getElementById('reportsTableContainer');

        if (branchSelect) marcacionesState.branchId = branchSelect.value;
        if (searchInput) marcacionesState.search = searchInput.value;

        {{-- Inicializar Calendario Moderno de Marcaciones --}}
        const calManager = setupInteractiveCalendar({
            dropdownId: 'repCalendarDropdown',
            triggerBtnId: 'repDateFilterDropdownBtn',
            triggerLabelId: 'repCalTriggerLabel',
            triggerFromId: 'repCalTriggerFrom',
            triggerToId: 'repCalTriggerTo',
            inputFromId: 'repCalInputFrom',
            inputToId: 'repCalInputTo',
            monthTitleId: 'repCalCurrentMonthTitle',
            daysGridId: 'repCalDaysGrid',
            prevMonthBtnId: 'repCalPrevMonthBtn',
            nextMonthBtnId: 'repCalNextMonthBtn',
            cancelBtnId: 'repCalCancelBtn',
            applyBtnId: 'repCalApplyBtn',
            fromWrapperId: 'repCalFromInputWrapper',
            toWrapperId: 'repCalToInputWrapper',
            initialStart: marcacionesState.startDate,
            initialEnd: marcacionesState.endDate,
            initialPreset: marcacionesState.preset || 'month',
            onApply: (startIso, endIso, presetCode, presetLabel) => {
                marcacionesState.startDate = startIso;
                marcacionesState.endDate = endIso;
                marcacionesState.preset = presetCode;
                marcacionesState.presetLabel = presetLabel;
                marcacionesState.page = 1;
                fetchMarcaciones();
            }
        });

        {{-- Status Tabs click --}}
        statusTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                statusTabs.forEach(t => t.classList.remove('is-active'));
                tab.classList.add('is-active');
                marcacionesState.status = tab.dataset.status || '';
                marcacionesState.page = 1;
                fetchMarcaciones();
            });
        });

        {{-- Sucursal --}}
        if (branchSelect) {
            branchSelect.addEventListener('change', () => {
                marcacionesState.branchId = branchSelect.value;
                marcacionesState.page = 1;
                fetchMarcaciones();
            });
        }

        {{-- Búsqueda con debounce --}}
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchDebounceTimeout);
                searchDebounceTimeout = setTimeout(() => {
                    marcacionesState.search = searchInput.value.trim();
                    marcacionesState.page = 1;
                    fetchMarcaciones();
                }, 350);
            });
        }

        {{-- Reset --}}
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                if (branchSelect) branchSelect.value = '';
                if (searchInput) searchInput.value = '';

                statusTabs.forEach(t => t.classList.remove('is-active'));
                const allTab = document.querySelector('#reportStatusTabs [data-status=""]');
                if (allTab) allTab.classList.add('is-active');

                const resetDates = calManager?.resetToPreset('month');

                marcacionesState.status = '';
                if (resetDates) {
                    marcacionesState.startDate = resetDates.startIso;
                    marcacionesState.endDate = resetDates.endIso;
                    marcacionesState.preset = 'month';
                    marcacionesState.presetLabel = 'Este mes';
                }
                marcacionesState.branchId = '';
                marcacionesState.search = '';
                marcacionesState.page = 1;

                fetchMarcaciones();
            });
        }

        {{-- Paginación y Selector por página delegados --}}
        if (tableCard) {
            tableCard.addEventListener('click', (e) => {
                const pageBtn = e.target.closest('.ajax-report-page-btn');
                if (pageBtn && !pageBtn.closest('.disabled')) {
                    e.preventDefault();
                    const page = parseInt(pageBtn.dataset.page, 10);
                    if (page) {
                        marcacionesState.page = page;
                        fetchMarcaciones();
                    }
                }
            });

            tableCard.addEventListener('change', (e) => {
                if (e.target && e.target.id === 'repPerPageSelect') {
                    marcacionesState.perPage = parseInt(e.target.value, 10) || 25;
                    marcacionesState.page = 1;
                    fetchMarcaciones();
                }
            });
        }

        updateMarcacionesExportLinks();
    }

    async function fetchMarcaciones() {
        const tableCard = document.getElementById('reportsTableContainer');
        if (tableCard) {
            tableCard.style.opacity = '0.5';
            tableCard.style.pointerEvents = 'none';
        }

        const params = new URLSearchParams();
        if (marcacionesState.startDate) params.append('start_date', marcacionesState.startDate);
        if (marcacionesState.endDate) params.append('end_date', marcacionesState.endDate);
        if (marcacionesState.preset) params.append('preset', marcacionesState.preset);
        if (marcacionesState.branchId) params.append('branch_id', marcacionesState.branchId);
        if (marcacionesState.status) params.append('status', marcacionesState.status);
        if (marcacionesState.search) params.append('search', marcacionesState.search);
        if (marcacionesState.perPage) params.append('per_page', marcacionesState.perPage);
        if (marcacionesState.page > 1) params.append('page', marcacionesState.page);

        updateMarcacionesExportLinks();

        try {
            const response = await fetch(`{{ route('reports.index') }}?${params.toString()}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            if (response.ok) {
                const data = await response.json();

                {{-- 1. Actualizar tabla --}}
                if (tableCard && data.html) {
                    tableCard.innerHTML = data.html;
                }

                {{-- 2. Actualizar KPIs del periodo --}}
                if (data.kpis) {
                    const elTotal = document.getElementById('kpiTotalRecords');
                    const elRate = document.getElementById('kpiPunctualityRate');
                    const elOnTime = document.getElementById('kpiOnTimeCount');
                    const elLate = document.getElementById('kpiLateCount');
                    const elHours = document.getElementById('kpiTotalHours');
                    const elOvertime = document.getElementById('kpiOvertimeHours');

                    if (elTotal) elTotal.textContent = data.kpis.totalRecords;
                    if (elRate) elRate.textContent = data.kpis.punctualityRate + '%';
                    if (elOnTime) elOnTime.textContent = data.kpis.onTimeCount;
                    if (elLate) elLate.textContent = data.kpis.lateCount;
                    if (elHours) elHours.textContent = data.kpis.totalHours + 'h';
                    if (elOvertime) elOvertime.textContent = data.kpis.overtimeHours + 'h';
                }

                {{-- 3. Actualizar badges en la barra segmentada de estados --}}
                if (data.counts) {
                    const bAll = document.getElementById('badgeCountAll');
                    const bPres = document.getElementById('badgeCountPresent');
                    const bLate = document.getElementById('badgeCountLate');
                    const bAbs = document.getElementById('badgeCountAbsent');
                    const bExc = document.getElementById('badgeCountExcused');

                    if (bAll) bAll.textContent = data.counts.all;
                    if (bPres) bPres.textContent = data.counts.present;
                    if (bLate) bLate.textContent = data.counts.late;
                    if (bAbs) bAbs.textContent = data.counts.absent;
                    if (bExc) bExc.textContent = data.counts.excused;
                }
            } else {
                console.error('Error al filtrar marcaciones:', response.status);
            }
        } catch (error) {
            console.error('Error de red al filtrar marcaciones:', error);
        } finally {
            if (tableCard) {
                tableCard.style.opacity = '1';
                tableCard.style.pointerEvents = 'auto';
            }
        }
    }

    function updateMarcacionesExportLinks() {
        const params = new URLSearchParams();
        if (marcacionesState.startDate) params.append('start_date', marcacionesState.startDate);
        if (marcacionesState.endDate) params.append('end_date', marcacionesState.endDate);
        if (marcacionesState.branchId) params.append('branch_id', marcacionesState.branchId);
        if (marcacionesState.status) params.append('status', marcacionesState.status);
        if (marcacionesState.search) params.append('search', marcacionesState.search);

        const qs = params.toString();
        if (exportExcelBtn) {
            exportExcelBtn.href = `{{ route('reports.export.excel') }}?${qs}`;
            exportExcelBtn.innerHTML = '<i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel (.csv)';
            exportExcelBtn.className = 'btn btn-light border';
        }
        if (exportPdfBtn) {
            exportPdfBtn.href = `{{ route('reports.export.pdf') }}?${qs}`;
            exportPdfBtn.innerHTML = '<i class="bi bi-printer me-1"></i>Imprimir PDF';
            exportPdfBtn.className = 'btn btn-light border';
        }
    }

    {{-- ------------------------------------------------------------------------- --}}
    {{-- 3. LÓGICA DEL MÓDULO DE HORAS Y FALTAS (AJAX) --}}
    {{-- ------------------------------------------------------------------------- --}}
    function initHoursHandlers() {
        const branchSelect = document.getElementById('hrsBranchSelect');
        const empSelect = document.getElementById('hrsEmployeeSelect');
        const searchInput = document.getElementById('hrsSearchInput');
        const resetBtn = document.getElementById('hrsResetBtn');
        const tableCard = document.getElementById('hoursTableContainer');

        if (branchSelect) hoursState.branchId = branchSelect.value;
        if (empSelect) hoursState.employeeId = empSelect.value;
        if (searchInput) hoursState.q = searchInput.value;

        {{-- Inicializar Calendario Moderno de Horas --}}
        const calManager = setupInteractiveCalendar({
            dropdownId: 'hrsCalendarDropdown',
            triggerBtnId: 'hrsDateFilterDropdownBtn',
            triggerLabelId: 'hrsCalTriggerLabel',
            triggerFromId: 'hrsCalTriggerFrom',
            triggerToId: 'hrsCalTriggerTo',
            inputFromId: 'hrsCalInputFrom',
            inputToId: 'hrsCalInputTo',
            monthTitleId: 'hrsCalCurrentMonthTitle',
            daysGridId: 'hrsCalDaysGrid',
            prevMonthBtnId: 'hrsCalPrevMonthBtn',
            nextMonthBtnId: 'hrsCalNextMonthBtn',
            cancelBtnId: 'hrsCalCancelBtn',
            applyBtnId: 'hrsCalApplyBtn',
            fromWrapperId: 'hrsCalFromInputWrapper',
            toWrapperId: 'hrsCalToInputWrapper',
            initialStart: hoursState.from,
            initialEnd: hoursState.to,
            initialPreset: 'month',
            onApply: (startIso, endIso) => {
                hoursState.from = startIso;
                hoursState.to = endIso;
                hoursState.page = 1;
                fetchHours();
            }
        });

        if (branchSelect) {
            branchSelect.addEventListener('change', () => {
                hoursState.branchId = branchSelect.value;
                hoursState.page = 1;
                fetchHours();
            });
        }

        if (empSelect) {
            empSelect.addEventListener('change', () => {
                hoursState.employeeId = empSelect.value;
                hoursState.page = 1;
                fetchHours();
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchDebounceTimeout);
                searchDebounceTimeout = setTimeout(() => {
                    hoursState.q = searchInput.value.trim();
                    hoursState.page = 1;
                    fetchHours();
                }, 350);
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                if (branchSelect) branchSelect.value = '';
                if (empSelect) empSelect.value = '';
                if (searchInput) searchInput.value = '';

                const resetDates = calManager?.resetToPreset('month');

                if (resetDates) {
                    hoursState.from = resetDates.startIso;
                    hoursState.to = resetDates.endIso;
                }
                hoursState.branchId = '';
                hoursState.employeeId = '';
                hoursState.q = '';
                hoursState.page = 1;

                fetchHours();
            });
        }

        {{-- Paginación y Selector por página delegados --}}
        if (tableCard) {
            tableCard.addEventListener('click', (e) => {
                const pageBtn = e.target.closest('.ajax-hours-page-btn');
                if (pageBtn && !pageBtn.closest('.disabled')) {
                    e.preventDefault();
                    const page = parseInt(pageBtn.dataset.page, 10);
                    if (page) {
                        hoursState.page = page;
                        fetchHours();
                    }
                }
            });

            tableCard.addEventListener('change', (e) => {
                if (e.target && e.target.id === 'hrsPerPageSelect') {
                    hoursState.perPage = parseInt(e.target.value, 10) || 25;
                    hoursState.page = 1;
                    fetchHours();
                }
            });
        }

        updateHoursExportLinks();
    }

    async function fetchHours() {
        const tableCard = document.getElementById('hoursTableContainer');
        if (tableCard) {
            tableCard.style.opacity = '0.5';
            tableCard.style.pointerEvents = 'none';
        }

        const params = new URLSearchParams();
        if (hoursState.from) params.append('from', hoursState.from);
        if (hoursState.to) params.append('to', hoursState.to);
        if (hoursState.branchId) params.append('branch_id', hoursState.branchId);
        if (hoursState.employeeId) params.append('employee_id', hoursState.employeeId);
        if (hoursState.q) params.append('q', hoursState.q);
        if (hoursState.perPage) params.append('per_page', hoursState.perPage);
        if (hoursState.page > 1) params.append('page', hoursState.page);

        updateHoursExportLinks();

        try {
            const response = await fetch(`{{ route('reports.hours') }}?${params.toString()}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            if (response.ok) {
                const data = await response.json();
                if (tableCard && data.html) {
                    tableCard.innerHTML = data.html;
                }

                if (data.totals) {
                    const elWorked = document.getElementById('kpiHoursWorked');
                    const elWorkedDays = document.getElementById('kpiHoursDaysWorked');
                    const elLate = document.getElementById('kpiHoursLate');
                    const elLateDays = document.getElementById('kpiHoursLateDays');
                    const elOtDay = document.getElementById('kpiHoursOvertimeDay');
                    const elOtNight = document.getElementById('kpiHoursOvertimeNight');
                    const elNightTot = document.getElementById('kpiHoursNightTotal');
                    const elAbs = document.getElementById('kpiHoursAbsences');
                    const elAbsJust = document.getElementById('kpiHoursAbsencesJustified');

                    if (elWorked) elWorked.textContent = data.totals.worked_hours;
                    if (elWorkedDays) elWorkedDays.textContent = data.totals.days_worked;
                    if (elLate) elLate.textContent = Panel.minutes(data.totals.late_minutes);
                    if (elLateDays) elLateDays.textContent = data.totals.late_days;
                    if (elOtDay) elOtDay.textContent = data.totals.overtime_day_hours;
                    if (elOtNight) elOtNight.textContent = data.totals.overtime_night_hours;
                    if (elNightTot) elNightTot.textContent = data.totals.night_hours;
                    if (elAbs) elAbs.textContent = data.totals.absences_unjustified;
                    if (elAbsJust) elAbsJust.textContent = data.totals.absences_justified;
                }

                const empBadge = document.getElementById('hoursTotalEmployeesBadge');
                if (empBadge && data.totalEmployees !== undefined) {
                    empBadge.textContent = data.totalEmployees;
                }
            }
        } catch (err) {
            console.error('Error al filtrar horas:', err);
        } finally {
            if (tableCard) {
                tableCard.style.opacity = '1';
                tableCard.style.pointerEvents = 'auto';
            }
        }
    }

    function updateHoursExportLinks() {
        const params = new URLSearchParams();
        if (hoursState.from) params.append('from', hoursState.from);
        if (hoursState.to) params.append('to', hoursState.to);
        if (hoursState.branchId) params.append('branch_id', hoursState.branchId);
        if (hoursState.employeeId) params.append('employee_id', hoursState.employeeId);
        if (hoursState.q) params.append('q', hoursState.q);

        const qs = params.toString();
        if (exportExcelBtn) {
            exportExcelBtn.href = `{{ route('reports.hours.excel') }}?${qs}`;
            exportExcelBtn.innerHTML = '<i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel (.xlsx)';
            exportExcelBtn.className = 'btn btn-light border';
        }
        if (exportPdfBtn) {
            exportPdfBtn.href = `{{ route('reports.hours.pdf') }}?${qs}`;
            exportPdfBtn.innerHTML = '<i class="bi bi-file-earmark-pdf me-1"></i>Exportar PDF';
            exportPdfBtn.className = 'btn btn-light border';
        }
    }

    {{-- Inicializar listeners según la pestaña inicial --}}
    initTabsNavigation();
    if (activeTabName === 'marcaciones') {
        initMarcacionesHandlers();
    } else if (activeTabName === 'hours') {
        initHoursHandlers();
    }
});
</script>
