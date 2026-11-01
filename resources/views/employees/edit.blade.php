@extends('layouts.app')

@section('title', 'Editar: ' . $employee->first_name)

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Colaboradores</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $employee->first_name }} {{ $employee->last_name }}</li>
    </ol>
</nav>
{{-- Cabecera: quién es (foto o iniciales, nombre, estado y cargo) a la izquierda; accesos a la derecha --}}
@php
    $meta =array_filter([$employee->jobPosition?->name ?? $employee->position, $employee->employee_code, $employee->document_number ? 'DNI ' . $employee->document_number : null]);
@endphp
<header class="page-head emp-edit-header">
    <div class="d-flex align-items-center gap-3 min-w-0">
        @if($employee->avatar_path)
            <img src="{{ asset('storage/' . $employee->avatar_path) }}" alt="" class="emp-edit-avatar">
        @else
            <span class="emp-edit-avatar" aria-hidden="true">{{ mb_substr($employee->first_name, 0, 1) }}{{ mb_substr($employee->last_name, 0, 1) }}</span>
        @endif
        <div class="page-head-text">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="page-title text-truncate">{{ $employee->first_name }} {{ $employee->last_name }}</h1>
                <x-status-badge type="user_status" :value="$employee->status" />
            </div>
            <p class="page-subtitle">Editar ficha · {{ implode(' · ', $meta) ?: 'Datos laborales, sedes de marcación y acceso' }}</p>
        </div>
    </div>
    <div class="page-actions">
        @if(auth()->user()->can('attendance.view_all') || auth()->user()->can('attendance.view_team'))
            <a href="{{ route('attendance.calendar.person', $employee->id) }}" class="btn btn-outline-secondary"><i class="bi bi-calendar3 me-1"></i>Calendario</a>
        @endif
        @can('profiles.manage')
            <a href="{{ route('employees.file', $employee->id) }}" class="btn btn-outline-primary"><i class="bi bi-folder2-open me-1"></i>Ver legajo</a>
        @endcan
    </div>
</header>

@include('employees._credentials')

{{-- Fuera del formulario principal: un formulario no puede ir dentro de otro --}}
<form method="POST" action="{{ route('employees.reset-access', $employee->id) }}" id="resetAccessForm"
      data-confirm="Se generará una contraseña temporal nueva y se cerrarán sus sesiones en la app." data-confirm-title="¿Restablecer la contraseña?" data-confirm-button="Restablecer" data-confirm-danger="1">
    @csrf
</form>

<form method="POST" action="{{ route('employees.update', $employee->id) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('employees._form', ['employee' => $employee])
</form>
@endsection
