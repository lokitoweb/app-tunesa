@extends('layouts.guest')

@section('title', 'Recuperar contraseña')

@section('content')
@php
    $brandTenant = isset($tenant) && $tenant instanceof \App\Modules\Core\Models\Tenant ? $tenant : \App\Modules\Core\Models\Tenant::fromHost(request()->getHost());
    $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($brandTenant);
@endphp
<div class="antigravity-composer">
    <div class="antigravity-auth-card" id="forgotPasswordCard">

        {{-- Logo Oficial Centrado --}}
        <div class="card-brand-logo-wrap">
            <img src="{{ $brandSettings->displayLogoUrl() }}" alt="{{ $brandSettings->tradeName() }}" class="card-brand-logo">
        </div>

        {{-- Contraseña temporal por correo (PasswordRecovery): la respuesta es la misma exista o no el correo --}}
        <h2 class="card-hero-title">¿Olvidaste tu contraseña?</h2>
        @if(session('status'))
            <p class="card-hero-subtitle" role="status">{{ session('status') }}</p>
        @else
            <p class="card-hero-subtitle">Escribe tu correo y te enviaremos una contraseña temporal. Al ingresar te pediremos crear una nueva.</p>

            <form method="POST" action="{{ route('password.email') }}" novalidate class="antigravity-form" style="margin-top: 1.25rem;">
                @csrf
                {{-- Trampa contra bots (igual que el login) --}}
                <input type="text" name="_auth_token_verify" value="" style="display:none !important;" tabindex="-1" autocomplete="off" aria-hidden="true">
                <div class="glass-field-group">
                    <label for="email" class="glass-field-label">Correo corporativo</label>
                    <div class="glass-input-plane">
                        <div class="glass-input-icon-lead">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lead-accent-icon">
                                <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                            </svg>
                        </div>
                        <input type="email" id="email" name="email" class="glass-input-elem @error('email') is-invalid @enderror"
                               placeholder="nombre@empresa.com" value="{{ old('email') }}" required autofocus autocomplete="username" spellcheck="false" maxlength="180">
                    </div>
                    @error('email')<div class="small text-danger mt-1" role="alert">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="organic-stone-btn">
                    <span class="btn-engraved-text">Enviar contraseña temporal</span>
                    <span class="btn-engraved-arrow">→</span>
                </button>
            </form>
            <p class="card-hero-subtitle" style="margin-top: .75rem; font-size: 12px;">Si no te llega, Recursos Humanos también puede restablecer tu acceso desde el panel.</p>
        @endif

        {{-- Enlace Volver --}}
        <div class="card-security-note" style="margin-top: 1.5rem;">
            <a href="{{ route('login') }}" class="glass-forgot-link" style="font-size: 13px; display: flex; align-items: center; gap: 0.4rem;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 14px; height: 14px;">
                    <path d="M19 12H5"></path>
                    <path d="m12 19-7-7 7-7"></path>
                </svg>
                Volver al inicio de sesión
            </a>
        </div>

    </div>
</div>
@endsection
