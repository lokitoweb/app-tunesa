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

        {{-- Política de la empresa: no hay recuperación por autoservicio; solo RRHH restablece el acceso --}}
        <h2 class="card-hero-title">¿Olvidaste tu contraseña?</h2>
        <p class="card-hero-subtitle">{{ \App\Modules\Core\Controllers\Api\AuthController::RESET_BY_ADMIN_MESSAGE }}</p>

        <p class="card-hero-subtitle" style="margin-top: .75rem;">Por seguridad, la contraseña solo se restablece desde el panel interno de Recursos Humanos o de la administración de tu empresa. Tu nueva clave temporal será tu número de documento y deberás cambiarla al ingresar.</p>

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
