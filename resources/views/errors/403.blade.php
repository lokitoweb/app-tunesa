@extends('errors.layout')

@section('title', '403 — Acceso no autorizado')
@section('code', '403')
@section('tone', 'warning')
@section('label', 'Acceso restringido')

@section('icon')
    <i class="bi bi-shield-lock"></i>
@endsection

@section('headline', 'Acceso no autorizado')

@section('message')
    @php
        $msg = isset($exception) ? $exception->getMessage() : null;
        $isInternal = empty($msg) || str_contains($msg, 'This action is unauthorized') || str_contains($msg, 'App\\') || str_contains($msg, 'Models\\');
    @endphp
    {{ $isInternal ? 'Tu perfil no tiene permiso para ver esta sección. Si necesitas acceso, pídelo al administrador o a Recursos Humanos de tu empresa.' : $msg }}
@endsection

@section('actions')
    @php($errAuthed403 = rescue(fn () => Auth::check(), false, false))
    <a href="{{ $errAuthed403 ? route('dashboard') : route('login') }}" class="x-btn x-btn-primary">
        <span>{{ $errAuthed403 ? 'Ir al panel principal' : 'Iniciar sesión' }}</span>
    </a>
    <button type="button" onclick="history.length > 1 ? history.back() : (location.href = '/')" class="x-btn x-btn-ghost">
        <i class="bi bi-arrow-left"></i>
        <span>Volver atrás</span>
    </button>
@endsection
