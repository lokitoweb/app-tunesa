@extends('errors.layout')

@section('title', '401 — Inicia sesión')
@section('code', '401')

@section('icon')
    <i class="bi bi-person-lock"></i>
@endsection

@section('headline', 'Necesitas iniciar sesión')

@section('message')
    Esta sección es solo para usuarios con sesión activa. Ingresa con tu correo corporativo para continuar.
@endsection
