@extends('layouts.guest')

@section('title', 'Iniciar sesión')

@section('content')
<div class="auth-card">
    @include('auth._brand')

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 fw-bold mb-4 text-center">Iniciar sesión</h1>

            @if($errors->any())
                <div class="alert alert-danger small" role="alert">
                    <i class="bi bi-exclamation-octagon"></i>
                    <div>
                        <strong class="toast-title">No pudimos iniciar tu sesión</strong>
                        @foreach($errors->all() as $error)
                            <span class="toast-text">{{ $error }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if(session('status'))
                <div class="alert alert-success small" role="status"><i class="bi bi-check-circle"></i><div>{{ session('status') }}</div></div>
            @endif

            @if(session('info'))
                <div class="alert alert-info small" role="status"><i class="bi bi-info-circle"></i><div>{{ session('info') }}</div></div>
            @endif

            <form method="POST" action="{{ route('login.post') }}" novalidate id="loginForm">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="email">Correo electrónico</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                               placeholder="nombre@empresa.com" value="{{ old('email') }}" required autofocus autocomplete="username">
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <label class="form-label" for="password">Contraseña</label>
                        <a href="{{ route('password.request') }}" class="small text-decoration-none">¿Olvidaste tu contraseña?</a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
                        <button type="button" class="btn btn-outline-secondary" id="togglePassword" aria-label="Mostrar la contraseña" aria-pressed="false" title="Mostrar la contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
                    <label class="form-check-label small" for="remember">Recordar mi sesión</label>
                </div>
                <button type="submit" class="btn btn-primary w-100 fw-semibold auth-submit" id="loginSubmit">Ingresar</button>
            </form>
        </div>
    </div>

</div>

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
