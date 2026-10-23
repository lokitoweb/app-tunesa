@extends('errors.layout')

@section('title', '404 — Página no encontrada')
@section('code', '404')
@section('tone', 'info')
@section('label', 'Página no encontrada')

@section('icon')
    <i class="bi bi-search"></i>
@endsection

@section('headline', 'No encontramos esta página')

@section('message')
    @php
        $msg = isset($exception) ? $exception->getMessage() : null;
        $isInternal = empty($msg) || str_contains($msg, 'No query results') || str_contains($msg, 'could not be found') || str_contains($msg, 'The route') || str_contains($msg, 'App\\') || str_contains($msg, 'Models\\') || str_contains($msg, 'Illuminate\\');
    @endphp
    {{ $isInternal ? 'La dirección no existe o el registro fue movido o eliminado. Revisa el enlace o vuelve a la pantalla anterior.' : $msg }}
@endsection
