@extends('layouts.guest')

@section('title', 'Iniciar Sesión')

@section('content')
<div class="auth-card">
    <div class="auth-brand">
        <div class="brand-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <circle cx="12" cy="11" r="3"/>
            </svg>
        </div>
        <div>
            <div class="brand-title">TuneSA SaaS</div>
            <div style="font-size: 0.75rem; color: #94a3b8; letter-spacing: 0.5px;">GESTIÓN & GEOCERCAS</div>
        </div>
    </div>

    @if ($errors->any())
        <div class="error-alert">
            <ul style="list-style: none; margin: 0; padding: 0;">
                @foreach ($errors->all() as $error)
                    <li>• {{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (session('info'))
        <div style="background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 12px; padding: 0.85rem 1rem; color: #93c5fd; font-size: 0.875rem; margin-bottom: 1.25rem;">
            {{ session('info') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.post') }}">
        @csrf

        @if(isset($tenants) && $tenants->count() > 0)
            <div class="form-group">
                <label class="form-label" for="tenant_id">Empresa (Tenant)</label>
                <select name="tenant_id" id="tenant_id" class="form-control">
                    <option value="">-- Detección Automática por Cuenta --</option>
                    @foreach($tenants as $tenant)
                        <option value="{{ $tenant->id }}" {{ old('tenant_id') == $tenant->id ? 'selected' : '' }}>
                            {{ $tenant->name }} ({{ $tenant->domain }})
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="form-group">
            <label class="form-label" for="email">Correo Electrónico</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="admin@empresa.com" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>

        <div class="form-group">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <label class="form-label" for="password" style="margin-bottom: 0;">Contraseña</label>
            </div>
            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
        </div>

        <div class="form-group" style="display: flex; align-items: center; justify-content: space-between; margin-top: 1rem; margin-bottom: 1.5rem;">
            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #94a3b8; cursor: pointer;">
                <input type="checkbox" name="remember" value="1" style="accent-color: #6366f1; width: 16px; height: 16px;">
                Recordar mi sesión
            </label>
        </div>

        <button type="submit" class="btn-submit">
            Ingresar al Panel
        </button>
    </form>

    <div style="margin-top: 2rem; text-align: center; font-size: 0.8rem; color: #64748b;">
        Acceso exclusivo para Administradores, RRHH y Supervisores.<br>
        Los colaboradores marcan desde la App Móvil TuneSA.
    </div>
</div>
@endsection
