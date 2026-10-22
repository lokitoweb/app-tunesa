@extends('layouts.app')

@section('title', 'Editar: ' . $employee->first_name)

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Colaboradores</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $employee->first_name }} {{ $employee->last_name }}</li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1>Editar a {{ $employee->first_name }} {{ $employee->last_name }}</h1>
        <p>Datos laborales, sucursales de marcación y credenciales.</p>
    </div>
    <div class="d-flex gap-2">
        @if(auth()->user()->can('attendance.view_all') || auth()->user()->can('attendance.view_team'))
            <a href="{{ route('attendance.calendar.person', $employee->id) }}" class="btn btn-outline-secondary"><i class="bi bi-calendar3 me-1"></i>Calendario</a>
        @endif
        @can('profiles.manage')
            <a href="{{ route('employees.file', $employee->id) }}" class="btn btn-outline-primary"><i class="bi bi-folder2-open me-1"></i>Ver legajo</a>
        @endcan
    </div>
</div>

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
