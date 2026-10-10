@extends('layouts.app')

@section('title', 'Legajo · ' . $employee->full_name)

@section('content')
@php
    use App\Modules\HR\Models\EmployeeDocument;
    use App\Modules\HR\Models\EmployeeProfile;
    use App\Modules\HR\Models\UniformDelivery;
    use App\Modules\HR\Models\UniformSize;

    $show = fn (?string $v) => $revealed ? $v : EmployeeProfile::mask($v);
    $payTone = ['incomplete' => 'secondary', 'pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger'];
    $payStatus = $profile->payment_info_status ?? 'incomplete';
    $approvedTypes = $documents->where('status', 'approved')->pluck('type')->all();
    $required = $config->requiredDocuments();
    $missing = array_diff($required, $approvedTypes);
    $sizes = $employee->uniformSize;
@endphp

<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Colaboradores</a></li>
        <li class="breadcrumb-item active" aria-current="page">Legajo</li>
    </ol>
</nav>
<div class="page-header">
    <div class="d-flex align-items-center gap-3">
        <span class="avatar avatar-lg">{{ mb_substr($employee->first_name, 0, 1) }}{{ mb_substr($employee->last_name, 0, 1) }}</span>
        <div>
            <h1>{{ $employee->full_name }}</h1>
            <p>{{ $employee->position ?: 'Sin cargo' }} · {{ $employee->employee_code }} · {{ label('id_document_type', $employee->document_type) }} {{ $employee->document_number }}
                · Ingreso {{ $employee->hire_date?->format('d/m/Y') ?? '—' }}</p>
        </div>
    </div>
    <a href="{{ route('employees.edit', $employee->id) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Editar datos básicos</a>
</div>

