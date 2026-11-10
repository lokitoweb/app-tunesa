@extends('layouts.guest')

@section('title', 'Crear nueva contraseña')

@section('content')
@php
    $brandTenant = isset($tenant) && $tenant instanceof \App\Modules\Core\Models\Tenant ? $tenant : \App\Modules\Core\Models\Tenant::fromHost(request()->getHost());
    $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($brandTenant);
@endphp
<div class="antigravity-composer">
    <div class="antigravity-auth-card" id="resetPasswordCard">

        <div class="card-brand-logo-wrap">
            <img src="{{ $brandSettings->displayLogoUrl() }}" alt="{{ $brandSettings->tradeName() }}" class="card-brand-logo">
        </div>

        {{-- Enlace de un solo uso del correo (PasswordRecovery): la contraseña cambia recién al enviar --}}
        <h2 class="card-hero-title">Crea tu nueva contraseña</h2>
        @if(! $valid)
            <p class="card-hero-subtitle" role="alert">Este enlace ya no sirve: venció o ya se usó. Pide uno nuevo desde «¿Olvidaste tu contraseña?».</p>
            <a href="{{ route('password.request') }}" class="organic-stone-btn" style="margin-top: 1.25rem; text-decoration: none;">
                <span class="btn-engraved-text">Pedir un enlace nuevo</span>
                <span class="btn-engraved-arrow">→</span>
            </a>
        @else
            <p class="card-hero-subtitle">Mínimo 8 caracteres, con mayúsculas, minúsculas y números. Al guardarla se cerrarán tus sesiones abiertas.</p>

            <form method="POST" action="{{ route('password.update', ['token' => $token]) }}" novalidate class="antigravity-form" style="margin-top: 1.25rem;">
                @csrf
                @foreach(['password' => 'Nueva contraseña', 'password_confirmation' => 'Repite la contraseña'] as $field => $label)
                    <div class="glass-field-group">
                        <label for="{{ $field }}" class="glass-field-label">{{ $label }}</label>
                        <div class="glass-input-plane">
                            <div class="glass-input-icon-lead">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lead-accent-icon">
                                    <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                            </div>
                            <input type="password" id="{{ $field }}" name="{{ $field }}" class="glass-input-elem @error('password') is-invalid @enderror"
                                   required autocomplete="new-password" maxlength="128" @if($loop->first) autofocus @endif>
                        </div>
                    </div>
                @endforeach
                @error('password')<div class="small text-danger mt-1" role="alert">{{ $message }}</div>@enderror
                <button type="submit" class="organic-stone-btn">
                    <span class="btn-engraved-text">Guardar contraseña</span>
                    <span class="btn-engraved-arrow">→</span>
                </button>
            </form>
        @endif

        <div class="card-security-note" style="margin-top: 1.5rem;">
            <a href="{{ route('login') }}" class="glass-forgot-link" style="font-size: 13px;">Volver al inicio de sesión</a>
        </div>
    </div>
</div>
@endsection
