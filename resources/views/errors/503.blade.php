@extends('errors.layout')

@section('title', '503 — Servicio en mantenimiento')
@section('code', '503')
@section('tone', 'neutral')
@section('label', 'Servicio en mantenimiento')

@section('icon')
    <i class="bi bi-tools"></i>
@endsection

@section('headline', 'Volvemos en unos minutos')

@section('message')
    Estamos aplicando una actualización. La plataforma volverá a estar disponible en unos minutos; tus datos están a salvo.
@endsection

@section('actions')
    <button type="button" onclick="window.location.reload()" class="x-btn x-btn-primary">
        <i class="bi bi-arrow-clockwise"></i>
        <span>Volver a intentar</span>
    </button>
@endsection
