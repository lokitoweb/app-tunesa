@extends('errors.layout')

@php($errAuthed = rescue(fn () => Auth::check(), false, false))

@section('title', '419 — Sesión expirada')
@section('code', '419')

@section('styles')
<style>
    :root {
        --err-badge-bg: rgba(245, 158, 11, 0.12);
        --err-badge-color: #d97706;
        --err-badge-border: rgba(245, 158, 11, 0.3);
        --err-icon-bg: rgba(245, 158, 11, 0.1);
        --err-icon-color: #d97706;
        --err-icon-border: rgba(245, 158, 11, 0.25);
        --err-icon-shadow: rgba(245, 158, 11, 0.2);
    }
    [data-bs-theme="dark"] {
        --err-badge-bg: rgba(245, 158, 11, 0.15);
        --err-badge-color: #f59e0b;
        --err-badge-border: rgba(245, 158, 11, 0.35);
        --err-icon-bg: rgba(245, 158, 11, 0.12);
        --err-icon-color: #f59e0b;
        --err-icon-border: rgba(245, 158, 11, 0.3);
        --err-icon-shadow: rgba(245, 158, 11, 0.3);
    }
</style>
@endsection

@section('icon')
    <i class="bi bi-clock-history"></i>
@endsection

@section('headline', 'Tu sesión expiró')

@section('message')
    Por seguridad, la sesión se cierra tras un tiempo sin actividad y los formularios abiertos dejan de ser válidos. No se guardó ningún cambio.
    {{ $errAuthed ? 'Recarga la página y vuelve a intentarlo.' : 'Inicia sesión otra vez para continuar donde estabas.' }}
@endsection

@section('hint', 'Consejo: si vas a dejar el panel sin usar un buen rato, usa "Seguir conectado" cuando aparezca el aviso de inactividad.')

@section('actions')
    @if($errAuthed)
        <button type="button" onclick="location.replace(location.href)" class="err-btn-primary">
            <i class="bi bi-arrow-clockwise"></i>
            <span>Recargar página</span>
        </button>
        <a href="{{ route('dashboard') }}" class="err-btn-secondary">
            <i class="bi bi-house-door-fill"></i>
            <span>Ir al inicio</span>
        </a>
    @else
        <a href="{{ route('login') }}" class="err-btn-primary">
            <i class="bi bi-box-arrow-in-right"></i>
            <span>Iniciar sesión</span>
        </a>
    @endif
@endsection
