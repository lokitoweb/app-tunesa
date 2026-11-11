@extends('legal.layout')

@section('title', 'Eliminar mi cuenta')

@section('action')
    <a class="legal-action" href="{{ route('legal.privacy') }}"><i class="bi bi-shield-lock me-1"></i><span>Política de privacidad</span></a>
@endsection

@push('legal-styles')
        .legal-form label { font-size: 0.9rem; font-weight: 600; margin-bottom: 6px; }
        .legal-form .form-control { border-radius: 10px; padding: 0.7rem 0.85rem; background: var(--legal-bg); border-color: var(--legal-line); color: var(--legal-text); }
        .legal-form .form-control:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(var(--brand-rgb), .15); }
        .legal-steps { list-style: none; padding: 0; margin: 0 0 24px; display: grid; gap: 10px; }
        .legal-steps li { display: flex; gap: 10px; align-items: flex-start; font-size: 0.95rem; }
        .legal-steps i { color: var(--brand); margin-top: 2px; }
        .legal-submit { width: 100%; min-height: 48px; border-radius: 12px; font-weight: 600; }
@endpush

@section('content')
        <span class="legal-eyebrow"><i class="bi bi-person-x"></i>Tus datos</span>
        <h1 class="legal-title">Eliminar mi cuenta</h1>
        <p class="legal-meta">{{ $appName }}</p>

        <article class="legal-doc">
            <ul class="legal-steps">
                <li><i class="bi bi-1-circle"></i><span>Tu cuenta la creó tu empleador. Al pedir la eliminación, RR. HH. de tu empresa la revisa.</span></li>
                <li><i class="bi bi-2-circle"></i><span>En un máximo de 30 días se borran tu cuenta, tus fotos, tus documentos y tus datos de contacto y de pago.</span></li>
                <li><i class="bi bi-3-circle"></i><span>Por ley se conservan las planillas, boletas y registros de asistencia el tiempo que exige la normativa laboral; después se eliminan.</span></li>
            </ul>
            <p class="legal-meta mb-4">También puedes pedirlo desde la app: <strong>Mi perfil › Privacidad y datos › Eliminar mi cuenta</strong>.</p>

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

            <form method="POST" action="{{ route('legal.account-deletion.store') }}" class="legal-form" novalidate>
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
                <button type="submit" class="btn btn-danger legal-submit">Pedir eliminación</button>
            </form>
        </article>

        <section class="legal-contact" aria-labelledby="legal-contact-title">
            <h2 id="legal-contact-title">¿Dudas? Escríbenos</h2>
            @include('legal._contact')
        </section>
@endsection
