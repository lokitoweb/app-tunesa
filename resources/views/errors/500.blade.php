@extends('errors.layout')

@section('title', '500 — Incidencia del servidor')
@section('code', '500')
@section('tone', 'danger')
@section('label', 'Error del servidor')

@section('icon')
    <i class="bi bi-cpu"></i>
@endsection

@section('headline', 'Algo salió mal de nuestro lado')

@section('message')
    No es un error tuyo: el servidor no pudo completar la operación y el incidente quedó registrado.
    Espera unos segundos y vuelve a intentarlo; si se repite, envía a soporte el código de referencia de abajo.
@endsection

@section('actions')
    <button type="button" onclick="location.reload()" class="e-btn e-btn-primary">
        <i class="bi bi-arrow-clockwise"></i>
        <span>Reintentar</span>
    </button>
    <button type="button" onclick="history.length > 1 ? history.back() : (location.href = '/')" class="e-btn e-btn-ghost">
        <i class="bi bi-arrow-left"></i>
        <span>Regresar</span>
    </button>
@endsection
