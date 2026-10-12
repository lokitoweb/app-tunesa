@extends('layouts.guest')

@section('title', 'Nueva contraseña')

@section('content')
<div class="auth-card">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            @include('auth._brand')
            <h1 class="h5 fw-semibold mb-1">Crea una nueva contraseña</h1>
            <p class="small text-body-secondary mb-3">Mínimo 8 caracteres, con letras y números. Servirá para la app y, si tienes acceso, para el panel.</p>

            @if($errors->any())
                <div class="alert alert-danger small" role="alert">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('password.update') }}" novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="mb-3">
                    <label class="form-label" for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                           value="{{ old('email', $email) }}" required autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Nueva contraseña</label>
                    <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror"
                           required autofocus autocomplete="new-password" minlength="8">
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password_confirmation">Repite la contraseña</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                           required autocomplete="new-password" minlength="8">
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">Guardar contraseña</button>
            </form>
        </div>
    </div>

    <p class="text-center small mt-3 mb-0">
        <a href="{{ route('login') }}" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Volver al inicio de sesión</a>
    </p>
</div>
@endsection
