@php
    use App\Modules\HR\Models\EmployeeDocument;
    use App\Modules\HR\Models\EmployeeProfile;

    $canView = $revealed ?? false;
    $show = fn (?string $v) => $canView ? $v : EmployeeProfile::mask($v);
    $payStatus = $profile->payment_info_status ?? 'incomplete';
    $approvedTypes = $documents->where('status', 'approved')->pluck('type')->all();
    $required = $config->requiredDocumentsFor($employee); // empresa + su cargo
    $missing = array_diff($required, $approvedTypes);
    $sizes = $employee->uniformSize;
    $initials = mb_strtoupper(mb_substr($employee->first_name, 0, 1) . mb_substr($employee->last_name, 0, 1));
    $primaryBranch = $employee->branches->firstWhere('pivot.is_primary', true) ?? $employee->branches->first();
    $banksList = config('payroll.banks', []);
    $pensionFundsList = config('payroll.pension_funds', []);
@endphp

<style>
{{-- =============================================================================
Expediente y Ficha del Colaborador — Executive Responsive Design
============================================================================= --}}
.legajo-container {
    display: flex;
    flex-direction: column;
    gap: 1.15rem;
    color: var(--text-primary);
    font-size: 0.825rem;
}

{{-- 1. Header del Colaborador --}}
.legajo-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding-bottom: 0.95rem;
    border-bottom: 1px solid var(--border-color);
}
.legajo-avatar {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    font-weight: 800;
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    color: var(--brand);
    flex-shrink: 0;
    overflow: hidden;
}
.legajo-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.legajo-name {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
    line-height: 1.25;
}
.legajo-sub-line {
    font-size: 0.74rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
}
.legajo-sep {
    color: var(--text-muted);
    opacity: 0.4;
}

{{-- 2. Barra de Métricas Sutil y Compacta (4 KPIs) --}}
.legajo-stat-strip {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    border-radius: 11px;
    padding: 0.7rem 0.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.legajo-stat-item {
    padding: 0 0.85rem;
    border-right: 1px solid var(--border-color);
}
.legajo-stat-item:last-child {
    border-right: none;
}
.legajo-stat-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    margin-bottom: 0.15rem;
}
.legajo-stat-value {
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.legajo-stat-note {
    font-size: 0.68rem;
    color: var(--text-muted);
    margin-top: 0.1rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

{{-- 3. Pestañas Naturales con Indicador Inferior --}}
.legajo-tabs {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 0.35rem;
}
.legajo-tab-btn {
    padding: 0.55rem 0;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--text-muted);
    font-size: 0.825rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    cursor: pointer;
    transition: all 0.15s ease;
    margin-bottom: -1px;
}
.legajo-tab-btn:hover {
    color: var(--text-primary);
}
.legajo-tab-btn.active {
    color: var(--brand);
    border-bottom-color: var(--brand);
    font-weight: 700;
}
.legajo-tab-badge {
    font-size: 0.68rem;
    padding: 0.1rem 0.45rem;
    border-radius: 9999px;
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    color: var(--text-muted);
}
.legajo-tab-btn.active .legajo-tab-badge {
    background: rgba(var(--brand-rgb), 0.12);
    border-color: rgba(var(--brand-rgb), 0.25);
    color: var(--brand);
}

{{-- 4. Tab 1: Grid de 3 Columnas Exacto y Adaptable (Cero Espacio Muerto) --}}
.legajo-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1.15rem;
    width: 100%;
}
.legajo-section {
    display: flex;
    flex-direction: column;
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    border-radius: 11px;
    padding: 0.95rem 1.05rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    transition: border-color 0.15s ease;
}
.legajo-section:hover {
    border-color: rgba(var(--brand-rgb), 0.3);
}
.legajo-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding-bottom: 0.55rem;
    margin-bottom: 0.55rem;
    border-bottom: 1px solid var(--border-color);
}
.legajo-section-title {
    font-size: 0.725rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.45rem;
    margin: 0;
}
.legajo-section-title i {
    color: var(--brand);
    font-size: 0.85rem;
}
.legajo-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.65rem;
    padding: 0.46rem 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    font-size: 0.785rem;
}
[data-bs-theme="light"] .legajo-row {
    border-bottom: 1px solid rgba(0, 0, 0, 0.04);
}
.legajo-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
.legajo-label {
    color: var(--text-muted);
    font-weight: 500;
    white-space: nowrap;
    flex-shrink: 0;
}
.legajo-value {
    color: var(--text-primary);
    font-weight: 600;
    text-align: right;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.3rem;
}
.legajo-value.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    letter-spacing: 0.02em;
}

