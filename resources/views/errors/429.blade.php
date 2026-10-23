@extends('errors.layout')

@section('title', '429 — Demasiadas solicitudes')
@section('code', '429')
@section('tone', 'warning')
@section('label', 'Demasiadas solicitudes')

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
    <button type="button" id="errRetryBtn" onclick="location.reload()" class="e-btn e-btn-primary" disabled>
        <i class="bi bi-arrow-clockwise"></i>
        <span>Reintentar</span>
    </button>
    <button type="button" onclick="history.length > 1 ? history.back() : (location.href = '/')" class="e-btn e-btn-ghost">
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
                    if (btn) btn.disabled = false;
                }
            }, 1000);
        })();
    </script>
@endsection
