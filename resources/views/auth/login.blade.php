@extends('layouts.guest')

@section('title', 'Iniciar sesión')

@section('content')
<div class="auth-card">
    <div class="text-center mb-4">
        @if(isset($tenant) && $tenant)
            @if($tenant->logo_path)
                <img src="{{ asset('storage/' . $tenant->logo_path) }}" alt="{{ $tenant->company_name }}" class="mb-2" style="max-height: 56px;">
            @endif
            <div class="h5 fw-bold mb-0">{{ $tenant->company_name }}</div>
        @else
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white mb-2" style="width: 48px; height: 48px;">
                <i class="bi bi-shield-check fs-4"></i>
            </span>
            <div class="h5 fw-bold mb-0">TuneSA</div>
        @endif
        <div class="small text-body-secondary">Asistencia, geocercas y planilla</div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h1 class="h5 fw-semibold mb-3">Ingresa al panel</h1>

            @if($errors->any())
                <div class="alert alert-danger small" role="alert">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            @if(session('info'))
                <div class="alert alert-info small" role="status">{{ session('info') }}</div>
            @endif

            <form method="POST" action="{{ route('login.post') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                           placeholder="admin@empresa.com" value="{{ old('email') }}" required autofocus autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Contraseña</label>
                    <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
                    <label class="form-check-label small" for="remember">Recordar mi sesión</label>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">Ingresar</button>
            </form>
        </div>
    </div>

    <p class="text-center small text-body-secondary mt-3 mb-0">
        Acceso para administradores, RRHH y supervisores.<br>
        Los colaboradores marcan desde la app móvil.
    </p>
</div>
@endsection