{{-- Micro-acciones: Copiar y Alternar --}}
.legajo-copy-btn {
    background: transparent;
    border: none;
    padding: 0.1rem 0.3rem;
    color: var(--text-muted);
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.15s ease;
    border-radius: 4px;
    line-height: 1;
}
.legajo-copy-btn:hover {
    color: var(--brand);
    background: rgba(var(--brand-rgb), 0.12);
}
.legajo-btn-ghost {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    color: var(--text-muted);
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.3rem 0.75rem;
    border-radius: 7px;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    cursor: pointer;
    transition: all 0.18s ease;
    line-height: 1.35;
    user-select: none;
}
.legajo-btn-ghost:hover {
    color: var(--text-primary);
    border-color: rgba(56, 189, 248, 0.4);
    background: rgba(var(--brand-rgb), 0.08);
    transform: translateY(-0.5px);
}
.legajo-btn-ghost:active {
    transform: translateY(0);
}

.legajo-btn-primary {
    background: linear-gradient(135deg, rgba(var(--brand-rgb), 0.24) 0%, rgba(14, 165, 233, 0.14) 100%);
    border: 1px solid rgba(56, 189, 248, 0.4);
    color: #38bdf8;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.3rem 0.8rem;
    border-radius: 7px;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.08);
    text-decoration: none;
    line-height: 1.35;
    user-select: none;
}
.legajo-btn-primary:hover {
    background: linear-gradient(135deg, rgba(var(--brand-rgb), 0.4) 0%, rgba(14, 165, 233, 0.26) 100%);
    border-color: rgba(56, 189, 248, 0.75);
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(var(--brand-rgb), 0.32), inset 0 1px 0 rgba(255, 255, 255, 0.15);
    transform: translateY(-1px);
}
.legajo-btn-primary:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}
.legajo-btn-primary.is-editing {
    background: rgba(100, 116, 139, 0.18);
    border-color: rgba(148, 163, 184, 0.38);
    color: #cbd5e1;
}
.legajo-btn-primary.is-editing:hover {
    background: rgba(100, 116, 139, 0.3);
    border-color: #94a3b8;
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}

{{-- Formulario de Modificación de Ficha --}}
.legajo-form-label {
    font-size: 0.69rem;
    font-weight: 700;
    color: var(--text-muted);
    margin-bottom: 0.2rem;
    display: block;
}
.legajo-form-input, .legajo-form-select {
    width: 100%;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    color: var(--text-primary);
    padding: 0.36rem 0.55rem;
    font-size: 0.785rem;
    transition: all 0.15s ease;
}
.legajo-form-input:focus, .legajo-form-select:focus {
    outline: none;
    border-color: var(--brand);
    box-shadow: 0 0 0 2px rgba(var(--brand-rgb), 0.2);
}
.legajo-form-input.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
.legajo-form-group {
    margin-bottom: 0.65rem;
}
.legajo-form-group:last-child {
    margin-bottom: 0;
}

{{-- 5. Tab 2 & 3: Tarjetas Contenedoras de Tablas --}}
.legajo-card-table {
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    border-radius: 11px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.legajo-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.785rem;
    margin-bottom: 0;
}
.legajo-table th {
    text-align: left;
    padding: 0.65rem 0.85rem;
    font-size: 0.675rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    background: rgba(var(--brand-rgb), 0.03);
    border-bottom: 1px solid var(--border-color);
}
.legajo-table td {
    padding: 0.65rem 0.85rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
}
.legajo-table tr:last-child td {
    border-bottom: none;
}
.legajo-table tbody tr:hover {
    background: rgba(var(--brand-rgb), 0.02);
}
.legajo-link-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.6rem;
    font-size: 0.725rem;
    font-weight: 600;
    border-radius: 6px;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
    text-decoration: none;
    transition: all 0.15s ease;
}
.legajo-link-btn:hover {
    color: var(--brand);
    border-color: var(--brand);
    background: rgba(var(--brand-rgb), 0.08);
}

{{-- 6. Chips de Tallas de Uniforme --}}
.legajo-sizes-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.95rem;
    margin-bottom: 1rem;
}
.legajo-size-chip {
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    border-radius: 11px;
    padding: 0.75rem 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.legajo-size-chip-info {
    display: flex;
    align-items: center;
    gap: 0.55rem;
}
.legajo-size-chip-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: rgba(var(--brand-rgb), 0.1);
    color: var(--brand);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
}
.legajo-size-chip-title {
    font-size: 0.725rem;
    color: var(--text-muted);
    font-weight: 600;
}
.legajo-size-chip-val {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--text-primary);
}

