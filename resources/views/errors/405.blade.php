@extends('errors.layout')

@section('title', '405 — Acción no válida')
@section('code', '405')
@section('tone', 'neutral')
@section('label', 'Acción no permitida')

@section('icon')
    <i class="bi bi-slash-circle"></i>
@endsection

@section('headline', 'Esta acción no se puede abrir así')

@section('message')
    El enlace que usaste no corresponde a una página: suele pasar al recargar después de enviar un formulario o al abrir un enlace guardado. Vuelve a la pantalla anterior y repite la acción desde el botón.
@endsection
