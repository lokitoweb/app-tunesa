@extends('layouts.app')

@section('title', 'Corregir asistencia')

@section('content')
@php
    $a = $attendance;
    $dateLabel = \Carbon\Carbon::parse($date)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
    $hhmm = fn ($t) => $t ? $t->format('H:i') : '';
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 fw-bold" style="border-radius: 6px; font-size: 0.725rem;">
                <i class="bi bi-clock-history me-1"></i>Asistencia Manual
            </span>
            <span class="badge bg-secondary-subtle text-secondary border px-2.5 py-1 fw-semibold" style="border-radius: 6px; font-size: 0.725rem;">
                <i class="bi bi-calendar-event me-1"></i>{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}
            </span>
        </div>
        <h1 class="h3 fw-bold mb-1 text-body">Corregir asistencia</h1>
        <p class="text-secondary small mb-0">
            <span class="fw-semibold text-body">{{ $employee->first_name }} {{ $employee->last_name }}</span> · <span class="text-capitalize">{{ $dateLabel }}</span>
        </p>
    </div>
    <div>
        <a href="{{ route('attendance.calendar.person', ['id' => $employee->id, 'month' => substr($date, 0, 7)]) }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5 shadow-sm" style="border-radius: 8px; font-weight: 600; padding: 7px 14px; border: 1px solid var(--border-color); background: var(--bg-surface); color: var(--text-body);">
            <i class="bi bi-arrow-left"></i><span>Volver al calendario</span>
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm" style="border-radius: 12px; border: 1px solid var(--border-color) !important; background: var(--bg-surface);">
            <div class="card-header bg-transparent p-4 pb-3 border-bottom d-flex align-items-center gap-2.5" style="border-color: var(--border-color) !important;">
                <div class="d-inline-flex align-items-center justify-content-center rounded-2 bg-primary-subtle text-primary shadow-sm" style="width: 36px; height: 36px;">
                    <i class="bi bi-pencil-square" style="font-size: 1.1rem;"></i>
                </div>
                <div>
                    <h2 class="h6 fw-bold mb-0 text-body">Formulario de Regularización</h2>
                    <div class="text-secondary small" style="font-size: 0.775rem;">Ingresa los horarios reales laborados para actualizar la jornada</div>
                </div>
            </div>

            <div class="card-body p-4">
                <form method="POST" action="{{ route('attendance.manual.update', ['id' => $employee->id, 'date' => $date]) }}" novalidate>
                    @csrf
                    @method('PUT')
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <label for="clock_in" class="form-label small fw-semibold text-body mb-1.5">Entrada (24 h)</label>
                            <div class="position-relative">
                                <i class="bi bi-box-arrow-in-right position-absolute top-50 translate-middle-y text-secondary" style="left: 0.85rem; pointer-events: none; font-size: 0.95rem; z-index: 5;"></i>
                                <input type="text" id="clock_in" name="clock_in" inputmode="numeric" pattern="([01]\d|2[0-3]):[0-5]\d" placeholder="08:00" maxlength="5"
                                       value="{{ old('clock_in', $hhmm($a?->clock_in_at)) }}" 
                                       class="form-control font-monospace @error('clock_in') is-invalid @enderror"
                                       style="padding-left: 2.5rem; height: 42px; border-radius: 8px; border-color: var(--border-color); font-size: 0.9rem;">
                            </div>
                            @error('clock_in')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-md-4">
                            <label for="clock_out" class="form-label small fw-semibold text-body mb-1.5">Salida (24 h)</label>
                            <div class="position-relative">
                                <i class="bi bi-box-arrow-right position-absolute top-50 translate-middle-y text-secondary" style="left: 0.85rem; pointer-events: none; font-size: 0.95rem; z-index: 5;"></i>
                                <input type="text" id="clock_out" name="clock_out" inputmode="numeric" pattern="([01]\d|2[0-3]):[0-5]\d" placeholder="17:00" maxlength="5"
                                       value="{{ old('clock_out', $hhmm($a?->clock_out_at)) }}" 
                                       class="form-control font-monospace @error('clock_out') is-invalid @enderror"
                                       style="padding-left: 2.5rem; height: 42px; border-radius: 8px; border-color: var(--border-color); font-size: 0.9rem;">
                            </div>
                            @error('clock_out')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="break_minutes" class="form-label small fw-semibold text-body mb-1.5">Descanso tomado (min)</label>
                            <div class="position-relative">
                                <i class="bi bi-cup-hot position-absolute top-50 translate-middle-y text-secondary" style="left: 0.85rem; pointer-events: none; font-size: 0.95rem; z-index: 5;"></i>
                                <input type="number" id="break_minutes" name="break_minutes" min="0" max="480"
                                       value="{{ old('break_minutes', $a?->break_minutes) }}" 
                                       class="form-control @error('break_minutes') is-invalid @enderror"
                                       placeholder="0"
                                       style="padding-left: 2.5rem; height: 42px; border-radius: 8px; border-color: var(--border-color); font-size: 0.9rem;">
                            </div>
                            @error('break_minutes')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <div class="p-3 rounded-3 d-flex align-items-start gap-2.5 border" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important; border-radius: 8px;">
                                <i class="bi bi-info-circle-fill text-primary mt-0.5 flex-shrink-0" style="font-size: 0.95rem;"></i>
                                <div class="small text-secondary" style="line-height: 1.45; font-size: 0.775rem;">
                                    Si la hora de salida es menor que la entrada, se procesa automáticamente como jornada nocturna (día siguiente). Deja la entrada en blanco si el colaborador no asistió.
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="reason" class="form-label small fw-semibold text-body mb-1.5">
                                <i class="bi bi-chat-left-text text-primary me-1"></i>Motivo de la corrección
                            </label>
                            <textarea id="reason" name="reason" rows="3" maxlength="500" required 
                                      placeholder="Ej.: Olvidó marcar la salida física; confirmado con supervisor de operaciones."
                                      class="form-control @error('reason') is-invalid @enderror" 
                                      style="border-radius: 8px; border-color: var(--border-color); font-size: 0.875rem; padding: 10px 12px;">{{ old('reason') }}</textarea>
                            @error('reason')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <div class="text-secondary small mt-1" style="font-size: 0.725rem;">
                                <i class="bi bi-shield-check me-1"></i>Queda registrado en la bitácora de auditoría con fecha, hora y usuario responsable.
                            </div>
                        </div>
                        @error('date')<div class="col-12"><div class="alert alert-danger mb-0" style="border-radius: 8px;">{{ $message }}</div></div>@enderror
                        <div class="col-12 d-flex align-items-center justify-content-between pt-2 border-top" style="border-color: var(--border-color) !important;">
                            <a href="{{ route('attendance.calendar.person', ['id' => $employee->id, 'month' => substr($date, 0, 7)]) }}" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px; font-weight: 600; padding: 8px 16px;">
                                Cancelar
                            </a>
                            <button type="submit" class="btn btn-primary text-white d-inline-flex align-items-center gap-2 shadow-sm" style="border-radius: 8px; font-weight: 600; padding: 8px 20px;">
                                <i class="bi bi-check2"></i>
                                <span>Guardar corrección</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm" style="border-radius: 12px; border: 1px solid var(--border-color) !important; background: var(--bg-surface);">
            <div class="card-header bg-transparent p-4 pb-3 border-bottom d-flex align-items-center justify-content-between" style="border-color: var(--border-color) !important;">
                <div class="d-flex align-items-center gap-2">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-2 bg-info-subtle text-info shadow-sm" style="width: 36px; height: 36px;">
                        <i class="bi bi-clock-history" style="font-size: 1.1rem;"></i>
                    </div>
                    <div>
                        <h2 class="h6 fw-bold mb-0 text-body">Registro actual</h2>
                        <div class="text-secondary small" style="font-size: 0.775rem;">Estado registrado en el sistema</div>
                    </div>
                </div>
                <span class="badge {{ $a ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle' }} px-2.5 py-1" style="border-radius: 6px; font-weight: 600; font-size: 0.725rem;">
                    {{ $a ? 'Con marcación' : 'Falta / Sin marcación' }}
                </span>
            </div>

            <div class="card-body p-4">
                @if($a)
                    <div class="d-flex flex-column gap-2.5 mb-3">
                        <div class="p-2.5 rounded-3 border d-flex align-items-center justify-content-between" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important; border-radius: 8px;">
                            <div class="d-flex align-items-center gap-2 min-w-0">
                                <i class="bi bi-calendar3-event text-secondary" style="font-size: 0.9rem;"></i>
                                <span class="text-secondary small" style="font-size: 0.775rem; font-weight: 600;">Turno programado</span>
                            </div>
                            <span class="fw-semibold text-body text-truncate" style="font-size: 0.85rem;">{{ $a->shift?->name ?? 'Sin turno asignado' }}</span>
                        </div>

                        <div class="p-2.5 rounded-3 border d-flex align-items-center justify-content-between" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important; border-radius: 8px;">
                            <div class="d-flex align-items-center gap-2 min-w-0">
                                <i class="bi bi-arrow-left-right text-secondary" style="font-size: 0.9rem;"></i>
                                <span class="text-secondary small" style="font-size: 0.775rem; font-weight: 600;">Entrada / Salida</span>
                            </div>
                            <span class="fw-bold font-monospace text-body" style="font-size: 0.85rem;">
                                {{ $hhmm($a->clock_in_at) ?: '—' }} <span class="text-secondary">a</span> {{ $hhmm($a->clock_out_at) ?: '—' }}
                            </span>
                        </div>

                        <div class="row g-2">
                            <div class="col-4">
                                <div class="p-2.5 rounded-3 border text-center" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important; border-radius: 8px;">
                                    <div class="text-secondary" style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase;">Trabajadas</div>
                                    <div class="fw-bold text-body mt-1" style="font-size: 1rem;">{{ number_format((float) $a->worked_hours, 2) }} <span class="fw-normal text-secondary" style="font-size: 0.75rem;">h</span></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2.5 rounded-3 border text-center" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important; border-radius: 8px;">
                                    <div class="text-secondary" style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase;">H. Extra</div>
                                    <div class="fw-bold text-body mt-1" style="font-size: 1rem;">{{ number_format((float) $a->overtime_hours, 2) }} <span class="fw-normal text-secondary" style="font-size: 0.75rem;">h</span></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2.5 rounded-3 border text-center" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important; border-radius: 8px;">
                                    <div class="text-secondary" style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase;">Tardanza</div>
                                    <div class="fw-bold {{ $a->late_minutes > 0 ? 'text-warning-emphasis' : 'text-body' }} mt-1" style="font-size: 1rem;">{{ $a->late_minutes }} <span class="fw-normal text-secondary" style="font-size: 0.75rem;">min</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if($a->manual_edited_at)
                        <div class="p-3 rounded-3 border d-flex align-items-start gap-2.5" style="background: rgba(245, 158, 11, 0.08); border-color: rgba(245, 158, 11, 0.25) !important; border-radius: 8px;">
                            <i class="bi bi-pencil-square text-warning-emphasis mt-0.5 flex-shrink-0"></i>
                            <div class="small" style="line-height: 1.45;">
                                <div class="fw-semibold text-warning-emphasis">Previamente corregida</div>
                                <div class="text-secondary" style="font-size: 0.775rem;">Por {{ $a->manualEditor?->full_name ?? 'Usuario' }} el {{ $a->manual_edited_at->format('d/m/Y h:i a') }}</div>
                                <div class="text-body mt-1 p-2 rounded bg-white bg-opacity-50" style="font-size: 0.8rem; border: 1px solid rgba(245, 158, 11, 0.15);">"{{ $a->manual_edit_reason }}"</div>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="text-center py-4">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger-subtle text-danger mb-2" style="width: 48px; height: 48px;">
                            <i class="bi bi-calendar-x" style="font-size: 1.4rem;"></i>
                        </div>
                        <h3 class="h6 fw-semibold text-body mb-1">Sin marcación registrada</h3>
                        <p class="text-secondary small mb-0" style="max-width: 300px; margin: 0 auto;">No hay marcación este día. Al guardar se crea la jornada con las horas que indiques.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
