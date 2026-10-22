@extends('errors.layout')

@section('title', 'Solicitud no válida')
@section('code')
{{ isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 400 }}
@endsection

@section('icon')
    <i class="bi bi-exclamation-diamond-fill"></i>
@endsection

@section('headline', 'No pudimos procesar esta solicitud')

@section('message')
    Los datos enviados no son válidos o el enlace está incompleto. Vuelve a la pantalla anterior e inténtalo otra vez.
@endsection
