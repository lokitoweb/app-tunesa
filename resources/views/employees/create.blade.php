@extends('layouts.app')

@section('title', 'Nuevo colaborador')

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Colaboradores</a></li>
        <li class="breadcrumb-item active" aria-current="page">Nuevo</li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1>Nuevo colaborador</h1>
        <p>Registra a la persona, su rol y las sucursales donde puede marcar asistencia.</p>
    </div>
</div>

<form method="POST" action="{{ route('employees.store') }}" enctype="multipart/form-data">
    @csrf
    @include('employees._form')
</form>
@endsection
