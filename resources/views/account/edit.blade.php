@extends('layouts.app')

@section('title', 'Mi cuenta')

@section('content')
{{-- Encabezado Ejecutivo de Mi Cuenta --}}
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 fw-bold" style="font-size: 0.725rem;">
                <i class="bi bi-person-badge me-1"></i>Perfil de Usuario
            </span>
            <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2.5 py-1 fw-semibold" style="font-size: 0.725rem;">
                <i class="bi bi-shield-check me-1"></i>Cuenta activa
            </span>
        </div>
        <h1 class="h3 fw-bold mb-0 text-body">Mi cuenta</h1>
        <p class="text-secondary small mb-0 mt-1">Administra tu información personal, seguridad de acceso y credenciales del panel.</p>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="badge bg-body-secondary text-body border px-3 py-2 d-inline-flex align-items-center gap-1.5" style="border-radius: 8px; font-size: 0.8rem; font-weight: 600;">
            <i class="bi bi-buildings text-primary"></i>
            <span>{{ $user->tenant?->company_name ?? 'Plataforma Global' }}</span>
        </span>
        <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1.5 shadow-sm" data-bs-toggle="modal" data-bs-target="#editProfileModal" style="border-radius: 8px; font-weight: 600; padding: 7px 14px;">
            <i class="bi bi-pencil-square"></i>
            <span>Editar datos de contacto</span>
        </button>
    </div>
</div>

