@extends('layouts.app')

@section('title', $shift->exists ? 'Editar turno' : 'Nuevo turno')

@section('content')
@php
    $days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $selectedDays = old('working_days', $shift->working_days ?? []);
    $type = old('type', $shift->type ?? 'fixed');
@endphp

<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('shifts.index') }}">Turnos</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $shift->exists ? 'Editar' : 'Nuevo' }}</li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1>{{ $shift->exists ? "Editar turno: {$shift->name}" : 'Nuevo turno' }}</h1>
        <p>La tolerancia define cuántos minutos después de la hora de entrada se sigue considerando puntual.</p>
    </div>
</div>

<form method="POST" action="{{ $shift->exists ? route('shifts.update', $shift->id) : route('shifts.store') }}" class="card border-0">
    @csrf
    @if($shift->exists) @method('PUT') @endif
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label for="name" class="form-label">Nombre</label>
                <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $shift->name) }}" required>
            </div>
            <div class="col-md-4">
                <label for="code" class="form-label">Código</label>
                <input id="code" name="code" class="form-control" value="{{ old('code', $shift->code) }}">
            </div>
            <div class="col-md-4">
                <label for="shiftType" class="form-label">Tipo</label>
                <select name="type" id="shiftType" class="form-select">
                    <option value="fixed" @selected($type === 'fixed')>Fijo (días de la semana)</option>
                    <option value="rotating" @selected($type === 'rotating')>Rotativo (N días de trabajo × M de descanso)</option>
                </select>
            </div>
            <div class="col-6 col-md-4">
                <label for="start_time" class="form-label">Entrada</label>
                <input type="time" id="start_time" name="start_time" class="form-control" value="{{ old('start_time', substr($shift->start_time ?? '08:00', 0, 5)) }}" required>
            </div>
            <div class="col-6 col-md-4">
                <label for="end_time" class="form-label">Salida</label>
                <input type="time" id="end_time" name="end_time" class="form-control" value="{{ old('end_time', substr($shift->end_time ?? '17:00', 0, 5)) }}" required>
            </div>
            <div class="col-md-4">
                <label for="grace_period_minutes" class="form-label">Tolerancia de tardanza (min)</label>
                <input type="number" min="0" max="240" id="grace_period_minutes" name="grace_period_minutes" class="form-control" value="{{ old('grace_period_minutes', $shift->grace_period_minutes) }}" required>
            </div>
            <div class="col-6 col-md-4">
                <label for="daily_hours" class="form-label">Horas por día</label>
                <input type="number" step="0.25" min="0.5" max="24" id="daily_hours" name="daily_hours" class="form-control" value="{{ old('daily_hours', $shift->daily_hours) }}" required>
            </div>
            <div class="col-6 col-md-4">
                <label for="break_duration_minutes" class="form-label">Descanso (min)</label>
                <input type="number" min="0" max="480" id="break_duration_minutes" name="break_duration_minutes" class="form-control" value="{{ old('break_duration_minutes', $shift->break_duration_minutes) }}" required>
            </div>
            <div class="col-md-4">
                <label for="color" class="form-label">Color en calendario</label>
                <input type="color" id="color" name="color" class="form-control form-control-color w-100" value="{{ old('color', $shift->color) }}">
            </div>

            <div class="col-12" id="fixedFields">
                <span class="form-label d-block">Días laborables</span>
                <div class="d-flex flex-wrap gap-2" role="group" aria-label="Días laborables">
                    @foreach($days as $i => $label)
                        <input type="checkbox" class="btn-check" id="day{{ $i }}" name="working_days[]" value="{{ $i }}" autocomplete="off" @checked(in_array($i, $selectedDays))>
                        <label class="btn btn-outline-primary btn-sm" for="day{{ $i }}">{{ $label }}</label>
                    @endforeach
                </div>
            </div>

            <div class="col-12" id="rotatingFields">
                <div class="row g-3">
                    <div class="col-6 col-md-4">
                        <label for="rotation_work_days" class="form-label">Días seguidos de trabajo</label>
                        <input type="number" min="1" max="60" id="rotation_work_days" name="rotation_work_days" class="form-control" value="{{ old('rotation_work_days', $shift->rotation_work_days) }}">
                    </div>
                    <div class="col-6 col-md-4">
                        <label for="rotation_rest_days" class="form-label">Días seguidos de descanso</label>
                        <input type="number" min="1" max="60" id="rotation_rest_days" name="rotation_rest_days" class="form-control" value="{{ old('rotation_rest_days', $shift->rotation_rest_days) }}">
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex flex-wrap gap-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="break_allowed" name="break_allowed" value="1" @checked(old('break_allowed', $shift->break_allowed))>
                    <label class="form-check-label" for="break_allowed">Permite descanso</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $shift->is_active))>
                    <label class="form-check-label" for="is_active">Activo</label>
                </div>
            </div>

            <div class="col-12">
                <label for="description" class="form-label">Descripción</label>
                <textarea id="description" name="description" class="form-control" rows="2">{{ old('description', $shift->description) }}</textarea>
            </div>
        </div>
    </div>
    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
        <a href="{{ route('shifts.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        <button class="btn btn-primary">{{ $shift->exists ? 'Guardar cambios' : 'Crear turno' }}</button>
    </div>
</form>
@endsection

@section('scripts')
<script>
    (function () {
        const type = document.getElementById('shiftType');
        const toggle = () => {
            const rotating = type.value === 'rotating';
            document.getElementById('rotatingFields').classList.toggle('d-none', !rotating);
            document.getElementById('fixedFields').classList.toggle('d-none', rotating);
        };
        type.addEventListener('change', toggle);
        toggle();
    })();
</script>
@endsection
