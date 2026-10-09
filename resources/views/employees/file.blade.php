@extends('layouts.app')

@section('title', 'Legajo · ' . $employee->full_name)

@section('content')
@php
    use App\Models\EmployeeDocument;
    use App\Models\EmployeeProfile;
    use App\Models\UniformDelivery;
    use App\Models\UniformSize;

    $show   = fn (?string $v) => $revealed ? $v : EmployeeProfile::mask($v);
    $docBadge = ['pending' => 'badge-late', 'approved' => 'badge-present', 'rejected' => 'badge-absent', 'superseded' => 'badge-muted'];
    $payBadge = ['incomplete' => 'badge-muted', 'pending' => 'badge-late', 'verified' => 'badge-present', 'rejected' => 'badge-absent'];
    $approvedTypes = $documents->where('status', 'approved')->pluck('type')->all();
    $required = $config->requiredDocuments();
    $missing  = array_diff($required, $approvedTypes);
@endphp

<div class="page-head">
    <div>
        <a href="{{ route('employees.index') }}" class="text-muted" style="font-size: 0.85rem; text-decoration: none;">← Colaboradores</a>
        <h1>{{ $employee->full_name }}</h1>
        <p>{{ $employee->position ?: 'Sin cargo' }} · {{ $employee->employee_code }} · {{ strtoupper($employee->document_type) }} {{ $employee->document_number }}
            · Ingreso {{ $employee->hire_date?->format('d/m/Y') ?? '—' }}</p>
    </div>
    <a href="{{ route('employees.edit', $employee->id) }}" class="btn btn-ghost">Editar datos básicos</a>
</div>

@if ($errors->any())
    <div class="card" style="margin-bottom: 1rem; border-color: rgba(239,68,68,0.3);"><div class="card-body" style="color: #fca5a5;">{{ $errors->first() }}</div></div>
@endif

