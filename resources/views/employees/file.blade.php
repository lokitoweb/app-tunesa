@extends('layouts.app')

@section('title', 'Legajo · ' . $employee->full_name)

@section('content')
@php
    use App\Modules\HR\Models\EmployeeDocument;
    use App\Modules\HR\Models\EmployeeProfile;
    use App\Modules\HR\Models\UniformDelivery;
    use App\Modules\HR\Models\UniformSize;

    $canView = $revealed ?? false;
    $show = fn (?string $v) => $canView ? $v : EmployeeProfile::mask($v);
    $payTone = ['incomplete' => 'secondary', 'pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger'];
    $payStatus = $profile->payment_info_status ?? 'incomplete';
    $approvedTypes = $documents->where('status', 'approved')->pluck('type')->all();
    $required = $config->requiredDocuments();
    $missing = array_diff($required, $approvedTypes);
    $sizes = $employee->uniformSize;
    $initials = mb_strtoupper(mb_substr($employee->first_name, 0, 1) . mb_substr($employee->last_name, 0, 1));
    $primaryBranch = $employee->branches->firstWhere('pivot.is_primary', true) ?? $employee->branches->first();
    $banksList = config('payroll.banks', []);
    $pensionFundsList = config('payroll.pension_funds', []);
@endphp

<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-3">
        <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Colaboradores</a></li>
        <li class="breadcrumb-item active" aria-current="page">Legajo · {{ $employee->full_name }}</li>
    </ol>
</nav>

{{-- Encabezado Ejecutivo del Colaborador --}}
<div class="legajo-page-header">
    <div class="d-flex align-items-center gap-3 min-w-0">
        @if(!empty($employee->avatar_path))
            <img src="{{ asset('storage/' . $employee->avatar_path) }}" alt="{{ $employee->first_name }}" class="legajo-avatar-img">
        @else
            <div class="legajo-avatar-initials">
                {{ $initials ?: 'U' }}
            </div>
        @endif
        <div class="min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="h3 fw-bold mb-0 text-body">{{ $employee->full_name }}</h1>
                <span class="badge {{ $employee->status === 'active' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary' }} px-2.5 py-1" style="font-size: 0.725rem; font-weight: 700;">
                    <i class="bi {{ $employee->status === 'active' ? 'bi-check-circle-fill' : 'bi-dash-circle' }}" style="margin-right: 5px;"></i>{{ $employee->status === 'active' ? 'Activo' : 'Inactivo' }}
                </span>
            </div>
            <div class="text-secondary small mt-1 d-flex align-items-center gap-2 flex-wrap" style="font-size: 0.8125rem;">
                <span class="fw-semibold text-body">{{ $employee->position ?: 'Sin cargo' }}</span>
                <span>·</span>
                <span>{{ $employee->employee_code }}</span>
                <span>·</span>
                <span>{{ label('id_document_type', $employee->document_type) }} {{ $employee->document_number }}</span>
                <span>·</span>
                <span><i class="bi bi-calendar-event" style="margin-right: 5px;"></i>Ingreso {{ $employee->hire_date?->format('d/m/Y') ?? '—' }}</span>
                @if($primaryBranch)
                    <span>·</span>
                    <span><i class="bi bi-geo-alt-fill text-warning" style="margin-right: 5px;"></i>{{ $primaryBranch->name }}</span>
                @endif
            </div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 mt-3 mt-md-0">
        @can('profiles.view_sensitive')
            @unless($revealed)
                <form method="POST" action="{{ route('employees.file.reveal', $employee->id) }}" id="revealProfileForm" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center shadow-sm" style="border-radius: 8px; font-weight: 600; padding: 7px 14px; gap: 8px;" title="Queda registrado en la bitácora de auditoría">
                        <i class="bi bi-eye"></i>
                        <span>Ver números completos</span>
                    </button>
                </form>
            @else
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2 d-inline-flex align-items-center" style="font-size: 0.775rem; border-radius: 8px;">
                    <i class="bi bi-unlock-fill" style="margin-right: 6px;"></i><span>Números visibles (auditado)</span>
                </span>
            @endunless
        @endcan
        @can('users.manage')
            <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center shadow-sm" onclick="openLegajoEditModal({{ $employee->id }})" style="border-radius: 8px; font-weight: 600; padding: 7px 15px; gap: 8px;">
                <i class="bi bi-pencil"></i>
                <span>Editar datos básicos</span>
            </button>
        @endcan
    </div>
</div>

{{-- 4 Métricas Clave (KPI Strip) --}}
<div class="legajo-stat-strip">
    <div class="legajo-stat-item">
        <div class="legajo-stat-label">Documentos requeridos</div>
        <div class="legajo-stat-value">
            <span>{{ count($required) - count($missing) }} / {{ count($required) }}</span>
        </div>
        <div class="legajo-stat-note {{ $missing ? 'text-warning-emphasis' : 'text-success' }}" title="{{ $missing ? 'Falta: ' . collect($missing)->map(fn ($t) => EmployeeDocument::TYPES[$t] ?? $t)->implode(', ') : 'Completo' }}">
            {{ $missing ? 'Falta: ' . count($missing) . ' obligatorio(s)' : '100% completo' }}
        </div>
    </div>
    <div class="legajo-stat-item">
        <div class="legajo-stat-label">Datos de pago</div>
        @php
            $payBadgeClass = match($payStatus) {
                'verified' => 'bg-success-subtle text-success border border-success-subtle',
                'pending'  => 'bg-warning-subtle text-warning border border-warning-subtle',
                'rejected' => 'bg-danger-subtle text-danger border border-danger-subtle',
                default    => 'bg-secondary-subtle text-secondary',
            };
        @endphp
        <div class="legajo-stat-value">
            <span class="badge {{ $payBadgeClass }}" style="font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.55rem;">
                {{ EmployeeProfile::PAYMENT_STATUSES[$payStatus] ?? ucfirst($payStatus) }}
            </span>
        </div>
        <div class="legajo-stat-note">
            {{ $profile->payment_info_updated_at ? 'Act. ' . $profile->payment_info_updated_at->diffForHumans() : 'Sin registrar' }}
        </div>
    </div>
    <div class="legajo-stat-item">
        <div class="legajo-stat-label">Tallas y dotación</div>
        <div class="legajo-stat-value">
            <span>{{ $sizes?->isComplete() ? 'Completas' : 'Pendientes' }}</span>
        </div>
        <div class="legajo-stat-note">
            {{ $deliveries->count() }} {{ $deliveries->count() === 1 ? 'entrega registrada' : 'entregas registradas' }}
        </div>
    </div>
    <div class="legajo-stat-item">
        <div class="legajo-stat-label">Acceso móvil / sistema</div>
        <div class="legajo-stat-value">
            <span>{{ $employee->last_login_at?->format('d/m/Y') ?? 'Nunca' }}</span>
        </div>
        <div class="legajo-stat-note {{ $employee->isLocked() ? 'text-danger' : '' }}">
            {{ $employee->isLocked() ? 'Bloqueado hasta ' . $employee->locked_until->format('h:i a') : ($employee->must_change_password ? 'Clave provisional' : 'Acceso normal') }}
        </div>
    </div>
