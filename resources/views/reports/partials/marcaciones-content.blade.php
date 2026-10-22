<!-- =========================================================================
     INDICADORES DEL PERIODO (KPIS EJECUTIVOS DE MARCACIONES)
     ========================================================================= -->
<div class="row g-3 mb-4" id="reportsKpiContainer">
    <div class="col-6 col-md-4 col-xl">
        <div class="reports-kpi-card p-3 shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-kpi-pill bg-primary-subtle text-primary">
                    <i class="bi bi-list-check"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Registros</div>
                    <div class="fs-4 fw-bold text-body lh-1 mt-1" id="kpiTotalRecords">{{ $totalRecords }}</div>
                    <div class="small text-body-secondary mt-1">Jornadas</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <div class="reports-kpi-card p-3 shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-kpi-pill bg-success-subtle text-success">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Puntualidad</div>
                    <div class="fs-4 fw-bold text-success lh-1 mt-1" id="kpiPunctualityRate">{{ $punctualityRate }}%</div>
                    <div class="small text-body-secondary mt-1"><span id="kpiOnTimeCount">{{ $onTimeCount }}</span> a tiempo</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <div class="reports-kpi-card p-3 shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-kpi-pill bg-warning-subtle text-warning-emphasis">
                    <i class="bi bi-alarm-fill"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Tardanzas</div>
                    <div class="fs-4 fw-bold text-body lh-1 mt-1" id="kpiLateCount">{{ $lateCount }}</div>
                    <div class="small text-body-secondary mt-1">Fuera de regla</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <div class="reports-kpi-card p-3 shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-kpi-pill bg-info-subtle text-info">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Horas Trab.</div>
                    <div class="fs-4 fw-bold text-body lh-1 mt-1" id="kpiTotalHours">{{ number_format((float) $totalHours, 1) }}h</div>
                    <div class="small text-body-secondary mt-1">Efectivas</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <div class="reports-kpi-card p-3 shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-kpi-pill bg-secondary-subtle text-secondary-emphasis">
                    <i class="bi bi-plus-circle-fill"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">Horas Extra</div>
                    <div class="fs-4 fw-bold text-body lh-1 mt-1" id="kpiOvertimeHours">{{ number_format((float) $overtimeHours, 1) }}h</div>
                    <div class="small text-body-secondary mt-1">Aprobadas</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     BARRA DE FILTROS ADAPTADA Y 100% EN UNA SOLA LÍNEA HORIZONTAL
     ========================================================================= -->
