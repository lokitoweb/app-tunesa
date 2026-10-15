@extends('layouts.guest')

@section('title', 'Eliminar mi cuenta')

@section('wide', '1')

@section('content')
<div class="gn-legal">
    @include('auth._brand')

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h1 class="h5 fw-semibold mb-1">Eliminar mi cuenta de {{ $appName }}</h1>
            <p class="small text-body-secondary">Tu cuenta la creó tu empleador. Cuando la pides eliminar, RRHH de tu
                empresa borra tu cuenta, tus fotos, tus documentos y tus datos de contacto y de pago en un máximo de
                30 días. Por ley se conservan las planillas, boletas y registros de asistencia el tiempo que exige la
                normativa laboral; después se eliminan.</p>
            <p class="small text-body-secondary">También puedes pedirlo desde la app: <strong>Mi perfil › Privacidad y datos › Eliminar mi cuenta</strong>.</p>

            @if(session('status'))
                <div class="alert alert-success small" role="status"><i class="bi bi-check-circle me-1"></i>{{ session('status') }}</div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger small" role="alert">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('legal.account-deletion.store') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="email">Correo con el que ingresas a la app</label>
                    <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                           placeholder="tucorreo@empresa.com" value="{{ old('email') }}" required autocomplete="email">
                </div>
                <div class="mb-4">
                    <label class="form-label" for="reason">Motivo (opcional)</label>
                    <textarea id="reason" name="reason" rows="3" maxlength="1000" class="form-control">{{ old('reason') }}</textarea>
                </div>
                <button type="submit" class="btn btn-danger w-100 py-2 fw-semibold">Pedir eliminación</button>
            </form>
        </div>
    </div>

    <p class="text-center small mt-3 mb-0">
        <a href="{{ route('legal.privacy') }}" class="text-decoration-none">Política de privacidad</a>
    </p>
</div>
@endsection