<div class="stats-grid" style="margin-bottom: 1.5rem;">
    <div class="stat-card">
        <div class="stat-card-label">Documentos requeridos</div>
        <div class="stat-card-value">{{ count($required) - count($missing) }}/{{ count($required) }}</div>
        <div class="stat-card-sub">{{ $missing ? 'Falta: ' . collect($missing)->map(fn ($t) => EmployeeDocument::TYPES[$t] ?? $t)->implode(', ') : 'Completo' }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label">Datos de pago</div>
        <div class="stat-card-value"><span class="badge {{ $payBadge[$profile->payment_info_status ?? 'incomplete'] }}">{{ EmployeeProfile::PAYMENT_STATUSES[$profile->payment_info_status ?? 'incomplete'] }}</span></div>
        <div class="stat-card-sub">{{ $profile->payment_info_updated_at ? 'Actualizado ' . $profile->payment_info_updated_at->diffForHumans() : 'Sin registrar' }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label">Tallas</div>
        <div class="stat-card-value">{{ $employee->uniformSize?->isComplete() ? 'Completas' : 'Pendientes' }}</div>
        <div class="stat-card-sub">{{ $deliveries->count() }} entregas registradas</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label">Último acceso</div>
        <div class="stat-card-value" style="font-size: 1rem;">{{ $employee->last_login_at?->format('d/m/Y H:i') ?? 'Nunca' }}</div>
        <div class="stat-card-sub">{{ $employee->isLocked() ? 'Bloqueado hasta ' . $employee->locked_until->format('H:i') : ($employee->must_change_password ? 'Con contraseña temporal' : 'Acceso normal') }}</div>
    </div>
</div>

<div class="grid-2" style="align-items: start;">
    {{-- FICHA LABORAL --}}
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div class="card-title">Datos de pago, previsión y régimen</div>
            @can('profiles.view_sensitive')
                @unless ($revealed)
                    <form method="POST" action="{{ route('employees.file.reveal', $employee->id) }}">@csrf
                        <button class="btn btn-ghost btn-sm" title="Queda registrado en la bitácora">👁 Ver números completos</button>
                    </form>
                @endunless
            @endcan
        </div>
        <div class="card-body">
            <dl class="kv" style="margin-bottom: 1.25rem;">
                <dt>Banco / cuenta</dt><dd>{{ $profile->bank_name ?? '—' }} · {{ EmployeeProfile::BANK_ACCOUNT_TYPES[$profile->bank_account_type] ?? '' }} {{ $profile->bank_account_currency }}</dd>
                <dt>N° de cuenta</dt><dd class="mono">{{ $show($profile->bank_account_number) ?? '—' }}</dd>
                <dt>CCI / CLABE / CBU</dt><dd class="mono">{{ $show($profile->interbank_code) ?? '—' }}</dd>
                <dt>Previsión</dt><dd>{{ EmployeeProfile::PENSION_SYSTEMS[$profile->pension_system] ?? '—' }} {{ $profile->pension_fund }}</dd>
                <dt>CUSPP / NSS</dt><dd class="mono">{{ $show($profile->pension_code) ?? '—' }}</dd>
                <dt>Cuenta CTS</dt><dd class="mono">{{ $profile->cts_bank_name ?? '—' }} {{ $show($profile->cts_account_number) }}</dd>
                <dt>Domicilio</dt><dd>{{ $profile->address ?? '—' }} {{ $profile->district }}</dd>
                <dt>Emergencia</dt><dd>{{ $profile->emergency_contact_name ?? '—' }} {{ $profile->emergency_contact_phone }}</dd>
                @if ($profile->payment_verified_at)
                    <dt>Verificado por</dt><dd>{{ $profile->verifier?->full_name }} · {{ $profile->payment_verified_at->format('d/m/Y H:i') }}</dd>
                @endif
                @if ($profile->payment_rejection_reason)
                    <dt>Observación</dt><dd style="color: var(--danger);">{{ $profile->payment_rejection_reason }}</dd>
                @endif
            </dl>

            @if ($profile->exists && $profile->payment_info_status === 'pending')
                <form method="POST" action="{{ route('employees.file.payment-review', $employee->id) }}" style="display: flex; gap: 8px; margin-bottom: 1.25rem; flex-wrap: wrap;">
                    @csrf
                    <input type="text" name="reason" class="form-control" placeholder="Motivo si observas (ej. cuenta no coincide con el titular)" style="flex: 1; min-width: 200px;">
                    <button name="decision" value="verified" class="btn btn-success btn-sm">Verificar</button>
                    <button name="decision" value="rejected" class="btn btn-danger btn-sm">Observar</button>
                </form>
            @endif

            <details>
                <summary class="btn btn-ghost btn-sm">Editar ficha laboral</summary>
                <form method="POST" action="{{ route('employees.file.profile', $employee->id) }}" style="margin-top: 1rem;">
                    @csrf @method('PUT')
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Régimen laboral</label>
                            <select name="labor_regime" class="form-control">
                                <option value="">—</option>
                                @foreach (EmployeeProfile::LABOR_REGIMES as $k => $v)<option value="{{ $k }}" @selected($profile->labor_regime === $k)>{{ $v }}</option>@endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Seguro de salud</label>
                            <select name="health_insurance" class="form-control">
                                <option value="">—</option>
                                @foreach (EmployeeProfile::HEALTH_INSURANCES as $k => $v)<option value="{{ $k }}" @selected($profile->health_insurance === $k)>{{ $v }}</option>@endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Sistema previsional</label>
                            <select name="pension_system" class="form-control">
                                <option value="">—</option>
                                @foreach (EmployeeProfile::PENSION_SYSTEMS as $k => $v)<option value="{{ $k }}" @selected($profile->pension_system === $k)>{{ $v }}</option>@endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">AFP / fondo</label>
                            <input name="pension_fund" class="form-control" list="pensionFunds" value="{{ $profile->pension_fund }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Banco de abono</label>
                            <input name="bank_name" class="form-control" list="banks" value="{{ $profile->bank_name }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tipo de cuenta / moneda</label>
                            <div style="display: flex; gap: 6px;">
                                <select name="bank_account_type" class="form-control">
                                    <option value="">—</option>
                                    @foreach (EmployeeProfile::BANK_ACCOUNT_TYPES as $k => $v)<option value="{{ $k }}" @selected($profile->bank_account_type === $k)>{{ $v }}</option>@endforeach
                                </select>
                                <input name="bank_account_currency" class="form-control" maxlength="3" style="max-width: 80px;" value="{{ $profile->bank_account_currency ?? $employee->tenant?->currency }}">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">N° de cuenta (vacío = no cambiar)</label>
                            <input name="bank_account_number" class="form-control mono" inputmode="numeric" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label">CCI / CLABE / CBU (vacío = no cambiar)</label>
                            <input name="interbank_code" class="form-control mono" inputmode="numeric" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Banco CTS</label>
                            <input name="cts_bank_name" class="form-control" list="banks" value="{{ $profile->cts_bank_name }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cuenta CTS (vacío = no cambiar)</label>
                            <input name="cts_account_number" class="form-control mono" inputmode="numeric" autocomplete="off">
                        </div>
                    </div>
                    <datalist id="banks">@foreach (config('payroll.banks') as $b)<option value="{{ $b }}">@endforeach</datalist>
                    <datalist id="pensionFunds">@foreach (config('payroll.pension_funds') as $f)<option value="{{ $f }}">@endforeach</datalist>
                    <button class="btn btn-primary">Guardar ficha</button>
                </form>
            </details>
        </div>
    </div>

    {{-- DOCUMENTOS --}}
    <div class="card">
        <div class="card-header"><div class="card-title">Legajo digital</div></div>
        <div class="card-body">
            @forelse ($documents as $doc)
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border);">
                    <div>
                        <div style="color: #fff; font-weight: 600;">{{ EmployeeDocument::TYPES[$doc->type] ?? $doc->type }}</div>
                        <div class="text-muted" style="font-size: 0.78rem;">{{ $doc->original_name }} · {{ $doc->created_at->format('d/m/Y') }}
                            @if ($doc->expires_at) · vence {{ $doc->expires_at->format('d/m/Y') }} @endif</div>
                        @if ($doc->rejection_reason)<div style="font-size: 0.78rem; color: var(--danger);">{{ $doc->rejection_reason }}</div>@endif
                    </div>
                    <div style="display: flex; gap: 6px; align-items: center;">
                        <span class="badge {{ $docBadge[$doc->status] ?? 'badge-muted' }}">{{ EmployeeDocument::STATUSES[$doc->status] }}</span>
                        <a href="{{ route('documents.open', $doc->id) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Ver</a>
                        @if ($doc->status === 'pending')
                            <form method="POST" action="{{ route('documents.review', $doc->id) }}" class="inline-form">@csrf
                                <button name="decision" value="approved" class="btn btn-success btn-sm">✓</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-muted">Aún no hay documentos en el legajo.</p>
            @endforelse

            @can('documents.review')
            <form method="POST" action="{{ route('employees.file.upload', $employee->id) }}" enctype="multipart/form-data" style="margin-top: 1.25rem; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                @csrf
                <select name="type" class="form-control" required>
                    @foreach (EmployeeDocument::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
                <input type="date" name="expires_at" class="form-control" title="Vencimiento (opcional)">
                <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" class="form-control" required style="grid-column: span 2;">
                <button class="btn btn-primary" style="grid-column: span 2;">Subir al legajo (queda aprobado)</button>
            </form>
            @endcan
        </div>
    </div>
</div>

{{-- DOTACIÓN --}}
<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header"><div class="card-title">Dotación</div></div>
    <div class="card-body grid-2" style="align-items: start;">
        <div>
            <div class="size-chips" style="margin-bottom: 1rem;">
                <div class="size-chip"><strong>{{ $employee->uniformSize?->shirt_size ?? '—' }}</strong><span>Polo/camisa</span></div>
                <div class="size-chip"><strong>{{ $employee->uniformSize?->pants_size ?? '—' }}</strong><span>Pantalón</span></div>
                <div class="size-chip"><strong>{{ $employee->uniformSize?->shoe_size ?? '—' }}</strong><span>{{ UniformSize::SHOE_TYPES[$employee->uniformSize?->shoe_type] ?? 'Calzado' }}</span></div>
            </div>
            @if ($employee->uniformSize?->notes)<p class="text-muted">{{ $employee->uniformSize->notes }}</p>@endif
            @can('uniforms.manage')
            <form method="POST" action="{{ route('uniforms.deliveries.store') }}" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                @csrf
                <input type="hidden" name="user_id" value="{{ $employee->id }}">
                <select name="item" class="form-control">@foreach (UniformDelivery::ITEMS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                <input name="size" class="form-control" placeholder="Talla" maxlength="10">
                <input type="number" name="quantity" class="form-control" value="1" min="1" max="50">
                <input type="date" name="delivered_at" class="form-control" value="{{ now()->toDateString() }}" style="grid-column: span 2;">
                <button class="btn btn-primary">Registrar entrega</button>
            </form>
            @endcan
        </div>
        <div>
            @forelse ($deliveries as $d)
                <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 0.88rem;">
                    <span style="color: #fff;">{{ $d->quantity }}× {{ UniformDelivery::ITEMS[$d->item] ?? $d->item }} {{ $d->size }}</span>
                    <span class="text-muted">{{ $d->delivered_at->format('d/m/Y') }} · {{ $d->deliverer?->first_name }}</span>
                </div>
            @empty
                <p class="text-muted">Sin entregas registradas.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
