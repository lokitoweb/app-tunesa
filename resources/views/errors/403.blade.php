@extends('errors.layout')

@section('title', '403 — Acceso no autorizado')
@section('code', '403')

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
    <i class="bi bi-shield-lock-fill"></i>
@endsection

@section('headline', 'Acceso no autorizado')

@section('message')
    @php
        $msg = isset($exception) ? $exception->getMessage() : null;
        $isInternal = empty($msg) || str_contains($msg, 'This action is unauthorized') || str_contains($msg, 'App\\') || str_contains($msg, 'Models\\');
    @endphp
    {{ $isInternal ? 'Tu perfil de usuario no cuenta con los permisos o privilegios necesarios para acceder a esta sección. Contacta al Administrador de Recursos Humanos si requieres acceso.' : $msg }}
@endsection
