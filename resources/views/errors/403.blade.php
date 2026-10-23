@extends('errors.layout')

@section('title', '403 — Acceso no autorizado')
@section('code', '403')
@section('tone', 'warning')
@section('label', 'Acceso restringido')

@section('icon')
    <i class="bi bi-shield-lock"></i>
@endsection

@section('headline', 'Acceso no autorizado')

@section('message')
    @php
        $msg = isset($exception) ? $exception->getMessage() : null;
        $isInternal = empty($msg) || str_contains($msg, 'This action is unauthorized') || str_contains($msg, 'App\\') || str_contains($msg, 'Models\\');
    @endphp
    {{ $isInternal ? 'Tu perfil no tiene permiso para ver esta sección. Si necesitas acceso, pídelo al administrador o a Recursos Humanos de tu empresa.' : $msg }}
@endsection
