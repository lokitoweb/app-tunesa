<!-- =========================================================================
     INDICADORES DEL PERIODO (TOTALES DE HORAS Y FALTAS)
     ========================================================================= -->
<div class="row g-3 mb-4" id="hoursKpiContainer">
    <div class="col-6 col-md-4 col-xl">
        <div class="reports-kpi-card p-3 shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-kpi-pill bg-primary-subtle text-primary">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Horas trabajadas</div>
                    <div class="fs-4 fw-bold text-body lh-1 mt-1" id="kpiHoursWorked">{{ number_format($totals['worked_hours'], 1) }} h</div>
                    <div class="small text-body-secondary mt-1" id="kpiHoursDaysWorked">{{ $totals['days_worked'] }} jornadas</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <div class="reports-kpi-card p-3 shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-kpi-pill bg-warning-subtle text-warning-emphasis">
                    <i class="bi bi-alarm"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Tardanza</div>
                    <div class="fs-4 fw-bold text-body lh-1 mt-1" id="kpiHoursLate">{{ $totals['late_minutes'] }} min</div>
                    <div class="small text-body-secondary mt-1" id="kpiHoursLateDays">{{ $totals['late_days'] }} días con tardanza</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <div class="reports-kpi-card p-3 shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-kpi-pill bg-info-subtle text-info">
                    <i class="bi bi-sun"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">HE diurnas</div>
                    <div class="fs-4 fw-bold text-body lh-1 mt-1" id="kpiHoursOvertimeDay">{{ number_format($totals['overtime_day_hours'], 1) }} h</div>
                    <div class="small text-body-secondary mt-1">Sobre la jornada</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <div class="reports-kpi-card p-3 shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-kpi-pill bg-secondary-subtle text-secondary-emphasis">
                    <i class="bi bi-moon-stars"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">HE nocturnas</div>
                    <div class="fs-4 fw-bold text-body lh-1 mt-1" id="kpiHoursOvertimeNight">{{ number_format($totals['overtime_night_hours'], 1) }} h</div>
                    <div class="small text-body-secondary mt-1" id="kpiHoursNightTotal">{{ number_format($totals['night_hours'], 1) }} h en total</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <div class="reports-kpi-card p-3 shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-kpi-pill bg-danger-subtle text-danger">
                    <i class="bi bi-person-x"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Faltas</div>
                    <div class="fs-4 fw-bold text-danger lh-1 mt-1" id="kpiHoursAbsences">{{ $totals['absences_unjustified'] }}</div>
                    <div class="small text-body-secondary mt-1" id="kpiHoursAbsencesJustified">{{ $totals['absences_justified'] }} justificadas</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     BARRA DE FILTROS ADAPTADA Y 100% EN UNA SOLA LÍNEA HORIZONTAL (HORAS Y FALTAS)
     ========================================================================= -->