</div>

{{-- Pestañas Segmentadas de Navegación --}}
<div class="legajo-tabs" role="tablist">
    <button class="legajo-tab-btn active" id="tab-btn-laboral" data-bs-toggle="tab" data-bs-target="#tab-content-laboral" type="button" role="tab" aria-selected="true">
        <i class="bi bi-person-vcard"></i>
        <span>Datos Laborales y Cuentas</span>
    </button>
    <button class="legajo-tab-btn" id="tab-btn-docs" data-bs-toggle="tab" data-bs-target="#tab-content-docs" type="button" role="tab" aria-selected="false">
        <i class="bi bi-file-earmark-text"></i>
        <span>Documentos del Expediente</span>
        <span class="legajo-tab-badge">{{ $documents->count() }}</span>
    </button>
    <button class="legajo-tab-btn" id="tab-btn-uniforms" data-bs-toggle="tab" data-bs-target="#tab-content-uniforms" type="button" role="tab" aria-selected="false">
        <i class="bi bi-shield-shaded"></i>
        <span>Dotación y Uniformes</span>
        <span class="legajo-tab-badge">{{ $deliveries->count() }}</span>
    </button>
</div>

{{-- Contenido de las Pestañas --}}
<div class="tab-content" id="legajoTabContent">

    {{-- TAB 1: FICHA LABORAL Y CUENTAS BANCARIAS --}}
    <div class="tab-pane fade show active" id="tab-content-laboral" role="tabpanel" aria-labelledby="tab-btn-laboral">

        {{-- Aviso de Revisión de Cuenta Bancaria (si está pendiente) --}}
        @if($profile->exists && $profile->payment_info_status === 'pending')
            <div class="alert alert-warning mb-4 p-3 rounded-3 border" style="background: rgba(245, 158, 11, 0.08); border-color: rgba(245, 158, 11, 0.3) !important;">
                <form method="POST" action="{{ route('employees.file.payment-review', $employee->id) }}">
                    @csrf
                    <div class="d-flex align-items-center gap-2 mb-2 text-warning-emphasis fw-bold">
                        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                        <span>Cuenta bancaria enviada por el colaborador — Requiere revisión de RRHH</span>
                    </div>
                    <div class="row g-2 align-items-center">
                        <div class="col-12 col-md-7">
                            <input type="text" id="pay_reason" name="reason" class="form-control form-control-sm" placeholder="Motivo si la observas (ej. no coincide con el titular)">
                        </div>
                        <div class="col-12 col-md-5 d-flex gap-2">
                            <button name="decision" value="verified" class="btn btn-sm btn-success d-inline-flex align-items-center gap-1.5 px-3">
                                <i class="bi bi-check-lg"></i>
                                <span>Verificar cuenta</span>
                            </button>
                            <button name="decision" value="rejected" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1.5 px-3">
                                <i class="bi bi-x-circle"></i>
                                <span>Observar</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        @endif

        {{-- Barra de Herramientas de la Ficha --}}
        <div class="d-flex align-items-center justify-content-between mb-3 px-1">
            <span class="small fw-semibold text-secondary d-flex align-items-center gap-2">
                <i class="bi bi-shield-check text-primary"></i>
                <span>Cuentas de abono y previsión validadas para planilla y pagos</span>
            </span>
            @can('profiles.manage')
                <button class="btn btn-sm btn-outline-primary d-inline-flex align-items-center shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#profileFormCollapse" aria-expanded="false" aria-controls="profileFormCollapse" style="border-radius: 8px; font-weight: 600; padding: 6px 14px; gap: 8px;">
                    <i class="bi bi-pencil-square"></i>
                    <span>Editar ficha laboral</span>
                </button>
            @endcan
        </div>

        {{-- 3 Secciones Simétricas: Cuentas, Previsión, Emergencias --}}
        <div class="legajo-grid mb-4">
            {{-- Columna 1: Cuentas Bancarias y Haberes --}}
            <div class="legajo-section">
                <div class="legajo-section-header">
                    <div class="legajo-section-title">
                        <i class="bi bi-bank"></i>
                        <span>Cuentas Bancarias</span>
                    </div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.65rem; font-weight: 700;">
                        {{ $profile->bank_account_currency ?? 'PEN' }}
                    </span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Banco de abono</span>
                    <span class="legajo-value" title="{{ $profile->bank_name ?? '—' }}">{{ $profile->bank_name ?? '—' }}</span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Tipo de cuenta</span>
                    <span class="legajo-value">{{ EmployeeProfile::BANK_ACCOUNT_TYPES[$profile->bank_account_type] ?? '—' }}</span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">N° de cuenta</span>
                    <span class="legajo-value mono">
                        <span>{{ $show($profile->bank_account_number) ?? '—' }}</span>
                        @if($canView && $profile->bank_account_number)
                            <button type="button" class="legajo-copy-btn" onclick="copyLegajoText('{{ $profile->bank_account_number }}', this)" title="Copiar número de cuenta">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        @endif
                    </span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Código CCI</span>
                    <span class="legajo-value mono">
                        <span>{{ $show($profile->interbank_code) ?? '—' }}</span>
                        @if($canView && $profile->interbank_code)
                            <button type="button" class="legajo-copy-btn" onclick="copyLegajoText('{{ $profile->interbank_code }}', this)" title="Copiar CCI">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        @endif
                    </span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Cuenta CTS</span>
                    <span class="legajo-value mono">
                        <span>{{ $profile->cts_bank_name ? $profile->cts_bank_name . ' · ' . ($show($profile->cts_account_number) ?: '—') : '—' }}</span>
                        @if($canView && $profile->cts_account_number)
                            <button type="button" class="legajo-copy-btn" onclick="copyLegajoText('{{ $profile->cts_account_number }}', this)" title="Copiar cuenta CTS">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        @endif
                    </span>
                </div>
            </div>

            {{-- Columna 2: Previsión y Régimen Laboral --}}
            <div class="legajo-section">
                <div class="legajo-section-header">
                    <div class="legajo-section-title">
                        <i class="bi bi-shield-check"></i>
                        <span>Previsión y Régimen</span>
                    </div>
                    @php
                        $isAfp = ($profile->pension_system ?? '') === 'afp';
                    @endphp
                    <span class="badge {{ $isAfp ? 'bg-info-subtle text-info border border-info-subtle' : 'bg-secondary-subtle text-secondary' }}" style="font-size: 0.65rem; font-weight: 700;">
                        {{ $profile->pension_system ? strtoupper($profile->pension_system) : 'SIN AFP' }}
                    </span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Régimen laboral</span>
                    <span class="legajo-value">{{ EmployeeProfile::LABOR_REGIMES[$profile->labor_regime] ?? '—' }}</span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Sistema previsional</span>
                    <span class="legajo-value">{{ EmployeeProfile::PENSION_SYSTEMS[$profile->pension_system] ?? '—' }}</span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Fondo / AFP</span>
                    <span class="legajo-value" title="{{ $profile->pension_fund ?? '—' }}">{{ $profile->pension_fund ?? '—' }}</span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Código CUSPP</span>
                    <span class="legajo-value mono">
                        <span>{{ $show($profile->pension_code) ?? '—' }}</span>
                        @if($profile->pension_code)
                            <button type="button" class="legajo-copy-btn" onclick="copyLegajoText('{{ $profile->pension_code }}', this)" title="Copiar CUSPP">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        @endif
                    </span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Seguro de salud</span>
                    <span class="legajo-value">{{ EmployeeProfile::HEALTH_INSURANCES[$profile->health_insurance] ?? '—' }}</span>
                </div>
            </div>

            {{-- Columna 3: Domicilio y Contactos de Emergencia --}}
            <div class="legajo-section">
                <div class="legajo-section-header">
                    <div class="legajo-section-title">
                        <i class="bi bi-geo-alt"></i>
                        <span>Domicilio y Contacto</span>
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.65rem; font-weight: 700;">
                        EMERGENCIA
                    </span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Dirección</span>
                    <span class="legajo-value" title="{{ $profile->address ?? '—' }}">{{ $profile->address ?? '—' }}</span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Distrito</span>
                    <span class="legajo-value">{{ $profile->district ?? '—' }}</span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Contacto emergencia</span>
                    <span class="legajo-value" title="{{ $profile->emergency_contact_name ?? '—' }}">{{ $profile->emergency_contact_name ?? '—' }}</span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Teléfono emergencia</span>
                    <span class="legajo-value mono">
                        <span>{{ $profile->emergency_contact_phone ?? '—' }}</span>
                        @if($profile->emergency_contact_phone)
                            <button type="button" class="legajo-copy-btn" onclick="copyLegajoText('{{ $profile->emergency_contact_phone }}', this)" title="Copiar teléfono">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        @endif
                    </span>
                </div>
                <div class="legajo-row">
                    <span class="legajo-label">Teléfono personal</span>
                    <span class="legajo-value mono">
                        <span>{{ $employee->phone ?? '—' }}</span>
                        @if($employee->phone)
                            <button type="button" class="legajo-copy-btn" onclick="copyLegajoText('{{ $employee->phone }}', this)" title="Copiar teléfono">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        @endif
                    </span>
                </div>
            </div>
        </div>

        {{-- Formulario Desplegable para Editar la Ficha Laboral --}}
        @can('profiles.manage')
            <div class="collapse mb-4" id="profileFormCollapse">
                <div class="card border-0 shadow-sm p-4 rounded-3" style="background: var(--bg-surface); border: 1px solid var(--border-color) !important;">
                    <div class="d-flex align-items-center justify-content-between pb-3 mb-3 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-pencil-square text-primary fs-5"></i>
                            <h2 class="h6 fw-bold mb-0 text-body">Modificar Ficha Laboral del Colaborador</h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-toggle="collapse" data-bs-target="#profileFormCollapse" aria-label="Cerrar"></button>
                    </div>

                    <form method="POST" action="{{ route('employees.file.profile', $employee->id) }}">
                        @csrf
                        @method('PUT')

                        {{-- SECCIÓN 1: Previsión y Régimen Laboral --}}
                        <div class="d-flex align-items-center gap-2 pb-2 mb-3 border-bottom">
                            <i class="bi bi-shield-check text-info fs-5"></i>
                            <span class="fw-bold small text-uppercase text-secondary" style="letter-spacing: 0.05em;">1. Previsión y Régimen Laboral</span>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-4">
                                <label for="labor_regime" class="form-label small fw-bold text-secondary mb-1">Régimen laboral</label>
                                <select id="labor_regime" name="labor_regime" class="form-select form-select-sm">
                                    <option value="">— Seleccionar régimen —</option>
                                    @foreach(EmployeeProfile::LABOR_REGIMES as $k => $v)
                                        <option value="{{ $k }}" @selected($profile->labor_regime === $k)>{{ $v }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="health_insurance" class="form-label small fw-bold text-secondary mb-1">Seguro de salud</label>
                                <select id="health_insurance" name="health_insurance" class="form-select form-select-sm">
                                    <option value="">— Seleccionar seguro —</option>
                                    @foreach(EmployeeProfile::HEALTH_INSURANCES as $k => $v)
                                        <option value="{{ $k }}" @selected($profile->health_insurance === $k)>{{ $v }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="pension_system" class="form-label small fw-bold text-secondary mb-1">Sistema previsional</label>
                                <select id="pension_system" name="pension_system" class="form-select form-select-sm">
                                    <option value="">— Seleccionar previsión —</option>
                                    @foreach(EmployeeProfile::PENSION_SYSTEMS as $k => $v)
                                        <option value="{{ $k }}" @selected($profile->pension_system === $k)>{{ $v }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="pension_fund" class="form-label small fw-bold text-secondary mb-1">Fondo / Administradora AFP</label>
                                <input id="pension_fund" name="pension_fund" class="form-control form-control-sm" list="pensionFundsDatalist" value="{{ $profile->pension_fund }}" placeholder="Ej. AFP Integra, Prima, Profuturo...">
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="pension_code" class="form-label small fw-bold text-secondary mb-1">Código CUSPP</label>
                                <input id="pension_code" name="pension_code" class="form-control form-control-sm font-monospace" value="{{ $profile->pension_code }}" placeholder="Ej. 123450INTEG1">
                            </div>
                        </div>

                        {{-- SECCIÓN 2: Cuentas Bancarias y CTS --}}
                        <div class="d-flex align-items-center gap-2 pb-2 mb-3 border-bottom">
                            <i class="bi bi-bank text-primary fs-5"></i>
                            <span class="fw-bold small text-uppercase text-secondary" style="letter-spacing: 0.05em;">2. Cuentas Bancarias y CTS</span>
                        </div>

                        <div class="row g-3 mb-4">
                            @unless($canView)
                                <div class="col-12">
                                    <div class="p-3 rounded-3 d-flex align-items-center justify-content-between flex-wrap gap-3" style="background: rgba(14, 165, 233, 0.08); border: 1px solid rgba(14, 165, 233, 0.28);">
                                        <div class="d-flex align-items-center gap-3 min-w-0">
                                            <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px; border-radius: 10px; background: rgba(14, 165, 233, 0.15); color: #0284c7; font-size: 1.25rem;">
                                                <i class="bi bi-shield-lock-fill"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="fw-bold text-body small mb-1">Cuentas bancarias protegidas en reposo</div>
                                                <div class="text-secondary small" style="font-size: 0.8125rem; line-height: 1.4;">
                                                    Los números bancarios se mantienen cifrados. Puedes ingresar nuevos valores directamente o cargar los dígitos completos para auditoría.
                                                </div>
                                            </div>
                                        </div>
                                        @can('profiles.view_sensitive')
                                            <button type="submit" form="revealProfileForm" class="btn btn-sm btn-primary d-inline-flex align-items-center shadow-sm" style="border-radius: 8px; font-weight: 600; padding: 8px 16px; gap: 8px; font-size: 0.8125rem;">
                                                <i class="bi bi-eye"></i>
                                                <span>Ver y cargar dígitos completos</span>
                                            </button>
                                        @endcan
                                    </div>
                                </div>
                            @else
                                <div class="col-12">
                                    <div class="p-3 rounded-3 d-flex align-items-center gap-3" style="background: rgba(16, 185, 129, 0.09); border: 1px solid rgba(16, 185, 129, 0.28);">
                                        <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px; border-radius: 10px; background: rgba(16, 185, 129, 0.16); color: #059669; font-size: 1.25rem;">
                                            <i class="bi bi-unlock-fill"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="badge bg-success text-white px-2 py-0.5" style="font-size: 0.675rem; font-weight: 700; letter-spacing: 0.04em;">MODO AUDITORÍA ACTIVO</span>
                                                <span class="text-success-emphasis fw-bold small">Cuentas desbloqueadas</span>
                                            </div>
                                            <div class="text-secondary small" style="font-size: 0.8125rem; line-height: 1.4;">
                                                Los números de cuenta bancaria, CCI y CTS se encuentran visibles y cargados en los campos para su modificación directa.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endunless

                            {{-- Fila Banco de Abono, Tipo y Moneda --}}
                            <div class="col-12 col-md-5">
                                <label for="bank_name" class="form-label small fw-bold text-secondary mb-1">Banco de abono</label>
                                <input id="bank_name" name="bank_name" class="form-control form-control-sm" list="banksDatalist" value="{{ $profile->bank_name }}" placeholder="Ej. BCP, BBVA, Interbank, Scotiabank...">
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="bank_account_type" class="form-label small fw-bold text-secondary mb-1">Tipo de cuenta</label>
                                <select id="bank_account_type" name="bank_account_type" class="form-select form-select-sm">
                                    <option value="">— Seleccionar tipo —</option>
                                    @foreach(EmployeeProfile::BANK_ACCOUNT_TYPES as $k => $v)
                                        <option value="{{ $k }}" @selected($profile->bank_account_type === $k)>{{ $v }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label for="bank_account_currency" class="form-label small fw-bold text-secondary mb-1">Moneda</label>
                                <select id="bank_account_currency" name="bank_account_currency" class="form-select form-select-sm">
                                    <option value="PEN" @selected(($profile->bank_account_currency ?? $employee->tenant?->currency ?? 'PEN') === 'PEN')>PEN (S/ · Soles)</option>
                                    <option value="USD" @selected(($profile->bank_account_currency ?? '') === 'USD')>USD ($ · Dólares)</option>
                                </select>
                            </div>

                            {{-- Fila Número de Cuenta y CCI --}}
                            <div class="col-12 col-md-6">
                                <label for="bank_account_number" class="form-label small fw-bold text-secondary mb-1">
                                    N° de cuenta bancaria
                                    @if($profile->bank_account_number)
                                        <span class="fw-normal text-muted">({{ $canView ? 'editable' : 'actual: ' . $show($profile->bank_account_number) }})</span>
                                    @endif
                                </label>
                                <input id="bank_account_number" name="bank_account_number" class="form-control form-control-sm font-monospace" inputmode="numeric" autocomplete="off" value="{{ $canView ? $profile->bank_account_number : '' }}" placeholder="{{ $profile->bank_account_number ? ($canView ? $profile->bank_account_number : $show($profile->bank_account_number)) : 'Número de cuenta' }}">
                                @unless($canView)
                                    @if($profile->bank_account_number)
                                        <div class="form-text text-secondary" style="font-size: 0.72rem;">
                                            <i class="bi bi-shield-lock me-1"></i>Dígitos protegidos. Deja vacío para conservar o escribe el nuevo número.
                                        </div>
                                    @endif
                                @endunless
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="interbank_code" class="form-label small fw-bold text-secondary mb-1">
                                    Código CCI (Interbancario)
                                    @if($profile->interbank_code)
                                        <span class="fw-normal text-muted">({{ $canView ? 'editable' : 'actual: ' . $show($profile->interbank_code) }})</span>
                                    @endif
                                </label>
                                <input id="interbank_code" name="interbank_code" class="form-control form-control-sm font-monospace" inputmode="numeric" autocomplete="off" value="{{ $canView ? $profile->interbank_code : '' }}" placeholder="{{ $profile->interbank_code ? ($canView ? $profile->interbank_code : $show($profile->interbank_code)) : 'Código interbancario de 20 dígitos' }}">
                                @unless($canView)
                                    @if($profile->interbank_code)
                                        <div class="form-text text-secondary" style="font-size: 0.72rem;">
                                            <i class="bi bi-shield-lock me-1"></i>Dígitos protegidos. Deja vacío para conservar o escribe el nuevo número.
                                        </div>
                                    @endif
                                @endunless
                            </div>

                            {{-- Fila CTS --}}
                            <div class="col-12 col-md-5">
                                <label for="cts_bank_name" class="form-label small fw-bold text-secondary mb-1">Banco de abono CTS</label>
                                <input id="cts_bank_name" name="cts_bank_name" class="form-control form-control-sm" list="banksDatalist" value="{{ $profile->cts_bank_name }}" placeholder="Ej. Interbank, BCP, BBVA...">
                            </div>
                            <div class="col-12 col-md-3">
                                <label for="cts_currency" class="form-label small fw-bold text-secondary mb-1">Moneda CTS</label>
                                <select id="cts_currency" name="cts_currency" class="form-select form-select-sm">
                                    <option value="PEN" @selected(($profile->cts_currency ?? 'PEN') === 'PEN')>PEN (S/ · Soles)</option>
                                    <option value="USD" @selected(($profile->cts_currency ?? '') === 'USD')>USD ($ · Dólares)</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="cts_account_number" class="form-label small fw-bold text-secondary mb-1">
                                    Cuenta CTS
                                    @if($profile->cts_account_number)
                                        <span class="fw-normal text-muted">({{ $canView ? 'editable' : 'actual: ' . $show($profile->cts_account_number) }})</span>
                                    @endif
                                </label>
                                <input id="cts_account_number" name="cts_account_number" class="form-control form-control-sm font-monospace" inputmode="numeric" autocomplete="off" value="{{ $canView ? $profile->cts_account_number : '' }}" placeholder="{{ $profile->cts_account_number ? ($canView ? $profile->cts_account_number : $show($profile->cts_account_number)) : 'Número de cuenta CTS' }}">
                                @unless($canView)
                                    @if($profile->cts_account_number)
                                        <div class="form-text text-secondary" style="font-size: 0.72rem;">
                                            <i class="bi bi-shield-lock me-1"></i>Dígitos protegidos. Deja vacío para conservar o escribe el nuevo número.
                                        </div>
                                    @endif
                                @endunless
                            </div>
                        </div>

                        {{-- SECCIÓN 3: Domicilio y Contactos de Emergencia --}}
                        <div class="d-flex align-items-center gap-2 pb-2 mb-3 border-bottom">
                            <i class="bi bi-geo-alt text-success fs-5"></i>
                            <span class="fw-bold small text-uppercase text-secondary" style="letter-spacing: 0.05em;">3. Domicilio y Contacto de Emergencia</span>
                        </div>

                        <div class="row g-3">
                            <div class="col-12 col-md-7">
                                <label for="address" class="form-label small fw-bold text-secondary mb-1">Dirección domiciliaria</label>
                                <input id="address" name="address" class="form-control form-control-sm" value="{{ $profile->address }}" placeholder="Av. Principal 123...">
                            </div>
                            <div class="col-12 col-md-5">
                                <label for="district" class="form-label small fw-bold text-secondary mb-1">Distrito / Localidad</label>
                                <input id="district" name="district" class="form-control form-control-sm" value="{{ $profile->district }}" placeholder="Distrito de residencia">
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="emergency_contact_name" class="form-label small fw-bold text-secondary mb-1">Contacto de emergencia</label>
                                <input id="emergency_contact_name" name="emergency_contact_name" class="form-control form-control-sm" value="{{ $profile->emergency_contact_name }}" placeholder="Nombres completos y parentesco">
                            </div>
                            <div class="col-12 col-md-6">
                                <label for="emergency_contact_phone" class="form-label small fw-bold text-secondary mb-1">Teléfono de emergencia</label>
                                <input id="emergency_contact_phone" name="emergency_contact_phone" class="form-control form-control-sm font-monospace" value="{{ $profile->emergency_contact_phone }}" placeholder="Teléfono o celular de contacto">
                            </div>
                        </div>

                        <datalist id="banksDatalist">@foreach($banksList as $b)<option value="{{ $b }}">@endforeach</datalist>
                        <datalist id="pensionFundsDatalist">@foreach($pensionFundsList as $f)<option value="{{ $f }}">@endforeach</datalist>

                        <div class="d-flex align-items-center justify-content-end gap-2 mt-4 pt-2 border-top">
                            <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-toggle="collapse" data-bs-target="#profileFormCollapse" style="border-radius: 8px;">Cancelar</button>
                            <button type="submit" class="btn btn-sm btn-primary px-4 d-inline-flex align-items-center gap-2" style="border-radius: 8px; font-weight: 600;">
                                <i class="bi bi-check2-circle"></i>
                                <span>Guardar ficha laboral</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan

    </div>

    {{-- TAB 2: DOCUMENTOS DEL LEGAJO --}}
    <div class="tab-pane fade" id="tab-content-docs" role="tabpanel" aria-labelledby="tab-btn-docs">

        <div class="legajo-card-table mb-4">
            <div class="p-3 border-bottom d-flex align-items-center justify-content-between" style="background: var(--bg-surface-elevated);">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-lock text-primary fs-5"></i>
                    <h2 class="h6 fw-bold mb-0 text-body">Expediente y Legajo Digital</h2>
                </div>
                <span class="small text-secondary">{{ $documents->count() }} documento(s) adjunto(s)</span>
            </div>

            <div class="table-responsive mb-0">
                <table class="legajo-table">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Fecha de carga</th>
                            <th>Vencimiento</th>
                            <th>Estado</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($documents as $doc)
                            @php
                                $isPdf = str_ends_with(strtolower($doc->original_name ?? ''), '.pdf');
                            @endphp
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2.5">
                                        <div class="fs-4 flex-shrink-0">
                                            <i class="bi {{ $isPdf ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-text text-primary' }}"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="fw-bold text-body" style="font-size: 0.85rem;">
                                                {{ EmployeeDocument::TYPES[$doc->type] ?? ucfirst($doc->type) }}
                                            </div>
                                            <div class="text-secondary small text-truncate" style="max-width: 280px; font-size: 0.75rem;">
                                                {{ $doc->original_name }}
                                            </div>
                                            @if($doc->rejection_reason)
                                                <div class="small text-danger mt-0.5">
                                                    <i class="bi bi-exclamation-circle" style="margin-right: 5px;"></i>{{ $doc->rejection_reason }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="text-secondary">
                                    <i class="bi bi-calendar3" style="margin-right: 6px;"></i>{{ $doc->created_at->format('d/m/Y') }}
                                </td>
                                <td>
                                    @if($doc->expires_at)
                                        <span class="{{ $doc->isExpired() ? 'text-danger fw-bold' : 'text-secondary' }}">
                                            <i class="bi bi-clock-history" style="margin-right: 6px;"></i>{{ $doc->expires_at->format('d/m/Y') }}
                                        </span>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                <td>
                                    <x-status-badge type="document_status" :value="$doc->status" />
                                </td>
                                <td class="text-end pe-4 text-nowrap">
                                    <a href="{{ route('documents.open', $doc->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5 shadow-sm px-2.5 py-1" style="border-radius: 6px; font-weight: 600; font-size: 0.775rem;" title="Ver archivo">
                                        <i class="bi bi-eye"></i>
                                        <span>Ver</span>
                                    </a>
                                    @if($doc->status === 'pending')
                                        <form method="POST" action="{{ route('documents.review', $doc->id) }}" class="d-inline ms-1">
                                            @csrf
                                            <button name="decision" value="approved" class="btn btn-sm btn-success d-inline-flex align-items-center gap-1.5 shadow-sm px-2.5 py-1" style="border-radius: 6px; font-weight: 600; font-size: 0.775rem;" title="Aprobar documento">
                                                <i class="bi bi-check-lg"></i>
                                                <span>Aprobar</span>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-5 text-center">
                                    <div class="empty-state py-4">
                                        <div style="width: 52px; height: 52px; border-radius: 12px; background: var(--bg-surface-elevated); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 0.75rem; border: 1px solid var(--border-color);">
                                            <i class="bi bi-folder2-open"></i>
                                        </div>
                                        <div class="fw-bold text-body fs-6">Aún no hay documentos en el legajo digital.</div>
                                        <div class="text-secondary small mt-1">Este colaborador no registra contratos ni expedientes cargados.</div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Tarjeta de Carga de Documentos al Legajo --}}
        @can('documents.review')
            <div class="card border-0 shadow-sm p-4 rounded-3" style="background: var(--bg-surface); border: 1px solid var(--border-color) !important;">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-cloud-arrow-up text-primary fs-5"></i>
                    <h2 class="h6 fw-bold mb-0 text-body">Subir Documento al Legajo</h2>
                </div>
                <p class="small text-secondary mb-3">Los documentos cargados directamente por Recursos Humanos quedan automáticamente aprobados.</p>

                <form method="POST" action="{{ route('employees.file.upload', $employee->id) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="doc_type" class="form-label small fw-bold text-secondary mb-1">Tipo de documento</label>
                            <select id="doc_type" name="type" class="form-select form-select-sm" required style="height: 38px; border-radius: 8px;">
                                @foreach(EmployeeDocument::TYPES as $k => $v)
                                    <option value="{{ $k }}">{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="doc_expires" class="form-label small fw-bold text-secondary mb-1">Fecha de vencimiento <span class="fw-normal text-muted">(opcional)</span></label>
                            <input type="date" id="doc_expires" name="expires_at" class="form-control form-control-sm" style="height: 38px; border-radius: 8px;">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="doc_file" class="form-label small fw-bold text-secondary mb-1">Archivo adjunto (PDF, JPG o PNG)</label>
                            <input type="file" id="doc_file" name="file" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm" required style="height: 38px; border-radius: 8px;">
                        </div>
                        <div class="col-12 d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2 px-4 py-2 shadow-sm" style="border-radius: 8px; font-weight: 600; font-size: 0.85rem;">
                                <i class="bi bi-upload"></i>
                                <span>Subir al legajo (queda aprobado)</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        @endcan

    </div>

    {{-- TAB 3: DOTACIÓN Y UNIFORMES --}}
    <div class="tab-pane fade" id="tab-content-uniforms" role="tabpanel" aria-labelledby="tab-btn-uniforms">

        {{-- Chips de Tallas Registradas --}}
        <div class="legajo-sizes-row">
            <div class="legajo-size-chip">
                <div class="legajo-size-chip-info">
                    <div class="legajo-size-chip-icon">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <div>
                        <div class="legajo-size-chip-title">Polo / Camisa</div>
                        <div class="small text-secondary" style="font-size: 0.725rem;">Talla superior</div>
                    </div>
                </div>
                <span class="legajo-size-chip-val">{{ $sizes?->shirt_size ?? '—' }}</span>
            </div>
            <div class="legajo-size-chip">
                <div class="legajo-size-chip-info">
                    <div class="legajo-size-chip-icon" style="background: rgba(2, 132, 199, 0.1); color: #0284c7; border-color: rgba(2, 132, 199, 0.2);">
                        <i class="bi bi-columns-gap"></i>
                    </div>
                    <div>
                        <div class="legajo-size-chip-title">Pantalón</div>
                        <div class="small text-secondary" style="font-size: 0.725rem;">Talla inferior</div>
                    </div>
                </div>
                <span class="legajo-size-chip-val">{{ $sizes?->pants_size ?? '—' }}</span>
            </div>
            <div class="legajo-size-chip">
                <div class="legajo-size-chip-info">
                    <div class="legajo-size-chip-icon" style="background: rgba(16, 185, 129, 0.1); color: #059669; border-color: rgba(16, 185, 129, 0.2);">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <div>
                        <div class="legajo-size-chip-title">{{ UniformSize::SHOE_TYPES[$sizes?->shoe_type] ?? 'Calzado' }}</div>
                        <div class="small text-secondary" style="font-size: 0.725rem;">Talla calzado</div>
                    </div>
                </div>
                <span class="legajo-size-chip-val">{{ $sizes?->shoe_size ?? '—' }}</span>
            </div>
        </div>

        @if($sizes?->notes)
            <div class="alert alert-info py-2.5 px-3 mb-4 rounded-3 small d-flex align-items-center gap-2">
                <i class="bi bi-info-circle-fill text-primary"></i>
                <span><strong>Notas de tallaje:</strong> {{ $sizes->notes }}</span>
            </div>
        @endif

        <div class="row g-4">
            {{-- Registrar Entrega de Uniforme --}}
            @can('uniforms.manage')
                <div class="col-12 col-lg-5">
                    <div class="card border-0 shadow-sm p-4 rounded-3 h-100" style="background: var(--bg-surface); border: 1px solid var(--border-color) !important;">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-box-seam text-primary fs-5"></i>
                            <h2 class="h6 fw-bold mb-0 text-body">Registrar Entrega de Dotación</h2>
                        </div>
                        <form method="POST" action="{{ route('uniforms.deliveries.store') }}">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $employee->id }}">

                            <div class="mb-3">
                                <label for="delivery_item" class="form-label small fw-bold text-secondary mb-1">Prenda / Artículo</label>
                                <select id="delivery_item" name="item" class="form-select form-select-sm" style="height: 38px; border-radius: 8px;">
                                    @foreach(UniformDelivery::ITEMS as $k => $v)
                                        <option value="{{ $k }}">{{ $v }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label for="delivery_size" class="form-label small fw-bold text-secondary mb-1">Talla</label>
                                    <input id="delivery_size" name="size" class="form-control form-control-sm" maxlength="10" placeholder="Ej. M, 32, 42" style="height: 38px; border-radius: 8px;">
                                </div>
                                <div class="col-6">
                                    <label for="delivery_quantity" class="form-label small fw-bold text-secondary mb-1">Cantidad</label>
                                    <input type="number" id="delivery_quantity" name="quantity" class="form-control form-control-sm" value="1" min="1" max="50" style="height: 38px; border-radius: 8px;">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="delivery_delivered_at" class="form-label small fw-bold text-secondary mb-1">Fecha de entrega</label>
                                <input type="date" id="delivery_delivered_at" name="delivered_at" class="form-control form-control-sm" value="{{ now()->toDateString() }}" style="height: 38px; border-radius: 8px;">
                            </div>

                            <button type="submit" class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center gap-2 shadow-sm py-2" style="border-radius: 8px; font-weight: 600;">
                                <i class="bi bi-check2-circle"></i>
                                <span>Registrar entrega</span>
                            </button>
                        </form>
                    </div>
                </div>
            @endcan

            {{-- Historial de Entregas --}}
            <div class="col-12 {{ auth()->user()->can('uniforms.manage') ? 'col-lg-7' : 'col-lg-12' }}">
                <div class="legajo-card-table h-100">
                    <div class="p-3 border-bottom d-flex align-items-center justify-content-between" style="background: var(--bg-surface-elevated);">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-clock-history text-primary fs-5"></i>
                            <h2 class="h6 fw-bold mb-0 text-body">Historial de Entregas</h2>
                        </div>
                        <span class="small text-secondary">{{ $deliveries->count() }} registro(s)</span>
                    </div>

                    <div class="table-responsive mb-0">
                        <table class="legajo-table">
                            <thead>
                                <tr>
                                    <th>Artículo / Prenda</th>
                                    <th>Fecha</th>
                                    <th class="text-end pe-4">Entregado por</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($deliveries as $d)
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-body" style="font-size: 0.85rem;">
                                                {{ label('uniform_item', $d->item) }}
                                            </div>
                                            <div class="text-secondary small" style="font-size: 0.75rem;">
                                                Cantidad: <strong>{{ $d->quantity }}</strong> · Talla: <strong>{{ $d->size ?: 'Estándar' }}</strong>
                                            </div>
                                        </td>
                                        <td class="text-secondary">
                                            <i class="bi bi-calendar-check" style="margin-right: 6px;"></i>{{ $d->delivered_at?->format('d/m/Y') ?? '—' }}
                                        </td>
                                        <td class="text-end pe-4 text-secondary">
                                            <i class="bi bi-person-check" style="margin-right: 6px;"></i>{{ $d->deliverer?->first_name ?? 'RRHH' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="py-5 text-center">
                                            <div class="empty-state py-4">
                                                <div style="width: 52px; height: 52px; border-radius: 12px; background: var(--bg-surface-elevated); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 0.75rem; border: 1px solid var(--border-color);">
                                                    <i class="bi bi-tag"></i>
                                                </div>
                                                <div class="fw-bold text-body fs-6">Sin entregas de uniforme registradas.</div>
                                                <div class="text-secondary small mt-1">Aún no se ha entregado indumentaria a este colaborador.</div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>

{{-- Modal Dialog de Edición de Colaborador --}}
@include('employees.partials.modal-edit')

{{-- Floating Micro-Toast for Instant Feedback --}}
<div class="emp-toast" id="empToast">
    <i class="bi bi-check-circle-fill" id="empToastIcon"></i>
    <span id="empToastMsg">Acción completada con éxito</span>
</div>

<script>
window.copyLegajoText = function(text, btn) {
    if (!text || text === '—') return;
    navigator.clipboard.writeText(text).then(() => {
        const icon = btn.querySelector('i');
        if (icon) {
            const originalClass = icon.className;
            icon.className = 'bi bi-check2 text-success';
            setTimeout(() => {
                icon.className = originalClass;
            }, 1800);
        }
    }).catch(err => {
        console.error('Error al copiar:', err);
    });
};

function showLegajoToast(message, type = 'success') {
    const toast = document.getElementById('empToast');
    const msg = document.getElementById('empToastMsg');
    const icon = document.getElementById('empToastIcon');
    if (!toast) return;

    toast.className = 'emp-toast';
    toast.classList.add(type === 'success' ? 'tone-success' : 'tone-danger');
    if (icon) {
        icon.className = type === 'success' ? 'bi bi-check-circle-fill text-success' : 'bi bi-exclamation-octagon-fill text-danger';
    }
    if (msg) msg.textContent = message;

    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3800);
}

window.openLegajoEditModal = async function(id) {
    const editModalEl = document.getElementById('employeeEditModal');
    const editForm = document.getElementById('editEmployeeForm');
    const editErrorAlert = document.getElementById('editModalErrorAlert');
    if (!editModalEl || !editForm) return;

    const modalInstance = bootstrap.Modal.getOrCreateInstance(editModalEl);
    modalInstance.show();

    {{-- Limpiar errores previos --}}
    editForm.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    editForm.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
    if (editErrorAlert) editErrorAlert.classList.add('d-none');

    {{-- Resetear a primera pestaña --}}
    const tab1Btn = document.getElementById('tab-edit-personal-btn');
    if (tab1Btn) bootstrap.Tab.getOrCreateInstance(tab1Btn).show();

    try {
        const res = await fetch(`/employees/${id}/edit`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        if (!res.ok) throw new Error('No se pudo cargar la información del colaborador.');

        const data = await res.json();
        const emp = data.employee;

        editForm.action = `/employees/${emp.id}`;
        document.getElementById('edit_employee_id').value = emp.id;

        const titleEl = document.getElementById('employeeEditModalLabel');
        if (titleEl) titleEl.textContent = `Editar Colaborador: ${emp.first_name} ${emp.last_name}`;

        {{-- Tab 1: Datos Personales --}}
        if (document.getElementById('edit_first_name')) document.getElementById('edit_first_name').value = emp.first_name || '';
        if (document.getElementById('edit_last_name')) document.getElementById('edit_last_name').value = emp.last_name || '';
        if (document.getElementById('edit_document_type')) document.getElementById('edit_document_type').value = emp.document_type || 'dni';
        if (document.getElementById('edit_document_number')) document.getElementById('edit_document_number').value = emp.document_number || '';
        if (document.getElementById('edit_phone')) document.getElementById('edit_phone').value = emp.phone || '';
        if (document.getElementById('edit_birth_date')) document.getElementById('edit_birth_date').value = emp.birth_date || '';
        if (document.getElementById('edit_email')) document.getElementById('edit_email').value = emp.email || '';
        if (document.getElementById('edit_status')) document.getElementById('edit_status').value = emp.status || 'active';

        {{-- Tab 2: Puesto y Rol --}}
        if (document.getElementById('edit_employee_code')) document.getElementById('edit_employee_code').value = emp.employee_code || '';
        if (document.getElementById('edit_position_id')) document.getElementById('edit_position_id').value = emp.position_id || '';
        if (document.getElementById('edit_department_id')) document.getElementById('edit_department_id').value = emp.department_id || '';
        if (document.getElementById('edit_hire_date')) document.getElementById('edit_hire_date').value = emp.hire_date || '';
        if (document.getElementById('edit_base_salary')) document.getElementById('edit_base_salary').value = emp.base_salary || 0;
        if (document.getElementById('edit_bonus')) document.getElementById('edit_bonus').value = emp.bonus || '';
        if (document.getElementById('edit_roaming_attendance_enabled')) document.getElementById('edit_roaming_attendance_enabled').checked = !!emp.roaming_attendance_enabled;
        if (document.getElementById('edit_role')) document.getElementById('edit_role').value = emp.role || 'employee';

        {{-- Tab 3: Sedes y Turno --}}
        if (document.getElementById('edit_shift_id')) document.getElementById('edit_shift_id').value = emp.shift_id || '';

        const branchChecks = document.querySelectorAll('.edit-branch-check');
        const primaryRadios = document.querySelectorAll('.edit-primary-branch-radio');
        const assignedIds = (emp.branch_ids || []).map(b => parseInt(b, 10));

        branchChecks.forEach(cb => {
            cb.checked = assignedIds.includes(parseInt(cb.value, 10));
        });
        primaryRadios.forEach(rb => {
            rb.checked = (parseInt(rb.value, 10) === parseInt(emp.primary_branch_id, 10));
        });

    } catch (err) {
        console.error('Error loading employee edit data:', err);
        showLegajoToast(err.message || 'Error al cargar datos del colaborador', 'danger');
        modalInstance.hide();
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const editForm = document.getElementById('editEmployeeForm');
    const editModalEl = document.getElementById('employeeEditModal');
    const editErrorAlert = document.getElementById('editModalErrorAlert');
    const editErrorText = document.getElementById('editModalErrorText');
    const btnSubmit = document.getElementById('btnSubmitEditEmployee');
    const btnSpinner = document.getElementById('btnEditSpinner');
    const btnIcon = document.getElementById('btnEditIcon');
    const btnText = document.getElementById('btnEditText');

    if (editForm) {
        editForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            {{-- Limpiar errores previos --}}
            editForm.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            editForm.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
            if (editErrorAlert) editErrorAlert.classList.add('d-none');

            if (btnSubmit) btnSubmit.disabled = true;
            if (btnSpinner) btnSpinner.classList.remove('d-none');
            if (btnIcon) btnIcon.classList.add('d-none');
            if (btnText) btnText.textContent = 'Guardando…';

            const formData = new FormData(editForm);

            try {
                const response = await fetch(editForm.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();

                if (response.status === 422) {
                    const errors = data.errors || {};
                    let firstErrElement = null;

                    for (const [key, messages] of Object.entries(errors)) {
                        const cleanKey = key.replace(/\.\d+/g, '');
                        const inputEl = editForm.querySelector(`[name="${cleanKey}"]`) ||
                                        editForm.querySelector(`[name="${cleanKey}[]"]`);
                        const errEl = document.getElementById(`edit_err_${cleanKey}`);

                        if (inputEl) {
                            inputEl.classList.add('is-invalid');
                            if (!firstErrElement) firstErrElement = inputEl;
                        }
                        if (errEl) {
                            errEl.textContent = messages[0];
                        }
                    }

                    if (editErrorAlert) {
                        if (editErrorText) editErrorText.textContent = data.message || 'Por favor revisa los campos señalados en rojo.';
                        editErrorAlert.classList.remove('d-none');
                    }

                    if (firstErrElement) {
                        const tabPane = firstErrElement.closest('.tab-pane');
                        if (tabPane && tabPane.id) {
                            const triggerBtn = document.querySelector(`[data-bs-target="#${tabPane.id}"]`);
                            if (triggerBtn) bootstrap.Tab.getOrCreateInstance(triggerBtn).show();
                        }
                        firstErrElement.focus();
                    }
                } else if (response.ok && data.success) {
                    if (editModalEl) bootstrap.Modal.getOrCreateInstance(editModalEl).hide();
                    showLegajoToast(data.message || 'Datos de colaborador actualizados.', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 800);
                } else {
                    throw new Error(data.message || 'Ocurrió un error inesperado al guardar.');
                }
            } catch (err) {
                console.error('Error submitting employee update:', err);
                showLegajoToast(err.message || 'Error al actualizar el colaborador', 'danger');
            } finally {
                if (btnSubmit) btnSubmit.disabled = false;
                if (btnSpinner) btnSpinner.classList.add('d-none');
                if (btnIcon) btnIcon.classList.remove('d-none');
                if (btnText) btnText.textContent = 'Guardar Cambios';
            }
        });
    }
});
</script>
@endsection
