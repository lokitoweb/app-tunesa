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
    @can('profiles.manage')
        <a href="{{ route('employees.file', $employee->id) }}" class="btn btn-outline-primary"><i class="bi bi-folder2-open me-1"></i>Ver legajo</a>
    @endcan
</div>

<form method="POST" action="{{ route('employees.update', $employee->id) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('employees._form', ['employee' => $employee])
</form>
@endsection