<div class="reports-filter-card p-2 p-md-3 mb-4 shadow-sm">
    <div class="reports-filter-single-row">
        <div class="reports-filter-inline-controls w-100 justify-content-between">
            <div class="d-flex align-items-center gap-2 flex-nowrap">
                <!-- Filtro de Calendario Moderno Popover -->
                <div class="dropdown flex-shrink-0" id="hrsCalendarDropdown">
                    <button type="button" class="date-filter-pill dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="hrsDateFilterDropdownBtn" title="Seleccionar periodo">
                        <i class="bi bi-calendar3 text-primary"></i>
                        <span class="fw-bold text-dark" id="hrsCalTriggerLabel">Este mes</span>
                        <span class="divider">|</span>
                        <span id="hrsCalTriggerFrom">{{ \Carbon\Carbon::parse($filters->from)->format('d/m/Y') }}</span>
                        <span class="divider">|</span>
                        <span id="hrsCalTriggerTo">{{ \Carbon\Carbon::parse($filters->to)->format('d/m/Y') }}</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-start calendar-pro-popover shadow-lg" aria-labelledby="hrsDateFilterDropdownBtn">
                        <div class="calendar-pro-grid">
                            <!-- Columna Izquierda: ACCESOS RÁPIDOS -->
                            <div class="calendar-sidebar-presets">
                                <div class="calendar-presets-heading">ACCESOS RÁPIDOS</div>
                                <button type="button" class="calendar-preset-btn" data-preset="today">
                                    <span>Hoy</span>
                                    <i class="bi bi-check-lg d-none"></i>
                                </button>
                                <button type="button" class="calendar-preset-btn" data-preset="yesterday">
                                    <span>Ayer</span>
                                    <i class="bi bi-check-lg d-none"></i>
                                </button>
                                <button type="button" class="calendar-preset-btn" data-preset="week">
                                    <span>Últimos 7 días</span>
                                    <i class="bi bi-check-lg d-none"></i>
                                </button>
                                <button type="button" class="calendar-preset-btn" data-preset="last_30_days">
                                    <span>Últimos 30 días</span>
                                    <i class="bi bi-check-lg d-none"></i>
                                </button>
                                <button type="button" class="calendar-preset-btn is-active" data-preset="month">
                                    <span>Este mes</span>
                                    <i class="bi bi-check-lg"></i>
                                </button>
                                <button type="button" class="calendar-preset-btn" data-preset="prev_month">
                                    <span>Mes anterior</span>
                                    <i class="bi bi-check-lg d-none"></i>
                                </button>
                            </div>

                            <!-- Columna Derecha: Rango y Cuadrícula -->
                            <div class="calendar-content-pane">
                                <div class="calendar-date-inputs-row">
                                    <div class="calendar-input-field is-active" id="hrsCalFromInputWrapper">
                                        <label class="calendar-input-label">Desde</label>
                                        <input type="text" id="hrsCalInputFrom" value="{{ \Carbon\Carbon::parse($filters->from)->format('d/m/Y') }}" placeholder="DD/MM/AAAA" readonly>
                                    </div>
                                    <div class="calendar-input-field" id="hrsCalToInputWrapper">
                                        <label class="calendar-input-label">Hasta</label>
                                        <input type="text" id="hrsCalInputTo" value="{{ \Carbon\Carbon::parse($filters->to)->format('d/m/Y') }}" placeholder="DD/MM/AAAA" readonly>
                                    </div>
                                </div>

                                <div class="calendar-nav-header">
                                    <button type="button" class="calendar-nav-btn" id="hrsCalPrevMonthBtn" title="Mes anterior">
                                        <i class="bi bi-chevron-left"></i>
                                    </button>
                                    <span class="calendar-month-title" id="hrsCalCurrentMonthTitle"></span>
                                    <button type="button" class="calendar-nav-btn" id="hrsCalNextMonthBtn" title="Mes siguiente">
                                        <i class="bi bi-chevron-right"></i>
                                    </button>
                                </div>

                                <div class="calendar-days-grid mb-1">
                                    <div class="calendar-day-header">L</div>
                                    <div class="calendar-day-header">M</div>
                                    <div class="calendar-day-header">X</div>
                                    <div class="calendar-day-header">J</div>
                                    <div class="calendar-day-header">V</div>
                                    <div class="calendar-day-header">S</div>
                                    <div class="calendar-day-header">D</div>
                                </div>

                                <div class="calendar-days-grid" id="hrsCalDaysGrid"></div>

                                <div class="calendar-helper-note">
                                    Selecciona el periodo. Se incluyen ambos días.
                                </div>

                                <div class="calendar-footer-actions">
                                    <button type="button" class="calendar-btn-cancel" id="hrsCalCancelBtn">Cancelar</button>
                                    <button type="button" class="calendar-btn-apply" id="hrsCalApplyBtn">Aplicar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sucursal (Compacto) -->
                <div class="modern-input-wrapper flex-shrink-0" style="width: 150px;" title="Filtrar por sucursal">
                    <span class="modern-input-icon"><i class="bi bi-building"></i></span>
                    <select id="hrsBranchSelect" class="modern-input-control" aria-label="Filtrar por sucursal">
                        <option value="">Todas las sucursales</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected($filters->branchId == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Colaborador (Compacto) -->
                <div class="modern-input-wrapper flex-shrink-0" style="width: 170px;" title="Filtrar por colaborador">
                    <span class="modern-input-icon"><i class="bi bi-person"></i></span>
                    <select id="hrsEmployeeSelect" class="modern-input-control" aria-label="Filtrar por colaborador">
                        <option value="">Todos los colaboradores</option>
                        @foreach($employees as $e)
                            <option value="{{ $e->id }}" @selected($filters->userId == $e->id)>{{ $e->last_name }}, {{ $e->first_name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Buscador libre (Compacto) -->
                <div class="modern-input-wrapper flex-shrink-0" style="width: 135px;" title="Buscar por nombre, código o DNI">
                    <span class="modern-input-icon"><i class="bi bi-search"></i></span>
                    <input
                        type="search"
                        id="hrsSearchInput"
                        value="{{ $filters->search }}"
                        class="modern-input-control"
                        placeholder="Buscar..."
                        aria-label="Buscar colaborador"
                    >
                </div>
            </div>

            <!-- Botón Limpiar -->
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <button
                    id="hrsResetBtn"
                    type="button"
                    class="btn btn-outline-secondary px-2 py-1 fw-medium d-inline-flex align-items-center flex-shrink-0 text-nowrap"
                    style="border-radius: 8px; height: 36px; font-size: 0.825rem;"
                    title="Restablecer filtros"
                >
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Limpiar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     TABLA DE HORAS Y FALTAS (AJAX)
     ========================================================================= -->
<div class="reports-table-card shadow-sm mb-4 reports-tab-transition" id="hoursTableContainer">
    @include('reports.partials.hours-table', ['rows' => $rows, 'filters' => $filters])
</div>

<p class="small text-body-secondary mt-2 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Horas efectivas = salida − entrada − refrigerio. Las horas extra sobre la jornada del turno son nocturnas si caen entre 22:00 y 06:00.
</p>
