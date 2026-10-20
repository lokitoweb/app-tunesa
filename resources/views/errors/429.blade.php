@extends('errors.layout')

@section('title', '429 — Demasiadas solicitudes')
@section('code', '429')

@section('styles')
<style>
    :root {
        --err-badge-bg: rgba(124, 58, 237, 0.12);
        --err-badge-color: #7c3aed;
        --err-badge-border: rgba(124, 58, 237, 0.3);
        --err-icon-bg: rgba(124, 58, 237, 0.1);
        --err-icon-color: #7c3aed;
        --err-icon-border: rgba(124, 58, 237, 0.25);
        --err-icon-shadow: rgba(124, 58, 237, 0.2);
    }
    [data-bs-theme="dark"] {
        --err-badge-bg: rgba(167, 139, 250, 0.15);
        --err-badge-color: #a78bfa;
        --err-badge-border: rgba(167, 139, 250, 0.35);
        --err-icon-bg: rgba(167, 139, 250, 0.12);
        --err-icon-color: #a78bfa;
        --err-icon-border: rgba(167, 139, 250, 0.3);
        --err-icon-shadow: rgba(167, 139, 250, 0.3);
    }
</style>
@endsection

@section('icon')
    <i class="bi bi-speedometer2"></i>
@endsection

@section('headline', 'Límite de peticiones alcanzado')

@section('message')
    Has realizado múltiples solicitudes en un intervalo breve. Por salvaguarda del rendimiento del servicio y prevención contra sobrecarga, te solicitamos aguardar unos segundos antes de realizar una nueva acción.
@endsection

@section('actions')
    <button type="button" onclick="setTimeout(() => window.location.reload(), 1500)" class="err-btn-primary">
        <i class="bi bi-arrow-clockwise"></i>
        <span>Reintentar Operación</span>
    </button>
    <a href="{{ route('dashboard') }}" class="err-btn-secondary">
        <i class="bi bi-grid-fill"></i>
        <span>Volver al Dashboard</span>
    </a>
@endsection
