@extends('layouts.app')

@section('title', 'Corregir asistencia')

@section('content')
@php
    $a = $attendance;
    $dateLabel = \Carbon\Carbon::parse($date)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
    $hhmm = fn ($t) => $t ? $t->format('H:i') : '';
@endphp
<div class="page-header">
    <div>
        <h1>Corregir asistencia</h1>
        <p>{{ $employee->first_name }} {{ $employee->last_name }} · <span class="text-capitalize">{{ $dateLabel }}</span></p>
    </div>
    <a href="{{ route('attendance.calendar.person', ['id' => $employee->id, 'month' => substr($date, 0, 7)]) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Volver al calendario</a>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card border-0">
            <div class="card-body">
                <form method="POST" action="{{ route('attendance.manual.update', ['id' => $employee->id, 'date' => $date]) }}" novalidate>
                    @csrf
                    @method('PUT')
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <label for="clock_in" class="form-label">Entrada (24 h)</label>
                            <input type="text" id="clock_in" name="clock_in" inputmode="numeric" pattern="([01]\d|2[0-3]):[0-5]\d" placeholder="08:00" maxlength="5"
                                   value="{{ old('clock_in', $hhmm($a?->clock_in_at)) }}" class="form-control font-monospace @error('clock_in') is-invalid @enderror">
                            @error('clock_in')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-md-4">
                            <label for="clock_out" class="form-label">Salida (24 h)</label>
                            <input type="text" id="clock_out" name="clock_out" inputmode="numeric" pattern="([01]\d|2[0-3]):[0-5]\d" placeholder="17:00" maxlength="5"
                                   value="{{ old('clock_out', $hhmm($a?->clock_out_at)) }}" class="form-control font-monospace @error('clock_out') is-invalid @enderror">
                            @error('clock_out')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="break_minutes" class="form-label">Descanso tomado (min)</label>
                            <input type="number" id="break_minutes" name="break_minutes" min="0" max="480"
                                   value="{{ old('break_minutes', $a?->break_minutes) }}" class="form-control @error('break_minutes') is-invalid @enderror">
                            @error('break_minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <div class="form-text">
                                Si la salida es menor que la entrada, se toma como del día siguiente (turno nocturno).
                                Deja la entrada vacía para registrar que no vino. Siempre se descuenta al menos el descanso fijo
                                del turno en jornadas de más de 5 horas.
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="reason" class="form-label">Motivo de la corrección</label>
                            <textarea id="reason" name="reason" rows="3" maxlength="500" required placeholder="Ej.: olvidó marcar la salida; confirmado con el supervisor de turno."
                                      class="form-control @error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
                            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Queda en la bitácora con el antes y el después.</div>
                        </div>
                        @error('date')<div class="col-12"><div class="alert alert-danger mb-0">{{ $message }}</div></div>@enderror
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i> Guardar corrección</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card border-0">
            <div class="card-body small">
                <h2 class="h6">Registro actual</h2>
                @if($a)
                    <dl class="row mb-0">
                        <dt class="col-6 fw-normal text-body-secondary">Turno</dt><dd class="col-6">{{ $a->shift?->name ?? 'Sin turno' }}</dd>
                        <dt class="col-6 fw-normal text-body-secondary">Entrada / salida</dt><dd class="col-6 font-monospace">{{ $hhmm($a->clock_in_at) ?: '—' }} – {{ $hhmm($a->clock_out_at) ?: '—' }}</dd>
                        <dt class="col-6 fw-normal text-body-secondary">Horas trabajadas</dt><dd class="col-6">{{ number_format((float) $a->worked_hours, 2) }}</dd>
                        <dt class="col-6 fw-normal text-body-secondary">Horas extra</dt><dd class="col-6">{{ number_format((float) $a->overtime_hours, 2) }}</dd>
                        <dt class="col-6 fw-normal text-body-secondary">Tardanza</dt><dd class="col-6">{{ $a->late_minutes }} min</dd>
                    </dl>
                    @if($a->manual_edited_at)
                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="bi bi-pencil-square me-1"></i> Corregida a mano por {{ $a->manualEditor?->full_name ?? 'alguien' }} el {{ $a->manual_edited_at->format('d/m/Y h:i a') }}.
                            <div class="text-body-secondary">{{ $a->manual_edit_reason }}</div>
                        </div>
                    @endif
                @else
                    <p class="text-body-secondary mb-0">No hay marcación este día. Al guardar se crea la jornada con las horas que indiques.</p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
