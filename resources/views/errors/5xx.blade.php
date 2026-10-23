@extends('errors.layout')

@section('title', 'Servicio no disponible')
@section('code')
{{ isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500 }}
@endsection
@section('tone', 'danger')
@section('label', 'Servicio no disponible')

@section('icon')
    <i class="bi bi-cloud-slash"></i>
@endsection

@section('headline', 'El servicio no respondió')

@section('message')
    El servidor tuvo un problema temporal. Espera unos segundos y vuelve a intentarlo; si se repite, envía a soporte el código de referencia de abajo.
@endsection

@section('actions')
    <button type="button" onclick="location.reload()" class="x-btn x-btn-primary">
        <i class="bi bi-arrow-clockwise"></i>
        <span>Reintentar</span>
    </button>
    <button type="button" onclick="history.length > 1 ? history.back() : (location.href = '/')" class="x-btn x-btn-ghost">
        <i class="bi bi-arrow-left"></i>
        <span>Volver atrás</span>
    </button>
@endsection
