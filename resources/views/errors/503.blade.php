@extends('errors.layout')

@section('title', '503 — Servicio en mantenimiento')
@section('code', '503')

@section('styles')
<style>
    :root {
        --err-badge-bg: rgba(5, 150, 105, 0.12);
        --err-badge-color: #059669;
        --err-badge-border: rgba(5, 150, 105, 0.3);
        --err-icon-bg: rgba(5, 150, 105, 0.1);
        --err-icon-color: #059669;
        --err-icon-border: rgba(5, 150, 105, 0.25);
        --err-icon-shadow: rgba(5, 150, 105, 0.2);
    }
    [data-bs-theme="dark"] {
        --err-badge-bg: rgba(16, 185, 129, 0.15);
        --err-badge-color: #10b981;
        --err-badge-border: rgba(16, 185, 129, 0.35);
        --err-icon-bg: rgba(16, 185, 129, 0.12);
        --err-icon-color: #10b981;
        --err-icon-border: rgba(16, 185, 129, 0.3);
        --err-icon-shadow: rgba(16, 185, 129, 0.3);
    }
</style>
@endsection

@section('icon')
    <i class="bi bi-tools"></i>
@endsection

@section('headline', 'Mantenimiento del sistema')

@section('message')
    Estamos ejecutando labores de mantenimiento y optimización de infraestructura en la plataforma. La plataforma estará disponible nuevamente en unos momentos. Agradecemos tu comprensión.
@endsection

@section('actions')
    <button type="button" onclick="window.location.reload()" class="err-btn-primary">
        <i class="bi bi-arrow-clockwise"></i>
        <span>Comprobar Estado</span>
    </button>
@endsection
