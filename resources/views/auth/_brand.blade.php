{{-- Logo y nombre de la empresa (marca blanca) o de la plataforma ($brandSettings lo arma layouts.guest) --}}
    @php
        $authBrand = $brandSettings ?? \App\Modules\Core\Models\TenantSetting::forTenant(isset($tenant) && $tenant ? $tenant : null);
        $authLogo = $authBrand->logoUrl();
        $authLogoDark = $authBrand->logoDarkUrl();
    @endphp
    <div class="text-center mb-4 auth-brand">
        @if($authLogo)
            <img src="{{ $authLogo }}" alt="{{ $authBrand->tradeName() }}" class="mb-2 brand-logo-light {{ $authLogoDark !== $authLogo ? 'has-dark' : '' }}" style="max-height: 56px; max-width: 220px;">
            @if($authLogoDark && $authLogoDark !== $authLogo)
                <img src="{{ $authLogoDark }}" alt="{{ $authBrand->tradeName() }}" class="mb-2 brand-logo-dark" style="max-height: 56px; max-width: 220px;">
            @endif
        @elseif(! (isset($tenant) && $tenant))
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 mb-2" style="width: 48px; height: 48px; background: var(--brand); color: var(--brand-text);">
                <i class="bi bi-shield-check fs-4"></i>
            </span>
        @endif
        <div class="h5 fw-bold mb-0">{{ $authBrand->tradeName() }}</div>
        <div class="small text-body-secondary">Asistencia, geocercas y planilla</div>
    </div>
