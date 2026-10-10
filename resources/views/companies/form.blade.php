@extends('layouts.app')

@section('title', $isEdit ? 'Editar empresa' : 'Nueva empresa')

@section('content')
@php
    use App\Modules\Core\Controllers\Web\CompanyWebController as C;
    $timezones = \DateTimeZone::listIdentifiers();
@endphp

<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('companies.index') }}">Empresas</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $isEdit ? 'Editar' : 'Nueva' }}</li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1>{{ $isEdit ? 'Editar empresa: ' . $company->company_name : 'Nueva empresa' }}</h1>
        <p>{{ $isEdit ? 'Datos, plan, estado y marca blanca de la empresa.' : 'Crea la empresa y su primer administrador, que ingresará con una contraseña temporal.' }}</p>
    </div>
</div>

<form method="POST" action="{{ $isEdit ? route('companies.update', $company->id) : route('companies.store') }}" enctype="multipart/form-data">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <div class="card border-0 h-100">
                <div class="card-header fw-semibold">Datos de la empresa</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="company_name" class="form-label">Nombre comercial <span class="text-danger">*</span></label>
                        <input type="text" id="company_name" name="company_name" value="{{ old('company_name', $company->company_name) }}" required maxlength="200" class="form-control @error('company_name') is-invalid @enderror">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="slug" class="form-label">Subdominio <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" id="slug" name="slug" value="{{ old('slug', $company->slug) }}" required maxlength="63" pattern="[a-z0-9]+(-[a-z0-9]+)*" class="form-control @error('slug') is-invalid @enderror" placeholder="empresa-demo">
                                <span class="input-group-text small">.{{ config('app.domain', 'tunesa.app') }}</span>
                            </div>
                            <div class="form-text">Minúsculas, números y guiones. La app lo usa para identificar a la empresa.</div>
                        </div>
                        <div class="col-sm-6">
                            <label for="custom_domain" class="form-label">Dominio propio</label>
                            <input type="text" id="custom_domain" name="custom_domain" value="{{ old('custom_domain', $company->custom_domain) }}" maxlength="255" class="form-control @error('custom_domain') is-invalid @enderror" placeholder="asistencia.empresa.com">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="email" class="form-label">Correo de la empresa <span class="text-danger">*</span></label>
                            <input type="email" id="email" name="email" value="{{ old('email', $company->email) }}" required class="form-control @error('email') is-invalid @enderror">
                        </div>
                        <div class="col-sm-3">
                            <label for="ruc" class="form-label">RUC</label>
                            <input type="text" id="ruc" name="ruc" value="{{ old('ruc', $company->ruc) }}" maxlength="20" class="form-control @error('ruc') is-invalid @enderror">
                        </div>
                        <div class="col-sm-3">
                            <label for="phone" class="form-label">Teléfono</label>
                            <input type="text" id="phone" name="phone" value="{{ old('phone', $company->phone) }}" maxlength="20" class="form-control @error('phone') is-invalid @enderror">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Dirección</label>
                        <input type="text" id="address" name="address" value="{{ old('address', $company->address) }}" maxlength="500" class="form-control @error('address') is-invalid @enderror">
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-4">
                            <label for="plan" class="form-label">Plan</label>
                            <select id="plan" name="plan" class="form-select @error('plan') is-invalid @enderror">
                                @foreach(C::PLANS as $value => $label)
                                    <option value="{{ $value }}" @selected(old('plan', $company->plan) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label for="status" class="form-label">Estado</label>
                            <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                                @foreach(C::STATUSES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('status', $company->status) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Suspendida: nadie de la empresa entra al panel ni a la app.</div>
                        </div>
                        <div class="col-sm-4">
                            <label for="timezone" class="form-label">Zona horaria</label>
                            <select id="timezone" name="timezone" class="form-select @error('timezone') is-invalid @enderror">
                                @foreach($timezones as $tz)
                                    <option value="{{ $tz }}" @selected(old('timezone', $company->timezone) === $tz)>{{ $tz }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5 d-flex flex-column gap-3">
            <div class="card border-0">
                <div class="card-header fw-semibold">Marca blanca</div>
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        @if($company->logo_path)
                            <img src="{{ asset('storage/' . $company->logo_path) }}" alt="Logo actual" style="max-height: 48px; max-width: 120px;">
                        @endif
                        <div class="flex-grow-1">
                            <label for="logo" class="form-label">Logo</label>
                            <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp" class="form-control @error('logo') is-invalid @enderror">
                            <div class="form-text">PNG, JPG o WebP, máximo 2 MB.</div>
                        </div>
                    </div>
                    @if($company->logo_path)
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="remove_logo">
                            <label class="form-check-label small" for="remove_logo">Quitar el logo actual</label>
                        </div>
                    @endif
                    <div class="row g-3">
                        <div class="col-6">
                            <label for="primary_color" class="form-label">Color primario</label>
                            <input type="color" id="primary_color" name="primary_color" value="{{ old('primary_color', $company->primary_color ?? '#1E40AF') }}" class="form-control form-control-color w-100 @error('primary_color') is-invalid @enderror">
                        </div>
                        <div class="col-6">
                            <label for="secondary_color" class="form-label">Color secundario</label>
                            <input type="color" id="secondary_color" name="secondary_color" value="{{ old('secondary_color', $company->secondary_color ?? '#F59E0B') }}" class="form-control form-control-color w-100 @error('secondary_color') is-invalid @enderror">
                        </div>
                    </div>
                </div>
            </div>

            @if(! $isEdit)
                <div class="card border-0">
                    <div class="card-header fw-semibold">Primer administrador de la empresa</div>
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label for="admin_first_name" class="form-label">Nombres <span class="text-danger">*</span></label>
                                <input type="text" id="admin_first_name" name="admin_first_name" value="{{ old('admin_first_name') }}" required maxlength="100" class="form-control @error('admin_first_name') is-invalid @enderror">
                            </div>
                            <div class="col-sm-6">
                                <label for="admin_last_name" class="form-label">Apellidos <span class="text-danger">*</span></label>
                                <input type="text" id="admin_last_name" name="admin_last_name" value="{{ old('admin_last_name') }}" required maxlength="100" class="form-control @error('admin_last_name') is-invalid @enderror">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="admin_email" class="form-label">Correo <span class="text-danger">*</span></label>
                            <input type="email" id="admin_email" name="admin_email" value="{{ old('admin_email') }}" required class="form-control @error('admin_email') is-invalid @enderror">
                        </div>
                        <div>
                            <label for="admin_password" class="form-label">Contraseña temporal <span class="text-danger">*</span></label>
                            <input type="text" id="admin_password" name="admin_password" required minlength="8" autocomplete="off" class="form-control @error('admin_password') is-invalid @enderror">
                            <div class="form-text">Mínimo 8 caracteres. Se le pedirá cambiarla en su primer ingreso.</div>
                        </div>
                    </div>
                </div>
            @else
                <div class="card border-0">
                    <div class="card-header fw-semibold">Administradores</div>
                    <ul class="list-group list-group-flush">
                        @forelse($admins as $a)
                            <li class="list-group-item d-flex justify-content-between align-items-center small">
                                <span>{{ $a->full_name }}<span class="d-block text-body-secondary">{{ $a->email }}</span></span>
                                <x-status-badge type="user_status" :value="$a->status" />
                            </li>
                        @empty
                            <li class="list-group-item small text-body-secondary">Sin administradores.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3">
        <a href="{{ route('companies.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>{{ $isEdit ? 'Guardar cambios' : 'Crear empresa' }}</button>
    </div>
</form>
@endsection