<div class="row g-4">
    {{-- Columna Izquierda: Perfil y Sesiones (col-lg-5) --}}
    <div class="col-lg-5">
        {{-- Tarjeta de Identidad y Credencial --}}
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px; overflow: hidden; border: 1px solid var(--border-color) !important;">
            {{-- Banner decorativo superior --}}
            <div style="height: 80px; background: linear-gradient(135deg, rgba(2, 132, 199, 0.25) 0%, rgba(99, 102, 241, 0.35) 100%); border-bottom: 1px solid var(--border-color);"></div>

            <div class="card-body p-4 pt-0 position-relative">
                {{-- Avatar y Foto --}}
                <div class="d-flex justify-content-between align-items-end mb-3" style="margin-top: -42px;">
                    <div class="position-relative">
                        @if(!empty($user->avatar_path))
                            <img src="{{ asset('storage/' . $user->avatar_path) }}" alt="{{ $user->first_name }}" 
                                 class="rounded-circle shadow" 
                                 style="width: 82px; height: 82px; object-fit: cover; border: 4px solid var(--bs-card-bg); background: var(--bs-card-bg);">
                        @else
                            <div class="rounded-circle shadow d-inline-flex align-items-center justify-content-center text-white fw-bold"
                                 style="width: 82px; height: 82px; font-size: 1.85rem; background: linear-gradient(135deg, #0284c7 0%, #4f46e5 100%); border: 4px solid var(--bs-card-bg);">
                                {{ strtoupper(mb_substr($user->first_name ?? 'U', 0, 1) . mb_substr($user->last_name ?? '', 0, 1)) }}
                            </div>
                        @endif
                        <button type="button" class="btn btn-sm btn-light border position-absolute bottom-0 end-0 rounded-circle shadow-sm d-inline-flex align-items-center justify-content-center p-0" 
                                style="width: 28px; height: 28px;" 
                                data-bs-toggle="modal" data-bs-target="#editProfileModal" title="Cambiar foto de perfil">
                            <i class="bi bi-camera-fill text-secondary" style="font-size: 0.75rem;"></i>
                        </button>
                    </div>

                    <div>
                        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1.5 fw-bold" style="font-size: 0.75rem;">
                            <i class="bi bi-award-fill me-1"></i>{{ label('user_role', $user->role) }}
                        </span>
                    </div>
                </div>

                {{-- Nombres y Rol --}}
                <div class="mb-4">
                    <h2 class="h5 fw-bold text-body mb-0.5">{{ $user->first_name }} {{ $user->last_name }}</h2>
                    <div class="text-secondary small d-flex align-items-center gap-1.5">
                        <i class="bi bi-briefcase text-muted"></i>
                        <span>{{ $user->position ?: ($user->isSuperAdmin() ? 'Administrador Maestro del Sistema' : 'Colaborador') }}</span>
                    </div>
                </div>

                {{-- Grilla de Atributos del Perfil --}}
                <div class="d-flex flex-column gap-2.5">
                    {{-- Correo Electrónico --}}
                    <div class="p-2.5 rounded-3 border d-flex align-items-center justify-content-between" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                        <div class="d-flex align-items-center gap-2.5 min-w-0">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-2 bg-primary-subtle text-primary" style="width: 32px; height: 32px; flex-shrink: 0;">
                                <i class="bi bi-envelope-at-fill" style="font-size: 0.85rem;"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="text-secondary" style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Correo de acceso</div>
                                <div class="fw-semibold text-body text-truncate" style="font-size: 0.825rem;">{{ $user->email }}</div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-link text-secondary p-1 ms-2" data-copy="{{ $user->email }}" onclick="navigator.clipboard.writeText(this.dataset.copy); this.innerHTML='<i class=\'bi bi-check2 text-success\'></i>'; setTimeout(() => this.innerHTML='<i class=\'bi bi-clipboard\'></i>', 1800);" title="Copiar correo">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>

                    {{-- Empresa / Tenant --}}
                    <div class="p-2.5 rounded-3 border d-flex align-items-center justify-content-between" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                        <div class="d-flex align-items-center gap-2.5 min-w-0">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-2 bg-info-subtle text-info" style="width: 32px; height: 32px; flex-shrink: 0;">
                                <i class="bi bi-building" style="font-size: 0.85rem;"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="text-secondary" style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Empresa asignada</div>
                                <div class="fw-semibold text-body text-truncate" style="font-size: 0.825rem;">{{ $user->tenant?->company_name ?? 'Plataforma Global (Super Admin)' }}</div>
                            </div>
                        </div>
                    </div>

                    {{-- Código de Colaborador y Documento --}}
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="p-2.5 rounded-3 border h-100" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                                <div class="text-secondary" style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Código empleado</div>
                                <div class="fw-semibold text-body mt-0.5" style="font-size: 0.825rem;">
                                    <span class="font-monospace text-primary">{{ $user->employee_code ?: 'EMP-' . str_pad($user->id, 3, '0', STR_PAD_LEFT) }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2.5 rounded-3 border h-100" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                                <div class="text-secondary" style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Documento</div>
                                <div class="fw-semibold text-body mt-0.5" style="font-size: 0.825rem;">
                                    {{ $user->document_number ? ($user->document_type ? strtoupper($user->document_type) . ': ' : '') . $user->document_number : '—' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Sede Principal y Teléfono --}}
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="p-2.5 rounded-3 border h-100" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                                <div class="text-secondary" style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Sede principal</div>
                                <div class="fw-semibold text-body mt-0.5 text-truncate" style="font-size: 0.825rem;">
                                    <i class="bi bi-geo-alt text-warning me-1"></i>{{ $primaryBranch?->name ?? 'Acceso Global' }}
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2.5 rounded-3 border h-100" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                                <div class="text-secondary" style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Teléfono móvil</div>
                                <div class="fw-semibold text-body mt-0.5 text-truncate" style="font-size: 0.825rem;">
                                    <i class="bi bi-telephone text-success me-1"></i>{{ $user->phone ?: 'Sin registrar' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Último Acceso --}}
                    <div class="p-2.5 rounded-3 border d-flex align-items-center justify-content-between" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                        <div class="d-flex align-items-center gap-2.5 min-w-0">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-2 bg-success-subtle text-success" style="width: 32px; height: 32px; flex-shrink: 0;">
                                <i class="bi bi-clock-history" style="font-size: 0.85rem;"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="text-secondary" style="font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Último acceso al panel</div>
                                <div class="fw-semibold text-body" style="font-size: 0.825rem;">
                                    {{ $user->last_login_at?->format('d/m/Y h:i a') ?? 'Sesión activa actual' }}
                                    @if($user->last_login_at)
                                        <span class="text-secondary fw-normal ms-1">({{ $user->last_login_at->diffForHumans() }})</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tarjeta de Sesiones Móviles y Dispositivos --}}
        <div class="card border-0 shadow-sm" style="border-radius: 14px; border: 1px solid var(--border-color) !important;">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-2 bg-primary-subtle text-primary" style="width: 32px; height: 32px;">
                            <i class="bi bi-phone-fill" style="font-size: 0.95rem;"></i>
                        </div>
                        <h3 class="h6 fw-bold mb-0 text-body">Sesiones en la App Móvil</h3>
                    </div>
                    <span class="badge rounded-pill {{ $activeTokensCount > 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary' }} px-2.5 py-1 fw-bold" style="font-size: 0.75rem;">
                        {{ $activeTokensCount }} {{ $activeTokensCount === 1 ? 'móvil activo' : 'móviles activos' }}
                    </span>
                </div>
                <p class="small text-secondary mb-3" style="line-height: 1.45;">
                    Dispositivos donde mantienes la sesión iniciada para marcaciones biométricas, notificaciones push de asistencia y boletas de pago.
                </p>

                <form method="POST" action="{{ route('account.revoke-tokens') }}" data-confirm="Se cerrará la sesión en todos los celulares vinculados a tu cuenta." data-confirm-title="¿Cerrar sesiones móviles?" data-confirm-button="Cerrar sesiones" data-confirm-danger="1">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger w-100 d-inline-flex align-items-center justify-content-center gap-2 py-2" style="border-radius: 8px; font-weight: 600;" @disabled($activeTokensCount === 0)>
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Cerrar sesión en todos los móviles</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Columna Derecha: Seguridad y Cambio de Contraseña (col-lg-7) --}}
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm" style="border-radius: 14px; border: 1px solid var(--border-color) !important;">
            <div class="card-header bg-transparent p-4 pb-3 border-bottom" style="border-color: var(--border-color) !important;">
                <div class="d-flex align-items-center gap-2.5">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary shadow-sm" style="width: 38px; height: 38px;">
                        <i class="bi bi-shield-lock-fill" style="font-size: 1.15rem;"></i>
                    </div>
                    <div>
                        <h2 class="h5 fw-bold mb-0 text-body">Seguridad y Cambio de Contraseña</h2>
                        <div class="text-secondary small mt-0.5">Mantén tu cuenta protegida renovando tus credenciales periódicamente.</div>
                    </div>
                </div>
            </div>

            <div class="card-body p-4">
                {{-- Alerta Informativa de Política de Seguridad --}}
                <div class="p-3 rounded-3 mb-4 d-flex align-items-start gap-3 border" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-2 bg-warning-subtle text-warning-emphasis flex-shrink-0" style="width: 32px; height: 32px; margin-top: 2px;">
                        <i class="bi bi-shield-exclamation" style="font-size: 1rem;"></i>
                    </div>
                    <div class="small text-secondary" style="line-height: 1.45;">
                        <strong class="text-body fw-bold d-block mb-1">Requisitos de la contraseña:</strong>
                        Mínimo 8 caracteres, combinando mayúsculas, minúsculas y números. Al actualizar tu contraseña, por protocolo de seguridad se cerrará la sesión en la app de tus celulares para forzar la reautenticación.
                    </div>
                </div>

                {{-- Formulario de Cambio de Contraseña --}}
                <form method="POST" action="{{ route('account.password') }}" novalidate id="passwordChangeForm">
                    @csrf
                    @method('PUT')

                    {{-- Contraseña Actual --}}
                    <div class="mb-3.5">
                        <label class="form-label fw-semibold text-body small d-flex align-items-center justify-content-between mb-1.5" for="current_password">
                            <span><i class="bi bi-key text-muted me-1"></i>Contraseña actual</span>
                            <span class="text-muted fw-normal" style="font-size: 0.75rem;">Requerida para confirmar identidad</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-surface text-secondary border-end-0" style="border-color: var(--border-color);">
                                <i class="bi bi-lock"></i>
                            </span>
                            <input type="password" 
                                   id="current_password" 
                                   name="current_password" 
                                   class="form-control border-start-0 border-end-0 @error('current_password') is-invalid @enderror" 
                                   placeholder="Ingresa tu contraseña actual"
                                   required 
                                   autocomplete="current-password"
                                   style="border-color: var(--border-color);">
                            <button type="button" 
                                    class="input-group-text bg-surface text-secondary border-start-0" 
                                    onclick="togglePasswordVisibility('current_password', this)"
                                    title="Mostrar u ocultar contraseña"
                                    aria-label="Mostrar u ocultar contraseña"
                                    style="border-color: var(--border-color); cursor: pointer;">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        @error('current_password')
                            <div class="text-danger small mt-1 fw-semibold d-flex align-items-center gap-1">
                                <i class="bi bi-exclamation-circle-fill"></i>{{ $message }}
                            </div>
                        @enderror
                    </div>

                    <div class="row g-3 mb-3.5">
                        {{-- Nueva Contraseña --}}
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-body small mb-1.5" for="password">
                                <i class="bi bi-shield-plus text-primary me-1"></i>Nueva contraseña
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-surface text-secondary border-end-0" style="border-color: var(--border-color);">
                                    <i class="bi bi-key-fill"></i>
                                </span>
                                <input type="password" 
                                       id="password" 
                                       name="password" 
                                       class="form-control border-start-0 border-end-0 @error('password') is-invalid @enderror" 
                                       placeholder="Mínimo 8 caracteres"
                                       required 
                                       autocomplete="new-password"
                                       oninput="evaluatePasswordStrength(this.value)"
                                       style="border-color: var(--border-color);">
                                <button type="button" 
                                        class="input-group-text bg-surface text-secondary border-start-0" 
                                        onclick="togglePasswordVisibility('password', this)"
                                        title="Mostrar u ocultar contraseña"
                                        aria-label="Mostrar u ocultar contraseña"
                                        style="border-color: var(--border-color); cursor: pointer;">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            @error('password')
                                <div class="text-danger small mt-1 fw-semibold d-flex align-items-center gap-1">
                                    <i class="bi bi-exclamation-circle-fill"></i>{{ $message }}
                                </div>
                            @enderror
                        </div>

                        {{-- Repetir Nueva Contraseña --}}
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-body small mb-1.5" for="password_confirmation">
                                <i class="bi bi-check2-circle text-success me-1"></i>Confirma la nueva contraseña
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-surface text-secondary border-end-0" style="border-color: var(--border-color);">
                                    <i class="bi bi-shield-check"></i>
                                </span>
                                <input type="password" 
                                       id="password_confirmation" 
                                       name="password_confirmation" 
                                       class="form-control border-start-0 border-end-0" 
                                       placeholder="Repite la contraseña"
                                       required 
                                       autocomplete="new-password"
                                       oninput="checkPasswordMatch()"
                                       style="border-color: var(--border-color);">
                                <button type="button" 
                                        class="input-group-text bg-surface text-secondary border-start-0" 
                                        onclick="togglePasswordVisibility('password_confirmation', this)"
                                        title="Mostrar u ocultar contraseña"
                                        aria-label="Mostrar u ocultar contraseña"
                                        style="border-color: var(--border-color); cursor: pointer;">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div id="passwordMatchNote" class="small mt-1" style="display: none;"></div>
                        </div>
                    </div>

                    {{-- Medidor Visual de Fortaleza de Contraseña --}}
                    <div class="p-3 rounded-3 border mb-4" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                        <div class="d-flex align-items-center justify-content-between mb-1.5">
                            <span class="small fw-semibold text-body">Seguridad de la contraseña</span>
                            <span class="small fw-bold" id="passwordStrengthLabel" style="color: var(--text-muted);">Sin evaluar</span>
                        </div>
                        <div class="progress mb-2.5" style="height: 6px; background: rgba(0,0,0,0.06); border-radius: 4px;">
                            <div class="progress-bar transition-all" id="passwordStrengthBar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <div class="row g-2" style="font-size: 0.75rem;">
                            <div class="col-sm-4 d-flex align-items-center gap-1.5 text-secondary" id="ruleLength">
                                <i class="bi bi-circle text-muted" id="ruleLengthIcon"></i>
                                <span>8+ caracteres</span>
                            </div>
                            <div class="col-sm-4 d-flex align-items-center gap-1.5 text-secondary" id="ruleCases">
                                <i class="bi bi-circle text-muted" id="ruleCasesIcon"></i>
                                <span>Mayús. y minús.</span>
                            </div>
                            <div class="col-sm-4 d-flex align-items-center gap-1.5 text-secondary" id="ruleNumbers">
                                <i class="bi bi-circle text-muted" id="ruleNumbersIcon"></i>
                                <span>Al menos 1 número</span>
                            </div>
                        </div>
                    </div>

                    {{-- Botón de Acción --}}
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top" style="border-color: var(--border-color) !important;">
                        <span class="text-secondary small">
                            <i class="bi bi-info-circle me-1"></i>Acción irreversible y auditada en la bitácora
                        </span>
                        <button type="submit" class="btn btn-primary px-4 py-2 d-inline-flex align-items-center gap-2 shadow-sm" style="border-radius: 8px; font-weight: 600;">
                            <i class="bi bi-shield-lock"></i>
                            <span>Actualizar contraseña</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Modal para Editar Datos de Contacto y Foto de Perfil --}}
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
            <div class="modal-header border-bottom p-3.5" style="border-color: var(--border-color) !important;">
                <div class="d-flex align-items-center gap-2.5">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-2 bg-primary-subtle text-primary" style="width: 32px; height: 32px;">
                        <i class="bi bi-person-gear"></i>
                    </div>
                    <h5 class="modal-title h6 fw-bold mb-0 text-body" id="editProfileModalLabel">Editar datos de contacto</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <form method="POST" action="{{ route('account.profile') }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                <div class="modal-body p-4">
                    {{-- Foto de Perfil --}}
                    <div class="mb-4 text-center">
                        <div class="position-relative d-inline-block mb-2">
                            @if(!empty($user->avatar_path))
                                <img src="{{ asset('storage/' . $user->avatar_path) }}" id="avatarPreviewImg" alt="{{ $user->first_name }}" 
                                     class="rounded-circle shadow-sm border" 
                                     style="width: 88px; height: 88px; object-fit: cover;">
                            @else
                                <div id="avatarPreviewInitials" class="rounded-circle shadow-sm d-inline-flex align-items-center justify-content-center text-white fw-bold"
                                     style="width: 88px; height: 88px; font-size: 2rem; background: linear-gradient(135deg, #0284c7 0%, #4f46e5 100%);">
                                    {{ strtoupper(mb_substr($user->first_name ?? 'U', 0, 1) . mb_substr($user->last_name ?? '', 0, 1)) }}
                                </div>
                                <img src="" id="avatarPreviewImg" alt="Vista previa" class="rounded-circle shadow-sm border d-none" style="width: 88px; height: 88px; object-fit: cover;">
                            @endif
                        </div>
                        <div>
                            <label for="avatarFileInput" class="btn btn-sm btn-outline-primary px-3 py-1.5" style="border-radius: 8px; font-weight: 600; cursor: pointer;">
                                <i class="bi bi-upload me-1"></i>Subir nueva foto
                            </label>
                            <input type="file" id="avatarFileInput" name="avatar" class="d-none" accept="image/png,image/jpeg,image/webp" onchange="previewAvatarImage(this)">
                            <div class="text-secondary small mt-1" style="font-size: 0.75rem;">JPG, PNG o WEBP. Máximo 3 MB.</div>
                        </div>
                    </div>

                    {{-- Teléfono --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-body small" for="phoneInput">Teléfono móvil</label>
                        <div class="input-group">
                            <span class="input-group-text bg-surface text-secondary" style="border-color: var(--border-color);">
                                <i class="bi bi-telephone"></i>
                            </span>
                            <input type="text" id="phoneInput" name="phone" class="form-control" value="{{ old('phone', $user->phone) }}" placeholder="+51 987 654 321" style="border-color: var(--border-color);">
                        </div>
                        <div class="text-secondary small mt-1" style="font-size: 0.75rem;">Usado para alertas operativas y comunicación interna.</div>
                    </div>

                    {{-- Correo (Informativo, no modificable) --}}
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-body small">Correo institucional (No modificable)</label>
                        <input type="text" class="form-control bg-body-secondary text-secondary" value="{{ $user->email }}" disabled readonly>
                        <div class="text-muted small mt-1" style="font-size: 0.725rem;">
                            <i class="bi bi-lock-fill me-1"></i>El correo de acceso es administrado por el equipo de TI y RRHH.
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top p-3" style="border-color: var(--border-color) !important;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" style="border-radius: 8px;">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-primary px-3" style="border-radius: 8px; font-weight: 600;">
                        <i class="bi bi-check-lg me-1"></i>Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(fieldId, btn) {
    const input = document.getElementById(fieldId);
    const icon = btn.querySelector('i');
    if (!input || !icon) return;

    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye-badge', 'bi-eye');
    }
}

function evaluatePasswordStrength(val) {
    const bar = document.getElementById('passwordStrengthBar');
    const label = document.getElementById('passwordStrengthLabel');

    const ruleLen = val.length >= 8;
    const ruleCase = /[A-Z]/.test(val) && /[a-z]/.test(val);
    const ruleNum = /[0-9]/.test(val);

    updateRuleState('ruleLength', 'ruleLengthIcon', ruleLen);
    updateRuleState('ruleCases', 'ruleCasesIcon', ruleCase);
    updateRuleState('ruleNumbers', 'ruleNumbersIcon', ruleNum);

    let score = 0;
    if (ruleLen) score += 40;
    if (ruleCase) score += 30;
    if (ruleNum) score += 30;

    if (val.length === 0) {
        bar.style.width = '0%';
        bar.className = 'progress-bar';
        label.textContent = 'Sin evaluar';
        label.style.color = 'var(--text-muted)';
        return;
    }

    bar.style.width = score + '%';

    if (score < 40) {
        bar.className = 'progress-bar bg-danger';
        label.textContent = 'Débil';
        label.style.color = 'var(--accent-rose)';
    } else if (score < 100) {
        bar.className = 'progress-bar bg-warning';
        label.textContent = 'Media';
        label.style.color = 'var(--accent-amber)';
    } else {
        bar.className = 'progress-bar bg-success';
        label.textContent = 'Segura y robusta';
        label.style.color = 'var(--accent-emerald)';
    }

    checkPasswordMatch();
}

function updateRuleState(ruleId, iconId, isValid) {
    const rule = document.getElementById(ruleId);
    const icon = document.getElementById(iconId);
    if (!rule || !icon) return;

    if (isValid) {
        icon.className = 'bi bi-check-circle-fill text-success';
        rule.classList.remove('text-secondary');
        rule.classList.add('text-success', 'fw-semibold');
    } else {
        icon.className = 'bi bi-circle text-muted';
        rule.classList.remove('text-success', 'fw-semibold');
        rule.classList.add('text-secondary');
    }
}

function checkPasswordMatch() {
    const p1 = document.getElementById('password')?.value || '';
    const p2 = document.getElementById('password_confirmation')?.value || '';
    const note = document.getElementById('passwordMatchNote');
    if (!note) return;

    if (p2.length === 0) {
        note.style.display = 'none';
        return;
    }

    note.style.display = 'block';
    if (p1 === p2) {
        note.innerHTML = '<span class="text-success fw-semibold"><i class="bi bi-check2 me-1"></i>Las contraseñas coinciden</span>';
    } else {
        note.innerHTML = '<span class="text-danger fw-semibold"><i class="bi bi-x-circle me-1"></i>Las contraseñas no coinciden</span>';
    }
}

function previewAvatarImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewImg = document.getElementById('avatarPreviewImg');
            const initials = document.getElementById('avatarPreviewInitials');
            if (previewImg) {
                previewImg.src = e.target.result;
                previewImg.classList.remove('d-none');
            }
            if (initials) {
                initials.classList.add('d-none');
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
@endsection
