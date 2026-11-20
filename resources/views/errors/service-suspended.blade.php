@extends('errors.layout')

@section('title', 'Servicio suspendido')
@section('code', '503')
@section('tone', 'neutral')
@section('label', 'Servicio suspendido')

@section('icon')
    <i class="bi bi-pause-circle"></i>
@endsection

@section('headline', 'Servicio suspendido')

@section('message')
    {{ $suspendedMessage ?? \App\Modules\Core\Services\ServiceSuspension::DEFAULT_MESSAGE }}
@endsection

@section('actions')
@endsection

@section('hint', 'Código de referencia:')
