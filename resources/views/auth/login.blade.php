@extends('layouts.guest')

@section('title', 'Iniciar sesión')

@section('content')
<div class="auth-card">
    @include('auth._brand')

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

            @if(session('status'))
                <div class="alert alert-success small" role="status"><i class="bi bi-check-circle me-1"></i>{{ session('status') }}</div>
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
                    <div class="d-flex justify-content-between align-items-baseline">
                        <label class="form-label" for="password">Contraseña</label>
                        <a href="{{ route('password.request') }}" class="small text-decoration-none">¿Olvidaste tu contraseña?</a>
                    </div>
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
