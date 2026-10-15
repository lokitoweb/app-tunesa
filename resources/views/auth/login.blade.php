@extends('layouts.guest')

@section('title', 'Iniciar sesión')

@section('content')
@include('auth._brand')
<div class="gn-head">
    <h1>Bienvenido</h1>
    <p>Ingresa con tu correo corporativo para continuar.</p>
</div>

@if($errors->any())
    <div class="gn-alert gn-alert-danger" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <div>
            <strong>No pudimos iniciar tu sesión</strong>
            @foreach($errors->all() as $error)
                <span>{{ $error }}</span>
            @endforeach
        </div>
    </div>
@endif
@if(session('status'))
    <div class="gn-alert gn-alert-success" role="status"><i class="bi bi-check-circle"></i><div>{{ session('status') }}</div></div>
@endif
@if(session('info'))
    <div class="gn-alert gn-alert-info" role="status"><i class="bi bi-info-circle"></i><div>{{ session('info') }}</div></div>
@endif

<form method="POST" action="{{ route('login.post') }}" novalidate id="loginForm" class="gn-form">
    @csrf
    <div class="gn-field">
        <label for="email">Correo electrónico</label>
        <div class="gn-input">
            <i class="bi bi-envelope"></i>
            <input type="email" id="email" name="email" class="@error('email') is-invalid @enderror"
                   placeholder="nombre@empresa.com" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
    </div>
    <div class="gn-field">
        <div class="gn-label-row">
            <label for="password">Contraseña</label>
            <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
        </div>
        <div class="gn-input">
            <i class="bi bi-lock"></i>
            <input type="password" id="password" name="password" placeholder="Tu contraseña" required autocomplete="current-password">
            <button type="button" class="gn-eye" id="togglePassword" aria-label="Mostrar la contraseña" aria-pressed="false" title="Mostrar la contraseña">
                <i class="bi bi-eye"></i>
            </button>
        </div>
    </div>
    <div class="form-check gn-remember">
        <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
        <label class="form-check-label" for="remember">Recordar mi sesión</label>
    </div>
    <button type="submit" class="btn btn-primary gn-submit" id="loginSubmit">Ingresar</button>
</form>

<script>
    (function () {
        const password = document.getElementById('password');
        const toggle = document.getElementById('togglePassword');
        toggle.addEventListener('click', () => {
            const show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', show);
            toggle.title = show ? 'Ocultar la contraseña' : 'Mostrar la contraseña';
            toggle.setAttribute('aria-label', toggle.title);
            toggle.firstElementChild.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
        // Evita el doble envío y muestra que está entrando
        document.getElementById('loginForm').addEventListener('submit', () => {
            const button = document.getElementById('loginSubmit');
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Ingresando…';
        });
    })();
</script>
@endsection
