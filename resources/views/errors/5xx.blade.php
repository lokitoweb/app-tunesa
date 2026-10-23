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