{{-- 7. Estados Vacíos Elegantes --}}
.legajo-empty-box {
    padding: 2.25rem 1.5rem;
    text-align: center;
    color: var(--text-muted);
}
.legajo-empty-icon {
    width: 46px;
    height: 46px;
    margin: 0 auto 0.75rem;
    border-radius: 50%;
    background: rgba(var(--brand-rgb), 0.08);
    color: var(--brand);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    border: 1px solid rgba(var(--brand-rgb), 0.18);
}
.legajo-empty-title {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
}
.legajo-empty-desc {
    font-size: 0.75rem;
    max-width: 380px;
    margin: 0 auto;
    color: var(--text-muted);
}

{{-- 8. Responsividad Adaptable --}}
@media (max-width: 991px) {
    .legajo-grid {
        grid-template-columns: 1fr;
        gap: 0.95rem;
    }
    .legajo-sizes-row {
        grid-template-columns: 1fr;
        gap: 0.65rem;
    }
}
@media (max-width: 768px) {
    .legajo-stat-strip {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.5rem;
    }
    .legajo-stat-item {
        border-right: none;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 0.4rem;
    }
    .legajo-stat-item:nth-last-child(-n+2) {
        border-bottom: none;
    }
}
</style>

<div class="legajo-container">
    {{-- 1. Encabezado del Colaborador --}}
    <div class="legajo-header">
        <div class="legajo-avatar">
            @if($employee->avatar_path)
                <img src="{{ asset('storage/' . $employee->avatar_path) }}" alt="{{ $employee->first_name }}">
            @else
                <span>{{ $initials ?: 'U' }}</span>
            @endif
        </div>
        <div class="flex-grow-1 min-w-0">
            <div class="d-flex align-items-center gap-2">
                <h3 class="legajo-name">{{ $employee->full_name }}</h3>
                <span class="badge {{ $employee->status === 'active' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary' }}" style="font-size: 0.68rem; padding: 0.15rem 0.45rem;">
                    {{ $employee->status === 'active' ? 'Activo' : 'Inactivo' }}
                </span>
            </div>
            <div class="legajo-sub-line">
                <span class="fw-semibold" style="color: var(--text-primary);">{{ $employee->position ?: 'Sin cargo' }}</span>
                <span class="legajo-sep">·</span>
                <span>{{ label('user_role', $employee->role) }}</span>
                <span class="legajo-sep">·</span>
                <span>{{ $employee->employee_code }}</span>
                <span class="legajo-sep">·</span>
                <span>{{ strtoupper($employee->document_type ?? 'DOC') }}: {{ $employee->document_number ?: 'Sin registro' }}</span>
                @if($primaryBranch)
                    <span class="legajo-sep">·</span>
                    <span><i class="bi bi-geo-alt-fill text-warning me-1"></i>{{ $primaryBranch->name }}</span>
                @endif
                <span class="legajo-sep">·</span>
                <span>Ingreso: {{ $employee->hire_date?->format('d/m/Y') ?? '—' }}</span>
            </div>
        </div>
    </div>

    {{-- 2. Barra de Métricas (4 KPIs Clave) --}}
    <div class="legajo-stat-strip">
        <div class="legajo-stat-item">
            <div class="legajo-stat-label">Documentos</div>
            <div class="legajo-stat-value">
                <span>{{ count($required) - count($missing) }} / {{ count($required) }}</span>
            </div>
            <div class="legajo-stat-note {{ $missing ? 'text-warning' : 'text-success' }}">
                {{ $missing ? 'Falta: ' . count($missing) . ' obligatorio(s)' : '100% completo' }}
            </div>
        </div>
        <div class="legajo-stat-item">
            <div class="legajo-stat-label">Datos de Pago</div>
            @php
                $payBadgeClass = match($payStatus) {
                    'verified' => 'bg-success-subtle text-success border border-success-subtle',
                    'pending'  => 'bg-warning-subtle text-warning border border-warning-subtle',
                    'rejected' => 'bg-danger-subtle text-danger border border-danger-subtle',
                    default    => 'bg-secondary-subtle text-secondary',
                };
            @endphp
            <div class="legajo-stat-value">
                <span class="badge {{ $payBadgeClass }}" style="font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.45rem;">
                    {{ EmployeeProfile::PAYMENT_STATUSES[$payStatus] ?? ucfirst($payStatus) }}
                </span>
            </div>
            <div class="legajo-stat-note">
                {{ $profile->payment_info_updated_at ? 'Act. ' . $profile->payment_info_updated_at->diffForHumans() : 'Sin registrar' }}
            </div>
        </div>
        <div class="legajo-stat-item">
            <div class="legajo-stat-label">Dotación</div>
            <div class="legajo-stat-value">
                <span>{{ $sizes?->isComplete() ? 'Completas' : 'Pendientes' }}</span>
            </div>
            <div class="legajo-stat-note">
                {{ $deliveries->count() }} entregas registradas
            </div>
        </div>
        <div class="legajo-stat-item">
            <div class="legajo-stat-label">Acceso Móvil</div>
            <div class="legajo-stat-value">
                <span>{{ $employee->last_login_at?->format('d/m/Y') ?? 'Nunca' }}</span>
            </div>
            <div class="legajo-stat-note {{ $employee->isLocked() ? 'text-danger' : '' }}">
                {{ $employee->isLocked() ? 'Bloqueado' : ($employee->must_change_password ? 'Clave provisional' : 'Normal') }}
            </div>
        </div>
    </div>

    {{-- 3. Pestañas de Navegación Claras y Entendibles --}}
    <div class="legajo-tabs" id="fileModalTabNav" role="tablist">
        <button class="legajo-tab-btn active" id="tab-sub-laboral-btn" data-bs-toggle="tab" data-bs-target="#tab-sub-laboral" type="button" role="tab">
            <i class="bi bi-person-vcard"></i>
            <span>Datos Laborales y Cuentas</span>
        </button>
        <button class="legajo-tab-btn" id="tab-sub-docs-btn" data-bs-toggle="tab" data-bs-target="#tab-sub-docs" type="button" role="tab">
            <i class="bi bi-file-earmark-text"></i>
            <span>Documentos del Expediente</span>
            <span class="legajo-tab-badge">{{ $documents->count() }}</span>
        </button>
        <button class="legajo-tab-btn" id="tab-sub-uniforms-btn" data-bs-toggle="tab" data-bs-target="#tab-sub-uniforms" type="button" role="tab">
            <i class="bi bi-shield-shaded"></i>
            <span>Dotación y Uniformes</span>
            <span class="legajo-tab-badge">{{ $deliveries->count() }}</span>
        </button>
    </div>

    {{-- 4. Contenido de las Pestañas --}}
    <div class="tab-content" id="fileModalTabContent">

        {{-- SUBTAB 1: FICHA LABORAL Y CUENTAS BANCARIAS --}}
        <div class="tab-pane fade show active" id="tab-sub-laboral" role="tabpanel">

            {{-- Barra Superior de Acciones en la Ficha --}}
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="small fw-semibold text-muted d-flex align-items-center gap-1">
                    <i class="bi bi-shield-check text-info"></i>
                    <span>Cuentas de abono y previsión validadas para planilla y pagos</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    @if($canView)
                        <button type="button" class="legajo-btn-ghost" id="btnToggleMaskAccounts" onclick="event.stopPropagation(); window.toggleEmployeeAccountMask();" title="Ocultar o mostrar números">
                            <i class="bi bi-eye-slash" id="btnMaskIcon"></i>
                            <span id="btnMaskLabel">Ocultar números</span>
                        </button>
                    @endif
                    @can('profiles.manage')
                        <button type="button" class="legajo-btn-primary" id="btnToggleEditProfile" onclick="event.stopPropagation(); window.toggleEmployeeProfileEdit();" title="Modificar ficha del colaborador">
                            <i class="bi bi-pencil-square" id="btnEditProfileIcon"></i>
                            <span id="btnEditProfileText">Modificar Ficha</span>
                        </button>
                    @endcan
                </div>
            </div>

            {{-- VISTA 1A: LECTURA DE DATOS (3 COLUMNAS SIMÉTRICAS) --}}
            <div id="profileDetailsView">
                <div class="legajo-grid">
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
                                <span class="unmasked-val">{{ $canView ? ($profile->bank_account_number ?: '—') : EmployeeProfile::mask($profile->bank_account_number) }}</span>
                                <span class="masked-val d-none">{{ EmployeeProfile::mask($profile->bank_account_number) }}</span>
                                @if($profile->bank_account_number)
                                    <button type="button" class="legajo-copy-btn" data-copy="{{ $profile->bank_account_number }}" onclick="copyLegajoText('{{ $profile->bank_account_number }}', this)" title="Copiar número de cuenta">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                @endif
                            </span>
                        </div>
                        <div class="legajo-row">
                            <span class="legajo-label">Código CCI</span>
                            <span class="legajo-value mono">
                                <span class="unmasked-val">{{ $canView ? ($profile->interbank_code ?: '—') : EmployeeProfile::mask($profile->interbank_code) }}</span>
                                <span class="masked-val d-none">{{ EmployeeProfile::mask($profile->interbank_code) }}</span>
                                @if($profile->interbank_code)
                                    <button type="button" class="legajo-copy-btn" data-copy="{{ $profile->interbank_code }}" onclick="copyLegajoText('{{ $profile->interbank_code }}', this)" title="Copiar código interbancario CCI">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                @endif
                            </span>
                        </div>
                        <div class="legajo-row">
                            <span class="legajo-label">Cuenta CTS</span>
                            <span class="legajo-value mono">
                                <span class="unmasked-val">{{ $profile->cts_bank_name ? $profile->cts_bank_name . ' · ' . ($canView ? ($profile->cts_account_number ?: '—') : EmployeeProfile::mask($profile->cts_account_number)) : '—' }}</span>
                                <span class="masked-val d-none">{{ $profile->cts_bank_name ? $profile->cts_bank_name . ' · ' . EmployeeProfile::mask($profile->cts_account_number) : '—' }}</span>
                                @if($profile->cts_account_number)
                                    <button type="button" class="legajo-copy-btn" data-copy="{{ $profile->cts_account_number }}" onclick="copyLegajoText('{{ $profile->cts_account_number }}', this)" title="Copiar cuenta CTS">
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
                                <span class="unmasked-val">{{ $canView ? ($profile->pension_code ?: '—') : EmployeeProfile::mask($profile->pension_code) }}</span>
                                <span class="masked-val d-none">{{ EmployeeProfile::mask($profile->pension_code) }}</span>
                                @if($profile->pension_code)
                                    <button type="button" class="legajo-copy-btn" data-copy="{{ $profile->pension_code }}" onclick="copyLegajoText('{{ $profile->pension_code }}', this)" title="Copiar código CUSPP">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                @endif
                            </span>
                        </div>
                        <div class="legajo-row">
                            <span class="legajo-label">Régimen laboral</span>
                            <span class="legajo-value">{{ $laborRegimes[$profile->labor_regime] ?? ($profile->labor_regime ?: '—') }}</span>
                        </div>
                        <div class="legajo-row">
                            <span class="legajo-label">Seguro de salud</span>
                            <span class="legajo-value">{{ EmployeeProfile::HEALTH_INSURANCES[$profile->health_insurance] ?? '—' }}</span>
                        </div>
                    </div>

                    {{-- Columna 3: Ubicación y Contacto de Emergencia --}}
                    <div class="legajo-section">
                        <div class="legajo-section-header">
                            <div class="legajo-section-title">
                                <i class="bi bi-geo-alt"></i>
                                <span>Ubicación y Contacto</span>
                            </div>
                            <span class="badge {{ $profile->address ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary' }}" style="font-size: 0.65rem; font-weight: 700;">
                                {{ $profile->address ? 'REGISTRADO' : 'PENDIENTE' }}
                            </span>
                        </div>
                        <div class="legajo-row">
                            <span class="legajo-label">Domicilio</span>
                            <span class="legajo-value" title="{{ $profile->address ?: 'Sin dirección registrada' }}">
                                {{ $profile->address ?: 'Sin dirección registrada' }}
                            </span>
                        </div>
                        <div class="legajo-row">
                            <span class="legajo-label">Distrito / Sede</span>
                            <span class="legajo-value" title="{{ $profile->district ?: ($primaryBranch ? $primaryBranch->name : '—') }}">
                                {{ $profile->district ?: ($primaryBranch ? $primaryBranch->name : '—') }}
                            </span>
                        </div>
                        <div class="legajo-row">
                            <span class="legajo-label">Contacto emergencia</span>
                            <span class="legajo-value" title="{{ $profile->emergency_contact_name ?: 'Sin contacto registrado' }}">
                                {{ $profile->emergency_contact_name ?: 'Sin contacto registrado' }}
                            </span>
                        </div>
                        <div class="legajo-row">
                            <span class="legajo-label">Teléfono contacto</span>
                            <span class="legajo-value mono">
                                @if($profile->emergency_contact_phone)
                                    <i class="bi bi-telephone text-success me-1"></i>{{ $profile->emergency_contact_phone }}
                                    <button type="button" class="legajo-copy-btn" data-copy="{{ $profile->emergency_contact_phone }}" onclick="copyLegajoText('{{ $profile->emergency_contact_phone }}', this)" title="Copiar teléfono">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </span>
                        </div>
                        <div class="legajo-row">
                            <span class="legajo-label">Teléfono personal</span>
                            <span class="legajo-value mono">
                                @if($employee->phone)
                                    <i class="bi bi-phone text-primary me-1"></i>{{ $employee->phone }}
                                    <button type="button" class="legajo-copy-btn" data-copy="{{ $employee->phone }}" onclick="copyLegajoText('{{ $employee->phone }}', this)" title="Copiar teléfono">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- VISTA 1B: FORMULARIO DE MODIFICACIÓN DE DATOS (OCULTO POR DEFECTO) --}}
            @can('profiles.manage')
            <div id="profileEditFormWrapper" class="d-none">
                <form id="formUpdateEmployeeProfile" method="POST" action="{{ route('employees.file.profile', $employee->id) }}" onsubmit="return window.submitEmployeeProfileForm(event, this);">
                    @csrf
                    @method('PUT')

                    <div class="alert alert-danger py-2 px-3 small d-none mb-3" id="profileFormAlert" role="alert"></div>

                    <div class="legajo-grid">
                        {{-- Columna 1 Form: Cuentas Bancarias --}}
                        <div class="legajo-section">
                            <div class="legajo-section-header">
                                <div class="legajo-section-title">
                                    <i class="bi bi-bank"></i>
                                    <span>Editar Cuentas</span>
                                </div>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.65rem;">
                                    PAGOS
                                </span>
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_bank_name" class="legajo-form-label">Banco de Abono</label>
                                <input id="edit_bank_name" name="bank_name" list="bankListOptions" class="legajo-form-input" value="{{ $profile->bank_name }}" placeholder="Ej. BCP, BBVA...">
                            </div>
                            <div class="row g-2 legajo-form-group">
                                <div class="col-7">
                                    <label for="edit_bank_account_type" class="legajo-form-label">Tipo de Cuenta</label>
                                    <select id="edit_bank_account_type" name="bank_account_type" class="legajo-form-select">
                                        <option value="">— Seleccionar —</option>
                                        @foreach(EmployeeProfile::BANK_ACCOUNT_TYPES as $k => $v)
                                            <option value="{{ $k }}" @selected($profile->bank_account_type === $k)>{{ $v }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-5">
                                    <label for="edit_bank_account_currency" class="legajo-form-label">Moneda</label>
                                    <select id="edit_bank_account_currency" name="bank_account_currency" class="legajo-form-select">
                                        <option value="PEN" @selected(($profile->bank_account_currency ?? 'PEN') === 'PEN')>PEN (S/)</option>
                                        <option value="USD" @selected(($profile->bank_account_currency ?? '') === 'USD')>USD ($)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_bank_account_number" class="legajo-form-label">N° de Cuenta Bancaria</label>
                                <input id="edit_bank_account_number" name="bank_account_number" class="legajo-form-input mono" value="{{ $profile->bank_account_number }}" placeholder="Ej. 191-12345678-0-12">
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_interbank_code" class="legajo-form-label">Código CCI Interbancario</label>
                                <input id="edit_interbank_code" name="interbank_code" class="legajo-form-input mono" value="{{ $profile->interbank_code }}" placeholder="Ej. 002-191-001234567801-23">
                            </div>
                            <div class="row g-2 legajo-form-group">
                                <div class="col-6">
                                    <label for="edit_cts_bank_name" class="legajo-form-label">Banco CTS</label>
                                    <input id="edit_cts_bank_name" name="cts_bank_name" list="bankListOptions" class="legajo-form-input" value="{{ $profile->cts_bank_name }}" placeholder="Banco CTS">
                                </div>
                                <div class="col-6">
                                    <label for="edit_cts_account_number" class="legajo-form-label">N° Cuenta CTS</label>
                                    <input id="edit_cts_account_number" name="cts_account_number" class="legajo-form-input mono" value="{{ $profile->cts_account_number }}" placeholder="N° CTS">
                                </div>
                            </div>
                        </div>

                        {{-- Columna 2 Form: Previsión y Régimen --}}
                        <div class="legajo-section">
                            <div class="legajo-section-header">
                                <div class="legajo-section-title">
                                    <i class="bi bi-shield-check"></i>
                                    <span>Editar Previsión</span>
                                </div>
                                <span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size: 0.65rem;">
                                    LABORAL
                                </span>
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_labor_regime" class="legajo-form-label">Régimen Laboral</label>
                                <select id="edit_labor_regime" name="labor_regime" class="legajo-form-select">
                                    <option value="">— Seleccionar —</option>
                                    @foreach($laborRegimes as $k => $v)
                                        <option value="{{ $k }}" @selected($profile->labor_regime === $k)>{{ $v }}</option>
                                    @endforeach
                                </select>
                                <input type="hidden" name="family_allowance" value="0">
                                <div class="form-check mt-2">
                                    <input id="edit_family_allowance" name="family_allowance" value="1" type="checkbox" class="form-check-input" @checked($profile->family_allowance)>
                                    <label for="edit_family_allowance" class="form-check-label small">Recibe asignación familiar (hijos)</label>
                                </div>
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_pension_system" class="legajo-form-label">Sistema Previsional</label>
                                <select id="edit_pension_system" name="pension_system" class="legajo-form-select">
                                    <option value="">— Seleccionar —</option>
                                    @foreach(EmployeeProfile::PENSION_SYSTEMS as $k => $v)
                                        <option value="{{ $k }}" @selected($profile->pension_system === $k)>{{ $v }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_pension_fund" class="legajo-form-label">Fondo / Administradora AFP</label>
                                <input id="edit_pension_fund" name="pension_fund" list="pensionFundOptions" class="legajo-form-input" value="{{ $profile->pension_fund }}" placeholder="Ej. AFP Integra, Prima...">
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_pension_code" class="legajo-form-label">Código CUSPP</label>
                                <input id="edit_pension_code" name="pension_code" class="legajo-form-input mono" value="{{ $profile->pension_code }}" placeholder="Ej. 123450INTEG1">
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_health_insurance" class="legajo-form-label">Seguro de Salud</label>
                                <select id="edit_health_insurance" name="health_insurance" class="legajo-form-select">
                                    <option value="">— Seleccionar —</option>
                                    @foreach(EmployeeProfile::HEALTH_INSURANCES as $k => $v)
                                        <option value="{{ $k }}" @selected($profile->health_insurance === $k)>{{ $v }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Columna 3 Form: Ubicación y Emergencias --}}
                        <div class="legajo-section">
                            <div class="legajo-section-header">
                                <div class="legajo-section-title">
                                    <i class="bi bi-geo-alt"></i>
                                    <span>Editar Contacto</span>
                                </div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.65rem;">
                                    EMERGENCIA
                                </span>
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_address" class="legajo-form-label">Dirección Domiciliaria</label>
                                <input id="edit_address" name="address" class="legajo-form-input" value="{{ $profile->address }}" placeholder="Av. Principal 123, Urb...">
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_district" class="legajo-form-label">Distrito / Localidad</label>
                                <input id="edit_district" name="district" class="legajo-form-input" value="{{ $profile->district }}" placeholder="Ej. Lince, Trujillo...">
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_emergency_contact_name" class="legajo-form-label">Contacto de Emergencia</label>
                                <input id="edit_emergency_contact_name" name="emergency_contact_name" class="legajo-form-input" value="{{ $profile->emergency_contact_name }}" placeholder="Nombres y parentesco">
                            </div>
                            <div class="legajo-form-group">
                                <label for="edit_emergency_contact_phone" class="legajo-form-label">Teléfono de Emergencia</label>
                                <input id="edit_emergency_contact_phone" name="emergency_contact_phone" class="legajo-form-input mono" value="{{ $profile->emergency_contact_phone }}" placeholder="Ej. 987654321">
                            </div>
                        </div>
                    </div>

                    {{-- Botones de Acción del Formulario --}}
                    <div class="d-flex align-items-center justify-content-end gap-2 mt-3 pt-2 border-top border-secondary-subtle">
                        <button type="button" class="legajo-btn-ghost px-3" id="btnCancelEditProfile" onclick="event.stopPropagation(); window.toggleEmployeeProfileEdit(false);">
                            <i class="bi bi-x-circle me-1"></i><span>Cancelar</span>
                        </button>
                        <button type="submit" class="legajo-btn-primary px-3" id="btnSaveProfile">
                            <i class="bi bi-check2-circle"></i><span>Guardar Cambios</span>
                        </button>
                    </div>
                </form>
            </div>
            @endcan

        </div>

        {{-- SUBTAB 2: DOCUMENTOS --}}
        <div class="tab-pane fade" id="tab-sub-docs" role="tabpanel">
            <div class="legajo-card-table">
                <div class="table-responsive mb-0">
                    <table class="legajo-table">
                        <thead>
                            <tr>
                                <th>Documento</th>
                                <th>Fecha de Carga</th>
                                <th>Vencimiento</th>
                                <th>Estado</th>
                                <th class="text-end">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($documents as $doc)
                                @php
                                    $docTone = match($doc->status) {
                                        'approved' => 'bg-success-subtle text-success border border-success-subtle',
                                        'pending'  => 'bg-warning-subtle text-warning border border-warning-subtle',
                                        'rejected' => 'bg-danger-subtle text-danger border border-danger-subtle',
                                        default    => 'bg-secondary-subtle text-secondary',
                                    };
                                    $isPdf = str_ends_with(strtolower($doc->original_name ?? ''), '.pdf');
                                @endphp
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="text-primary fs-5">
                                                <i class="bi {{ $isPdf ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-text text-info' }}"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-truncate" style="max-width: 260px;">
                                                    {{ EmployeeDocument::TYPES[$doc->type] ?? ucfirst($doc->type) }}
                                                </div>
                                                <div class="text-muted text-truncate" style="font-size: 0.7rem; max-width: 260px;">
                                                    {{ $doc->original_name }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-muted">
                                        <i class="bi bi-calendar3 me-1 opacity-50"></i>{{ $doc->created_at->format('d/m/Y') }}
                                    </td>
                                    <td>
                                        @if($doc->expires_at)
                                            <span class="{{ $doc->isExpired() ? 'text-danger fw-bold' : 'text-muted' }}">
                                                <i class="bi bi-clock-history me-1 opacity-50"></i>{{ $doc->expires_at->format('d/m/Y') }}
                                            </span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $docTone }}" style="font-size: 0.68rem; padding: 0.2rem 0.5rem; font-weight: 700;">
                                            {{ EmployeeDocument::STATUSES[$doc->status] ?? ucfirst($doc->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('documents.open', $doc->id) }}" target="_blank" class="legajo-link-btn" title="Ver archivo">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                            <span>Ver</span>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="p-0">
                                        <div class="legajo-empty-box">
                                            <div class="legajo-empty-icon">
                                                <i class="bi bi-folder2-open"></i>
                                            </div>
                                            <div class="legajo-empty-title">Sin documentos cargados</div>
                                            <div class="legajo-empty-desc">
                                                Este colaborador no tiene documentos oficiales o contratos adjuntos en su expediente digital.
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- SUBTAB 3: UNIFORMES Y DOTACIÓN --}}
        <div class="tab-pane fade" id="tab-sub-uniforms" role="tabpanel">
            {{-- Chips de Tallas --}}
            <div class="legajo-sizes-row">
                <div class="legajo-size-chip">
                    <div class="legajo-size-chip-info">
                        <div class="legajo-size-chip-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <div>
                            <div class="legajo-size-chip-title">Polo / Camisa</div>
                            <div class="small text-muted" style="font-size: 0.68rem;">Talla superior</div>
                        </div>
                    </div>
                    <span class="legajo-size-chip-val">{{ $sizes->shirt_size ?? '—' }}</span>
                </div>
                <div class="legajo-size-chip">
                    <div class="legajo-size-chip-info">
                        <div class="legajo-size-chip-icon">
                            <i class="bi bi-columns-gap"></i>
                        </div>
                        <div>
                            <div class="legajo-size-chip-title">Pantalón</div>
                            <div class="small text-muted" style="font-size: 0.68rem;">Talla inferior</div>
                        </div>
                    </div>
                    <span class="legajo-size-chip-val">{{ $sizes->pants_size ?? '—' }}</span>
                </div>
                <div class="legajo-size-chip">
                    <div class="legajo-size-chip-info">
                        <div class="legajo-size-chip-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <div>
                            <div class="legajo-size-chip-title">Calzado / Botas</div>
                            <div class="small text-muted" style="font-size: 0.68rem;">Talla calzado</div>
                        </div>
                    </div>
                    <span class="legajo-size-chip-val">{{ $sizes->shoes_size ?? '—' }}</span>
                </div>
            </div>

            {{-- Tabla de Entregas --}}
            <div class="legajo-card-table">
                <div class="table-responsive mb-0">
                    <table class="legajo-table">
                        <thead>
                            <tr>
                                <th>Artículo / Prenda</th>
                                <th>Fecha de Entrega</th>
                                <th class="text-end">Entregado Por</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($deliveries as $del)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $del->item_name }}</div>
                                        <div class="text-muted" style="font-size: 0.7rem;">
                                            Cantidad: {{ $del->quantity }} un. · Talla: {{ $del->size ?? 'Estándar' }}
                                        </div>
                                    </td>
                                    <td class="text-muted">
                                        <i class="bi bi-calendar-check me-1 opacity-50"></i>{{ $del->delivered_at?->format('d/m/Y') ?? '—' }}
                                    </td>
                                    <td class="text-end text-muted">
                                        <i class="bi bi-person-check me-1 opacity-50"></i>{{ $del->deliverer?->full_name ?? 'Sistema' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="p-0">
                                        <div class="legajo-empty-box">
                                            <div class="legajo-empty-icon">
                                                <i class="bi bi-tag"></i>
                                            </div>
                                            <div class="legajo-empty-title">Sin entregas registradas</div>
                                            <div class="legajo-empty-desc">
                                                No se registran entregas de uniformes, EPPs o indumentaria laboral para este colaborador.
                                            </div>
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

{{-- Datalists para Asistencia Rápida en Formulario --}}
<datalist id="bankListOptions">
    @foreach($banksList as $b)
        <option value="{{ $b }}">
    @endforeach
</datalist>
<datalist id="pensionFundOptions">
    @foreach($pensionFundsList as $f)
        <option value="{{ $f }}">
    @endforeach
</datalist>

<script>
{{-- Respaldo de seguridad en caso de ser invocado sin layout padre --}}
if (typeof window.copyLegajoText !== 'function') {
    window.copyLegajoText = function(text, btn) {
        if (!text || text === '—') return;
        navigator.clipboard.writeText(text);
    };
}
</script>
