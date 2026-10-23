@extends('errors.layout')

@php($errAuthed = rescue(fn () => Auth::check(), false, false))

@section('title', '419 — Sesión expirada')
@section('code', '419')
@section('tone', 'warning')
@section('label', 'Sesión expirada')

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
        <button type="button" onclick="location.replace(location.href)" class="e-btn e-btn-primary">
            <i class="bi bi-arrow-clockwise"></i>
            <span>Recargar página</span>
        </button>
        <a href="{{ route('dashboard') }}" class="e-btn e-btn-ghost">
            <i class="bi bi-house-door"></i>
            <span>Ir al inicio</span>
        </a>
    @else
        <a href="{{ route('login') }}" class="e-btn e-btn-primary">
            <i class="bi bi-box-arrow-in-right"></i>
            <span>Iniciar sesión</span>
        </a>
    @endif
@endsection
