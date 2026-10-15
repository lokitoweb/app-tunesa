@extends('layouts.guest')

@section('title', 'Recuperar contraseña')

@section('content')
@include('auth._brand')
<div class="gn-head">
    <h1>¿Olvidaste tu contraseña?</h1>
    <p>Escribe el correo con el que ingresas al panel y te enviaremos un enlace para crear una nueva. Si usas la app, pide a RR. HH. que restablezca tu acceso.</p>
</div>

@if(session('status'))
    <div class="gn-alert gn-alert-success" role="status"><i class="bi bi-envelope-check"></i><div>{{ session('status') }}</div></div>
@endif
@if($errors->any())
    <div class="gn-alert gn-alert-danger" role="alert"><i class="bi bi-exclamation-circle"></i>
        <div>@foreach($errors->all() as $error)<span>{{ $error }}</span>@endforeach</div>
    </div>
@endif

<form method="POST" action="{{ route('password.email') }}" novalidate class="gn-form">
    @csrf
    <div class="gn-field">
        <label for="email">Correo electrónico</label>
        <div class="gn-input">
            <i class="bi bi-envelope"></i>
            <input type="email" id="email" name="email" class="@error('email') is-invalid @enderror"
                   placeholder="tucorreo@empresa.com" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
    </div>
    <button type="submit" class="btn btn-primary gn-submit">Enviar enlace</button>
</form>

<a href="{{ route('login') }}" class="gn-back"><i class="bi bi-arrow-left"></i> Volver al inicio de sesión</a>
@endsection
