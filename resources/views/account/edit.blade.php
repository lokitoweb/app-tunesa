@extends('layouts.app')

@section('title', 'Mi cuenta')

@section('content')
<div class="page-header">
    <div>
        <h1>Mi cuenta</h1>
        <p>Tus datos de acceso al panel y tu contraseña.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <span class="avatar avatar-lg">{{ strtoupper(mb_substr($user->first_name ?? 'U', 0, 1) . mb_substr($user->last_name ?? '', 0, 1)) }}</span>
                    <div class="min-w-0">
                        <div class="fw-bold fs-5 text-truncate">{{ $user->first_name }} {{ $user->last_name }}</div>
                        <div class="text-body-secondary small">{{ label('user_role', $user->role) }}</div>
                    </div>
                </div>
                <dl class="kv">
                    <dt>Correo</dt>
                    <dd>{{ $user->email }}</dd>
                    <dt>Empresa</dt>
                    <dd>{{ $user->tenant?->company_name ?? 'Plataforma' }}</dd>
                    @if($user->position)
                        <dt>Cargo</dt>
                        <dd>{{ $user->position }}</dd>
                    @endif
                    <dt>Último acceso</dt>
                    <dd>{{ $user->last_login_at?->format('d/m/Y h:i a') ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-1">Cambiar contraseña</h2>
                <p class="small text-body-secondary mb-4">Mínimo 8 caracteres, con mayúsculas, minúsculas y números. Al cambiarla se cierra tu sesión en la app de los celulares.</p>

                <form method="POST" action="{{ route('account.password') }}" novalidate>
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Contraseña actual</label>
                        <input type="password" id="current_password" name="current_password" class="form-control @error('current_password') is-invalid @enderror" required autocomplete="current-password">
                        @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <label class="form-label" for="password">Nueva contraseña</label>
                            <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password">
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="password_confirmation">Repite la nueva contraseña</label>
                            <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" required autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-shield-lock me-1"></i>Actualizar contraseña</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
