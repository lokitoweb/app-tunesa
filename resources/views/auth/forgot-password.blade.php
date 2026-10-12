@extends('layouts.guest')

@section('title', 'Recuperar contraseña')

@section('content')
<div class="auth-card">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            @include('auth._brand')
            <h1 class="h5 fw-semibold mb-1">¿Olvidaste tu contraseña?</h1>
            <p class="small text-body-secondary mb-3">Escribe el correo con el que ingresas al panel o a la app. Te enviaremos un enlace para crear una nueva contraseña.</p>

            @if(session('status'))
                <div class="alert alert-success small" role="status"><i class="bi bi-envelope-check me-1"></i>{{ session('status') }}</div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger small" role="alert">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" novalidate>
                @csrf
                <div class="mb-4">
                    <label class="form-label" for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                           placeholder="tucorreo@empresa.com" value="{{ old('email') }}" required autofocus autocomplete="username">
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">Enviar enlace</button>
            </form>
        </div>
    </div>

    <p class="text-center small mt-3 mb-0">
        <a href="{{ route('login') }}" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver al inicio de sesión</a>
    </p>
</div>
@endsection
