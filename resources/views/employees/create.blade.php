@extends('layouts.app')

@section('title', 'Nuevo colaborador')

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Colaboradores</a></li>
        <li class="breadcrumb-item active" aria-current="page">Nuevo</li>
    </ol>
</nav>
<header class="page-head emp-edit-header">
    <div class="d-flex align-items-center gap-3 min-w-0">
        <span class="emp-edit-avatar" aria-hidden="true"><i class="bi bi-person-plus"></i></span>
        <div class="page-head-text">
            <h1 class="page-title">Nuevo colaborador</h1>
            <p class="page-subtitle">Registra a la persona, su rol y las sedes donde puede marcar asistencia.</p>
        </div>
    </div>
</header>

<form method="POST" action="{{ route('employees.store') }}" enctype="multipart/form-data">
    @csrf
    @include('employees._form')
</form>
@endsection
