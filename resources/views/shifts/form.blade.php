@extends('layouts.app')

@section('title', $shift->exists ? 'Editar turno' : 'Nuevo turno')

@section('content')
@php
    $days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $selectedDays = old('working_days', $shift->working_days ?? []);
    $type = old('type', $shift->type ?? 'fixed');
@endphp

<div class="page-head">
    <div>
        <h1>{{ $shift->exists ? "Editar turno: {$shift->name}" : 'Nuevo turno' }}</h1>
        <p>La tolerancia define cuántos minutos después de la hora de entrada se sigue considerando puntual.</p>
    </div>
    <a href="{{ route('shifts.index') }}" class="btn btn-ghost">← Volver</a>
</div>

@if ($errors->any())
    <div class="card" style="margin-bottom: 1rem; border-color: rgba(239,68,68,0.3);">
        <div class="card-body" style="color: #fca5a5;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    </div>
@endif

<form method="POST" action="{{ $shift->exists ? route('shifts.update', $shift->id) : route('shifts.store') }}" class="card">
    @csrf
    @if($shift->exists) @method('PUT') @endif
    <div class="card-body">
        <div class="grid-3">
            <div class="form-group">
                <label class="form-label">Nombre</label>
                <input name="name" class="form-control" value="{{ old('name', $shift->name) }}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Código</label>
                <input name="code" class="form-control" value="{{ old('code', $shift->code) }}">
            </div>
            <div class="form-group">
                <label class="form-label">Tipo</label>
                <select name="type" id="shiftType" class="form-control">
                    <option value="fixed" @selected($type === 'fixed')>Fijo (días de la semana)</option>
                    <option value="rotating" @selected($type === 'rotating')>Rotativo (N días trabajo × M descanso)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Entrada</label>
                <input type="time" name="start_time" class="form-control" value="{{ old('start_time', substr($shift->start_time ?? '08:00', 0, 5)) }}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Salida</label>
                <input type="time" name="end_time" class="form-control" value="{{ old('end_time', substr($shift->end_time ?? '17:00', 0, 5)) }}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Tolerancia de tardanza (min)</label>
                <input type="number" min="0" max="240" name="grace_period_minutes" class="form-control" value="{{ old('grace_period_minutes', $shift->grace_period_minutes) }}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Horas por día</label>
                <input type="number" step="0.25" min="0.5" max="24" name="daily_hours" class="form-control" value="{{ old('daily_hours', $shift->daily_hours) }}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Descanso (min)</label>
                <input type="number" min="0" max="480" name="break_duration_minutes" class="form-control" value="{{ old('break_duration_minutes', $shift->break_duration_minutes) }}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Color en calendario</label>
                <input type="color" name="color" class="form-control" style="height: 42px; padding: 4px;" value="{{ old('color', $shift->color) }}">
            </div>
        </div>

        <div class="form-group" id="fixedFields">
            <label class="form-label">Días laborables</label>
            <div class="day-picker">
                @foreach($days as $i => $label)
                    <label><input type="checkbox" name="working_days[]" value="{{ $i }}" @checked(in_array($i, $selectedDays))> {{ $label }}</label>
                @endforeach
            </div>
        </div>

        <div class="grid-3" id="rotatingFields">
            <div class="form-group">
                <label class="form-label">Días seguidos de trabajo</label>
                <input type="number" min="1" max="60" name="rotation_work_days" class="form-control" value="{{ old('rotation_work_days', $shift->rotation_work_days) }}">
            </div>
            <div class="form-group">
                <label class="form-label">Días seguidos de descanso</label>
                <input type="number" min="1" max="60" name="rotation_rest_days" class="form-control" value="{{ old('rotation_rest_days', $shift->rotation_rest_days) }}">
            </div>
        </div>

        <div class="form-group day-picker">
            <label><input type="checkbox" name="break_allowed" value="1" @checked(old('break_allowed', $shift->break_allowed))> Permite descanso</label>
            <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $shift->is_active))> Activo</label>
        </div>

        <div class="form-group">
            <label class="form-label">Descripción</label>
            <textarea name="description" class="form-control" rows="2">{{ old('description', $shift->description) }}</textarea>
        </div>

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
            document.getElementById('rotatingFields').style.display = rotating ? '' : 'none';
            document.getElementById('fixedFields').style.display = rotating ? 'none' : '';
        };
        type.addEventListener('change', toggle);
        toggle();
    })();
</script>
@endsection