<div class="reports-filter-card p-2 p-md-3 mb-4 shadow-sm">
    <div class="reports-filter-single-row">
        <!-- Segmented Control de Estados -->
        <div class="training-segmented-bar reports-segmented-bar flex-shrink-0" role="tablist" id="reportStatusTabs" aria-label="Filtrar por estado">
            <button type="button" class="training-segmented-item {{ empty($status) ? 'is-active' : '' }}" data-status="">
                <span>Todos</span>
                <span class="training-segmented-pill" id="badgeCountAll">{{ $totalRecords }}</span>
            </button>
            <button type="button" class="training-segmented-item {{ $status === 'present' ? 'is-active' : '' }}" data-status="present">
                <i class="bi bi-check-circle-fill text-success"></i>
                <span>Puntual</span>
                <span class="training-segmented-pill text-success" id="badgeCountPresent">{{ $onTimeCount }}</span>
            </button>
            <button type="button" class="training-segmented-item {{ $status === 'late' ? 'is-active' : '' }}" data-status="late">
                <i class="bi bi-alarm-fill text-warning"></i>
                <span>Tardanza</span>
                <span class="training-segmented-pill text-warning" id="badgeCountLate">{{ $lateCount }}</span>
            </button>
            <button type="button" class="training-segmented-item {{ $status === 'absent' ? 'is-active' : '' }}" data-status="absent">
                <i class="bi bi-person-x-fill text-danger"></i>
                <span>Inasistencia</span>
                <span class="training-segmented-pill text-danger" id="badgeCountAbsent">{{ $absentCount }}</span>
            </button>
            <button type="button" class="training-segmented-item {{ $status === 'excused' ? 'is-active' : '' }}" data-status="excused">
                <i class="bi bi-shield-check text-info"></i>
                <span>Justificado</span>
                <span class="training-segmented-pill text-info" id="badgeCountExcused">{{ $excusedCount }}</span>
            </button>
        </div>

        <!-- Controles en la misma línea: Calendario Moderno, Sucursal, Búsqueda y Limpiar -->
        <div class="reports-filter-inline-controls">
            <!-- Filtro de Calendario / Período Interactivo Profesional Popover -->
            <div class="dropdown flex-shrink-0" id="repCalendarDropdown">
                <button type="button" class="date-filter-pill dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="repDateFilterDropdownBtn" title="Seleccionar periodo">
                    <i class="bi bi-calendar3 text-primary"></i>
                    <span class="fw-bold text-dark" id="repCalTriggerLabel">{{ $presetLabel ?? 'Este mes' }}</span>
                    <span class="divider">|</span>
                    <span id="repCalTriggerFrom">{{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }}</span>
                    <span class="divider">|</span>
                    <span id="repCalTriggerTo">{{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end calendar-pro-popover shadow-lg" aria-labelledby="repDateFilterDropdownBtn">
                    <div class="calendar-pro-grid">
                        <!-- Columna Izquierda: ACCESOS RÁPIDOS -->
                        <div class="calendar-sidebar-presets">
                            <div class="calendar-presets-heading">ACCESOS RÁPIDOS</div>
                            <button type="button" class="calendar-preset-btn {{ ($preset ?? '') === 'today' ? 'is-active' : '' }}" data-preset="today">
                                <span>Hoy</span>
                                <i class="bi bi-check-lg {{ ($preset ?? '') === 'today' ? '' : 'd-none' }}"></i>
                            </button>
                            <button type="button" class="calendar-preset-btn {{ ($preset ?? '') === 'yesterday' ? 'is-active' : '' }}" data-preset="yesterday">
                                <span>Ayer</span>
                                <i class="bi bi-check-lg {{ ($preset ?? '') === 'yesterday' ? '' : 'd-none' }}"></i>
                            </button>
                            <button type="button" class="calendar-preset-btn {{ in_array(($preset ?? ''), ['week', 'last_7_days']) ? 'is-active' : '' }}" data-preset="week">
                                <span>Últimos 7 días</span>
                                <i class="bi bi-check-lg {{ in_array(($preset ?? ''), ['week', 'last_7_days']) ? '' : 'd-none' }}"></i>
                            </button>
                            <button type="button" class="calendar-preset-btn {{ ($preset ?? '') === 'last_30_days' ? 'is-active' : '' }}" data-preset="last_30_days">
                                <span>Últimos 30 días</span>
                                <i class="bi bi-check-lg {{ ($preset ?? '') === 'last_30_days' ? '' : 'd-none' }}"></i>
                            </button>
                            <button type="button" class="calendar-preset-btn {{ (empty($preset) || in_array($preset, ['month', 'this_month'])) ? 'is-active' : '' }}" data-preset="month">
                                <span>Este mes</span>
                                <i class="bi bi-check-lg {{ (empty($preset) || in_array($preset, ['month', 'this_month'])) ? '' : 'd-none' }}"></i>
                            </button>
                            <button type="button" class="calendar-preset-btn {{ ($preset ?? '') === 'prev_month' ? 'is-active' : '' }}" data-preset="prev_month">
                                <span>Mes anterior</span>
                                <i class="bi bi-check-lg {{ ($preset ?? '') === 'prev_month' ? '' : 'd-none' }}"></i>
                            </button>
                        </div>

                        <!-- Columna Derecha: Rango y Cuadrícula Dinámica -->
                        <div class="calendar-content-pane">
                            <div class="calendar-date-inputs-row">
                                <div class="calendar-input-field is-active" id="repCalFromInputWrapper">
                                    <label class="calendar-input-label">Desde</label>
                                    <input type="text" id="repCalInputFrom" value="{{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }}" placeholder="DD/MM/AAAA" readonly>
                                </div>
                                <div class="calendar-input-field" id="repCalToInputWrapper">
                                    <label class="calendar-input-label">Hasta</label>
                                    <input type="text" id="repCalInputTo" value="{{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}" placeholder="DD/MM/AAAA" readonly>
                                </div>
                            </div>

                            <!-- Navegación del mes -->
                            <div class="calendar-nav-header">
                                <button type="button" class="calendar-nav-btn" id="repCalPrevMonthBtn" title="Mes anterior">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <span class="calendar-month-title" id="repCalCurrentMonthTitle"></span>
                                <button type="button" class="calendar-nav-btn" id="repCalNextMonthBtn" title="Mes siguiente">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>

                            <!-- Días de la semana -->
                            <div class="calendar-days-grid mb-1">
                                <div class="calendar-day-header">L</div>
                                <div class="calendar-day-header">M</div>
                                <div class="calendar-day-header">X</div>
                                <div class="calendar-day-header">J</div>
                                <div class="calendar-day-header">V</div>
                                <div class="calendar-day-header">S</div>
                                <div class="calendar-day-header">D</div>
                            </div>

                            <!-- Días dinámicos del mes -->
                            <div class="calendar-days-grid" id="repCalDaysGrid"></div>

                            <div class="calendar-helper-note">
                                Selecciona el periodo. Se incluyen ambos días.
                            </div>

                            <div class="calendar-footer-actions">
                                <button type="button" class="calendar-btn-cancel" id="repCalCancelBtn">Cancelar</button>
                                <button type="button" class="calendar-btn-apply" id="repCalApplyBtn">Aplicar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sucursal (Compacto) -->
            <div class="modern-input-wrapper flex-shrink-0" style="width: 150px;" title="Filtrar por sucursal">
                <span class="modern-input-icon"><i class="bi bi-building"></i></span>
                <select id="repBranchSelect" class="modern-input-control" aria-label="Filtrar por sucursal">
                    <option value="">Todas las sucursales</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($branchId == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Buscador rápido (Compacto) -->
            <div class="modern-input-wrapper flex-shrink-0" style="width: 135px;" title="Buscar colaborador">
                <span class="modern-input-icon"><i class="bi bi-search"></i></span>
                <input
                    type="search"
                    id="repSearchInput"
                    value="{{ $search ?? '' }}"
                    class="modern-input-control"
                    placeholder="Buscar..."
                    aria-label="Buscar por colaborador"
                >
            </div>

            <!-- Botón Limpiar Filtros -->
            <button
                id="repResetBtn"
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

<!-- =========================================================================
     TABLA DE REGISTROS (AJAX)
     ========================================================================= -->
<div class="reports-table-card shadow-sm mb-4 reports-tab-transition" id="reportsTableContainer">
    @include('reports.partials.table', ['attendances' => $attendances])
</div>
