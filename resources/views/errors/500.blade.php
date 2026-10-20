@extends('errors.layout')

@section('title', '500 — Incidencia del servidor')
@section('code', '500')

@section('styles')
<style>
    :root {
        --err-badge-bg: rgba(220, 38, 38, 0.12);
        --err-badge-color: #dc2626;
        --err-badge-border: rgba(220, 38, 38, 0.3);
        --err-icon-bg: rgba(220, 38, 38, 0.1);
        --err-icon-color: #dc2626;
        --err-icon-border: rgba(220, 38, 38, 0.25);
        --err-icon-shadow: rgba(220, 38, 38, 0.2);
    }
    [data-bs-theme="dark"] {
        --err-badge-bg: rgba(239, 68, 68, 0.15);
        --err-badge-color: #ef4444;
        --err-badge-border: rgba(239, 68, 68, 0.35);
        --err-icon-bg: rgba(239, 68, 68, 0.12);
        --err-icon-color: #ef4444;
        --err-icon-border: rgba(239, 68, 68, 0.3);
        --err-icon-shadow: rgba(239, 68, 68, 0.3);
    }
</style>
@endsection

@section('icon')
    <i class="bi bi-cpu-fill"></i>
@endsection

@section('headline', 'Incidencia interna del servidor')

@section('message')
    {{ config('app.debug') && $exception->getMessage() ? $exception->getMessage() : 'Ocurrió un error inesperado al procesar la operación en el servidor. El equipo técnico ha sido notificado y el incidente ha sido registrado en la bitácora.' }}
@endsection
