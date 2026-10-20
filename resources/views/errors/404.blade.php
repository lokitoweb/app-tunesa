@extends('errors.layout')

@section('title', '404 — Página no encontrada')
@section('code', '404')

@section('styles')
<style>
    :root {
        --err-badge-bg: rgba(2, 132, 199, 0.12);
        --err-badge-color: #0284c7;
        --err-badge-border: rgba(2, 132, 199, 0.25);
        --err-icon-bg: rgba(2, 132, 199, 0.1);
        --err-icon-color: #0284c7;
        --err-icon-border: rgba(2, 132, 199, 0.25);
        --err-icon-shadow: rgba(2, 132, 199, 0.2);
    }
    [data-bs-theme="dark"] {
        --err-badge-bg: rgba(56, 189, 248, 0.12);
        --err-badge-color: #38bdf8;
        --err-badge-border: rgba(56, 189, 248, 0.3);
        --err-icon-bg: rgba(56, 189, 248, 0.1);
        --err-icon-color: #38bdf8;
        --err-icon-border: rgba(56, 189, 248, 0.25);
        --err-icon-shadow: rgba(56, 189, 248, 0.25);
    }
</style>
@endsection

@section('icon')
    <i class="bi bi-search"></i>
@endsection

@section('headline', 'Página o recurso no encontrado')

@section('message')
    @php
        $msg = isset($exception) ? $exception->getMessage() : null;
        $isInternal = empty($msg) || str_contains($msg, 'No query results') || str_contains($msg, 'App\\') || str_contains($msg, 'Models\\') || str_contains($msg, 'Illuminate\\');
    @endphp
    {{ $isInternal ? 'El expediente, registro o recurso solicitado no fue encontrado en el sistema o fue reubicado. Verifica el código e intenta nuevamente.' : $msg }}
@endsection
