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

@section('headline', 'Demasiados intentos seguidos')

@php
    $retryAfter = isset($exception) && method_exists($exception, 'getHeaders') ? (int) ($exception->getHeaders()['Retry-After'] ?? 0) : 0;
    $retryAfter = max(5, min($retryAfter ?: 60, 3600));
@endphp

@section('message')
    Para proteger la plataforma limitamos cuántas acciones se pueden hacer en poco tiempo.
    Podrás intentarlo de nuevo en <strong id="errRetryLeft">{{ $retryAfter }}</strong> segundos.
@endsection

@section('actions')
    <button type="button" id="errRetryBtn" onclick="location.reload()" class="err-btn-primary" disabled style="opacity:.6">
        <i class="bi bi-arrow-clockwise"></i>
        <span>Reintentar</span>
    </button>
    <button type="button" onclick="history.length > 1 ? history.back() : (location.href = '/')" class="err-btn-secondary">
        <i class="bi bi-arrow-left"></i>
        <span>Regresar</span>
    </button>
    <script>
        (function () {
            let left = {{ $retryAfter }};
            const out = document.getElementById('errRetryLeft');
            const btn = document.getElementById('errRetryBtn');
            const timer = setInterval(function () {
                left--;
                if (out) out.textContent = Math.max(left, 0);
                if (left <= 0) {
                    clearInterval(timer);
                    if (btn) { btn.disabled = false; btn.style.opacity = ''; }
                }
            }, 1000);
        })();
    </script>
@endsection
