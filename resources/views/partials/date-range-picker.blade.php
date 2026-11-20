{{--
    Selector de periodo (mismo que Dashboard y Reportes): accesos rápidos y rango Desde/Hasta.
    Parámetros: $prefix (ids), $start y $end (Y-m-d), $preset (today|yesterday|week|last_30_days|month|prev_month|'' = personalizado),
    $label (opcional: texto del botón si no coincide un acceso rápido). Se activa con setupInteractiveCalendar (partials.date-range-engine).
--}}
@php
    $presets = ['today' => 'Hoy', 'yesterday' => 'Ayer', 'week' => 'Últimos 7 días', 'last_30_days' => 'Últimos 30 días', 'month' => 'Este mes', 'prev_month' => 'Mes anterior'];
    $dmy = fn (string $date) => \Carbon\Carbon::parse($date)->format('d/m/Y');
    // Texto del botón: el del acceso rápido elegido; si no hay, el que se pase o «Personalizado»
    $label = $presets[$preset] ?? ($label ?? null) ?? 'Personalizado';
@endphp
<div class="dropdown flex-shrink-0" id="{{ $prefix }}CalendarDropdown" data-start="{{ $start }}" data-end="{{ $end }}" data-preset="{{ $preset }}">
    <button type="button" class="date-filter-pill dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="{{ $prefix }}DateFilterDropdownBtn" title="Seleccionar periodo">
        <i class="bi bi-calendar3 text-primary"></i>
        <span class="fw-bold text-dark" id="{{ $prefix }}CalTriggerLabel">{{ $label }}</span>
        <span class="divider">|</span>
        <span id="{{ $prefix }}CalTriggerFrom">{{ $dmy($start) }}</span>
        <span class="divider">|</span>
        <span id="{{ $prefix }}CalTriggerTo">{{ $dmy($end) }}</span>
    </button>
    <div class="dropdown-menu dropdown-menu-end calendar-pro-popover shadow-lg" aria-labelledby="{{ $prefix }}DateFilterDropdownBtn">
        <div class="calendar-pro-grid">
            <div class="calendar-sidebar-presets">
                <div class="calendar-presets-heading">ACCESOS RÁPIDOS</div>
                @foreach ($presets as $key => $text)
                    <button type="button" class="calendar-preset-btn {{ $preset === $key ? 'is-active' : '' }}" data-preset="{{ $key }}">
                        <span>{{ $text }}</span>
                        <i class="bi bi-check-lg {{ $preset === $key ? '' : 'd-none' }}"></i>
                    </button>
                @endforeach
            </div>
            <div class="calendar-content-pane">
                <div class="calendar-date-inputs-row">
                    <div class="calendar-input-field is-active" id="{{ $prefix }}CalFromInputWrapper">
                        <label class="calendar-input-label" for="{{ $prefix }}CalInputFrom">Desde</label>
                        <input type="text" id="{{ $prefix }}CalInputFrom" value="{{ $dmy($start) }}" placeholder="DD/MM/AAAA" readonly>
                    </div>
                    <div class="calendar-input-field" id="{{ $prefix }}CalToInputWrapper">
                        <label class="calendar-input-label" for="{{ $prefix }}CalInputTo">Hasta</label>
                        <input type="text" id="{{ $prefix }}CalInputTo" value="{{ $dmy($end) }}" placeholder="DD/MM/AAAA" readonly>
                    </div>
                </div>
                <div class="calendar-nav-header">
                    <button type="button" class="calendar-nav-btn" id="{{ $prefix }}CalPrevMonthBtn" title="Mes anterior"><i class="bi bi-chevron-left"></i></button>
                    <span class="calendar-month-title" id="{{ $prefix }}CalCurrentMonthTitle"></span>
                    <button type="button" class="calendar-nav-btn" id="{{ $prefix }}CalNextMonthBtn" title="Mes siguiente"><i class="bi bi-chevron-right"></i></button>
                </div>
                <div class="calendar-days-grid mb-1">
                    @foreach (['L', 'M', 'X', 'J', 'V', 'S', 'D'] as $weekday)
                        <div class="calendar-day-header">{{ $weekday }}</div>
                    @endforeach
                </div>
                <div class="calendar-days-grid" id="{{ $prefix }}CalDaysGrid"></div>
                <div class="calendar-helper-note">Selecciona el periodo. Se incluyen ambos días.</div>
                <div class="calendar-footer-actions">
                    <button type="button" class="calendar-btn-cancel" id="{{ $prefix }}CalCancelBtn">Cancelar</button>
                    <button type="button" class="calendar-btn-apply" id="{{ $prefix }}CalApplyBtn">Aplicar</button>
                </div>
            </div>
        </div>
    </div>
</div>
