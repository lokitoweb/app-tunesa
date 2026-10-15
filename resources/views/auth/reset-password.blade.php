@extends('layouts.guest')

@section('title', 'Nueva contraseña')

@section('content')
@include('auth._brand')
<div class="gn-head">
    <h1>Crea una nueva contraseña</h1>
    <p>Mínimo 8 caracteres, con letras y números.</p>
</div>

@if($errors->any())
    <div class="gn-alert gn-alert-danger" role="alert"><i class="bi bi-exclamation-circle"></i>
        <div>@foreach($errors->all() as $error)<span>{{ $error }}</span>@endforeach</div>
    </div>
@endif

<form method="POST" action="{{ route('password.update') }}" novalidate class="gn-form">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="gn-field">
        <label for="email">Correo electrónico</label>
        <div class="gn-input">
            <i class="bi bi-envelope"></i>
            <input type="email" id="email" name="email" class="@error('email') is-invalid @enderror" value="{{ old('email', $email) }}" required autocomplete="username">
        </div>
    </div>
    <div class="gn-field">
        <label for="password">Nueva contraseña</label>
        <div class="gn-input">
            <i class="bi bi-lock"></i>
            <input type="password" id="password" name="password" class="@error('password') is-invalid @enderror" required autofocus autocomplete="new-password" minlength="8">
        </div>
    </div>
    <div class="gn-field">
        <label for="password_confirmation">Repite la contraseña</label>
        <div class="gn-input">
            <i class="bi bi-lock"></i>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" minlength="8">
        </div>
    </div>
    <button type="submit" class="btn btn-primary gn-submit">Guardar contraseña</button>
</form>

<a href="{{ route('login') }}" class="gn-back"><i class="bi bi-arrow-left"></i> Volver al inicio de sesión</a>
@endsection
