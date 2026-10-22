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

@section('headline', 'Algo salió mal de nuestro lado')

@section('message')
    No es un error tuyo: el servidor no pudo completar la operación y el incidente quedó registrado.
    Espera unos segundos y vuelve a intentarlo; si se repite, envía a soporte el código de referencia de abajo.
@endsection

@section('actions')
    <button type="button" onclick="location.reload()" class="err-btn-primary">
        <i class="bi bi-arrow-clockwise"></i>
        <span>Reintentar</span>
    </button>
    <button type="button" onclick="history.length > 1 ? history.back() : (location.href = '/')" class="err-btn-secondary">
        <i class="bi bi-arrow-left"></i>
        <span>Regresar</span>
    </button>
@endsection
