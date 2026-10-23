@extends('errors.layout')

@section('title', '401 — Inicia sesión')
@section('code', '401')
@section('tone', 'info')
@section('label', 'Se requiere autenticación')

@section('icon')
    <i class="bi bi-person-lock"></i>
@endsection

@section('headline', 'Necesitas iniciar sesión')

@section('message')
    Esta sección es solo para usuarios con sesión activa. Ingresa con tu correo corporativo para continuar.
@endsection

@section('actions')
    <a href="{{ route('login') }}" class="x-btn x-btn-primary">
        <i class="bi bi-box-arrow-in-right"></i>
        <span>Iniciar sesión</span>
    </a>
@endsection