{{-- Resumen --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card border-0 h-100 kpi-card"><div class="card-body">
            <div class="kpi-label">Documentos requeridos</div>
            <div class="fs-4 fw-bold">{{ count($required) - count($missing) }}/{{ count($required) }}</div>
            <div class="small text-body-secondary">{{ $missing ? 'Falta: ' . collect($missing)->map(fn ($t) => EmployeeDocument::TYPES[$t] ?? $t)->implode(', ') : 'Completo' }}</div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 h-100 kpi-card"><div class="card-body">
            <div class="kpi-label">Datos de pago</div>
            <div class="my-1">@include('partials.badge', ['tone' => $payTone[$payStatus], 'label' => EmployeeProfile::PAYMENT_STATUSES[$payStatus]])</div>
            <div class="small text-body-secondary">{{ $profile->payment_info_updated_at ? 'Actualizado ' . $profile->payment_info_updated_at->diffForHumans() : 'Sin registrar' }}</div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 h-100 kpi-card"><div class="card-body">
            <div class="kpi-label">Tallas</div>
            <div class="fs-5 fw-bold">{{ $sizes?->isComplete() ? 'Completas' : 'Pendientes' }}</div>
            <div class="small text-body-secondary">{{ $deliveries->count() }} entregas registradas</div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 h-100 kpi-card"><div class="card-body">
            <div class="kpi-label">Último acceso</div>
            <div class="fs-6 fw-bold">{{ $employee->last_login_at?->format('d/m/Y H:i') ?? 'Nunca' }}</div>
            <div class="small {{ $employee->isLocked() ? 'text-danger' : 'text-body-secondary' }}">
                {{ $employee->isLocked() ? 'Bloqueado hasta ' . $employee->locked_until->format('H:i') : ($employee->must_change_password ? 'Con contraseña temporal' : 'Acceso normal') }}
            </div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    {{-- Ficha laboral --}}
    <div class="col-12 col-xl-6">
        <div class="card border-0 h-100">
            <div class="card-header border-0 pt-3 d-flex justify-content-between align-items-center gap-2">
                <h2 class="h6 fw-semibold mb-0">Pago, previsión y régimen</h2>
                @can('profiles.view_sensitive')
                    @unless($revealed)
                        <form method="POST" action="{{ route('employees.file.reveal', $employee->id) }}" class="m-0">@csrf
                            <button class="btn btn-sm btn-outline-secondary" title="Queda registrado en la bitácora de auditoría"><i class="bi bi-eye me-1"></i>Ver números completos</button>
                        </form>
                    @endunless
                @endcan
            </div>
            <div class="card-body">
                <dl class="kv small mb-3">
                    <dt>Banco / cuenta</dt><dd>{{ $profile->bank_name ?? '—' }} · {{ EmployeeProfile::BANK_ACCOUNT_TYPES[$profile->bank_account_type] ?? '' }} {{ $profile->bank_account_currency }}</dd>
                    <dt>N° de cuenta</dt><dd class="font-monospace">{{ $show($profile->bank_account_number) ?? '—' }}</dd>
                    <dt>CCI / CLABE / CBU</dt><dd class="font-monospace">{{ $show($profile->interbank_code) ?? '—' }}</dd>
                    <dt>Previsión</dt><dd>{{ EmployeeProfile::PENSION_SYSTEMS[$profile->pension_system] ?? '—' }} {{ $profile->pension_fund }}</dd>
                    <dt>CUSPP / NSS</dt><dd class="font-monospace">{{ $show($profile->pension_code) ?? '—' }}</dd>
                    <dt>Cuenta CTS</dt><dd class="font-monospace">{{ $profile->cts_bank_name ?? '—' }} {{ $show($profile->cts_account_number) }}</dd>
                    <dt>Domicilio</dt><dd>{{ $profile->address ?? '—' }} {{ $profile->district }}</dd>
                    <dt>Emergencia</dt><dd>{{ $profile->emergency_contact_name ?? '—' }} {{ $profile->emergency_contact_phone }}</dd>
                    @if($profile->payment_verified_at)
                        <dt>Verificado por</dt><dd>{{ $profile->verifier?->full_name }} · {{ $profile->payment_verified_at->format('d/m/Y H:i') }}</dd>
                    @endif
                    @if($profile->payment_rejection_reason)
                        <dt>Observación</dt><dd class="text-danger">{{ $profile->payment_rejection_reason }}</dd>
                    @endif
                </dl>

                @if($profile->exists && $profile->payment_info_status === 'pending')
                    <form method="POST" action="{{ route('employees.file.payment-review', $employee->id) }}" class="bg-body-tertiary rounded p-3 mb-3">
                        @csrf
                        <label for="pay_reason" class="form-label small fw-semibold">Revisar cuenta bancaria</label>
                        <div class="input-group">
                            <input type="text" id="pay_reason" name="reason" class="form-control" placeholder="Motivo si la observas (ej. no coincide con el titular)">
                            <button name="decision" value="verified" class="btn btn-success"><i class="bi bi-check-lg"></i> Verificar</button>
                            <button name="decision" value="rejected" class="btn btn-outline-danger">Observar</button>
                        </div>
                    </form>
                @endif

                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#profileForm" aria-expanded="false" aria-controls="profileForm">
                    <i class="bi bi-pencil-square me-1"></i>Editar ficha laboral
                </button>
                <div class="collapse mt-3" id="profileForm">
                    <form method="POST" action="{{ route('employees.file.profile', $employee->id) }}">
                        @csrf @method('PUT')
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label for="labor_regime" class="form-label small">Régimen laboral</label>
                                <select id="labor_regime" name="labor_regime" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach(EmployeeProfile::LABOR_REGIMES as $k => $v)<option value="{{ $k }}" @selected($profile->labor_regime === $k)>{{ $v }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="health_insurance" class="form-label small">Seguro de salud</label>
                                <select id="health_insurance" name="health_insurance" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach(EmployeeProfile::HEALTH_INSURANCES as $k => $v)<option value="{{ $k }}" @selected($profile->health_insurance === $k)>{{ $v }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="pension_system" class="form-label small">Sistema previsional</label>
                                <select id="pension_system" name="pension_system" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach(EmployeeProfile::PENSION_SYSTEMS as $k => $v)<option value="{{ $k }}" @selected($profile->pension_system === $k)>{{ $v }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="pension_fund" class="form-label small">AFP / fondo</label>
                                <input id="pension_fund" name="pension_fund" class="form-control form-control-sm" list="pensionFunds" value="{{ $profile->pension_fund }}">
                            </div>
                            <div class="col-sm-6">
                                <label for="bank_name" class="form-label small">Banco de abono</label>
                                <input id="bank_name" name="bank_name" class="form-control form-control-sm" list="banks" value="{{ $profile->bank_name }}">
                            </div>
                            <div class="col-sm-6">
                                <label for="bank_account_type" class="form-label small">Tipo de cuenta y moneda</label>
                                <div class="input-group input-group-sm">
                                    <select id="bank_account_type" name="bank_account_type" class="form-select">
                                        <option value="">—</option>
                                        @foreach(EmployeeProfile::BANK_ACCOUNT_TYPES as $k => $v)<option value="{{ $k }}" @selected($profile->bank_account_type === $k)>{{ $v }}</option>@endforeach
                                    </select>
                                    <input name="bank_account_currency" class="form-control" maxlength="3" style="max-width: 70px;" aria-label="Moneda" value="{{ $profile->bank_account_currency ?? $employee->tenant?->currency }}">
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <label for="bank_account_number" class="form-label small">N° de cuenta <span class="text-body-secondary">(vacío = no cambiar)</span></label>
                                <input id="bank_account_number" name="bank_account_number" class="form-control form-control-sm font-monospace" inputmode="numeric" autocomplete="off">
                            </div>
                            <div class="col-sm-6">
                                <label for="interbank_code" class="form-label small">CCI / CLABE / CBU <span class="text-body-secondary">(vacío = no cambiar)</span></label>
                                <input id="interbank_code" name="interbank_code" class="form-control form-control-sm font-monospace" inputmode="numeric" autocomplete="off">
                            </div>
                            <div class="col-sm-6">
                                <label for="cts_bank_name" class="form-label small">Banco CTS</label>
                                <input id="cts_bank_name" name="cts_bank_name" class="form-control form-control-sm" list="banks" value="{{ $profile->cts_bank_name }}">
                            </div>
                            <div class="col-sm-6">
                                <label for="cts_account_number" class="form-label small">Cuenta CTS <span class="text-body-secondary">(vacío = no cambiar)</span></label>
                                <input id="cts_account_number" name="cts_account_number" class="form-control form-control-sm font-monospace" inputmode="numeric" autocomplete="off">
                            </div>
                        </div>
                        <datalist id="banks">@foreach(config('payroll.banks') as $b)<option value="{{ $b }}">@endforeach</datalist>
                        <datalist id="pensionFunds">@foreach(config('payroll.pension_funds') as $f)<option value="{{ $f }}">@endforeach</datalist>
                        <button class="btn btn-primary btn-sm mt-3">Guardar ficha</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Documentos --}}
    <div class="col-12 col-xl-6">
        <div class="card border-0 h-100">
            <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Legajo digital</h2></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Documento</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                    <tbody>
                        @forelse($documents as $doc)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ label('document_type', $doc->type) }}</div>
                                    <div class="small text-body-secondary text-break">{{ $doc->original_name }} · {{ $doc->created_at->format('d/m/Y') }}@if($doc->expires_at) · vence {{ $doc->expires_at->format('d/m/Y') }}@endif</div>
                                    @if($doc->rejection_reason)<div class="small text-danger">{{ $doc->rejection_reason }}</div>@endif
                                </td>
                                <td><x-status-badge type="document_status" :value="$doc->status" /></td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('documents.open', $doc->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" aria-label="Ver documento"><i class="bi bi-eye"></i></a>
                                    @if($doc->status === 'pending')
                                        <form method="POST" action="{{ route('documents.review', $doc->id) }}" class="d-inline">@csrf
                                            <button name="decision" value="approved" class="btn btn-sm btn-success" aria-label="Aprobar documento"><i class="bi bi-check-lg"></i></button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><div class="empty-state"><i class="bi bi-folder2"></i>Aún no hay documentos en el legajo.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @can('documents.review')
                <div class="card-footer bg-transparent">
                    <form method="POST" action="{{ route('employees.file.upload', $employee->id) }}" enctype="multipart/form-data" class="row g-2">
                        @csrf
                        <div class="col-sm-6">
                            <label for="doc_type" class="form-label small">Tipo</label>
                            <select id="doc_type" name="type" class="form-select form-select-sm" required>
                                @foreach(EmployeeDocument::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label for="doc_expires" class="form-label small">Vencimiento (opcional)</label>
                            <input type="date" id="doc_expires" name="expires_at" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label for="doc_file" class="form-label small">Archivo (PDF, JPG o PNG)</label>
                            <input type="file" id="doc_file" name="file" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-12 d-grid">
                            <button class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Subir al legajo (queda aprobado)</button>
                        </div>
                    </form>
                </div>
            @endcan
        </div>
    </div>
</div>

{{-- Dotación --}}
<div class="card border-0">
    <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Dotación</h2></div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-12 col-lg-6">
                <div class="row g-2 mb-3 text-center">
                    @foreach([['Polo / camisa', $sizes?->shirt_size], ['Pantalón', $sizes?->pants_size], [UniformSize::SHOE_TYPES[$sizes?->shoe_type] ?? 'Calzado', $sizes?->shoe_size]] as [$label, $value])
                        <div class="col-4">
                            <div class="border rounded p-2">
                                <div class="fs-5 fw-bold">{{ $value ?? '—' }}</div>
                                <div class="small text-body-secondary">{{ $label }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if($sizes?->notes)<p class="small text-body-secondary">{{ $sizes->notes }}</p>@endif
                @can('uniforms.manage')
                    <form method="POST" action="{{ route('uniforms.deliveries.store') }}" class="row g-2">
                        @csrf
                        <input type="hidden" name="user_id" value="{{ $employee->id }}">
                        <div class="col-sm-5">
                            <label for="item" class="form-label small">Prenda</label>
                            <select id="item" name="item" class="form-select form-select-sm">@foreach(UniformDelivery::ITEMS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                        </div>
                        <div class="col-6 col-sm-4">
                            <label for="size" class="form-label small">Talla</label>
                            <input id="size" name="size" class="form-control form-control-sm" maxlength="10">
                        </div>
                        <div class="col-6 col-sm-3">
                            <label for="quantity" class="form-label small">Cantidad</label>
                            <input type="number" id="quantity" name="quantity" class="form-control form-control-sm" value="1" min="1" max="50">
                        </div>
                        <div class="col-sm-6">
                            <label for="delivered_at" class="form-label small">Fecha de entrega</label>
                            <input type="date" id="delivered_at" name="delivered_at" class="form-control form-control-sm" value="{{ now()->toDateString() }}">
                        </div>
                        <div class="col-sm-6 d-grid align-items-end">
                            <button class="btn btn-primary btn-sm mt-sm-4">Registrar entrega</button>
                        </div>
                    </form>
                @endcan
            </div>
            <div class="col-12 col-lg-6">
                <h3 class="small fw-semibold text-body-secondary text-uppercase">Historial de entregas</h3>
                <ul class="list-group list-group-flush">
                    @forelse($deliveries as $d)
                        <li class="list-group-item px-0 d-flex justify-content-between small">
                            <span>{{ $d->quantity }} × {{ label('uniform_item', $d->item) }} {{ $d->size }}</span>
                            <span class="text-body-secondary">{{ $d->delivered_at->format('d/m/Y') }} · {{ $d->deliverer?->first_name }}</span>
                        </li>
                    @empty
                        <li class="list-group-item px-0 small text-body-secondary">Sin entregas registradas.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
