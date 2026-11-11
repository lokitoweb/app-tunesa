@extends('legal.layout')

@section('title', 'Política de privacidad')

@section('action')
    <a class="legal-action" href="{{ route('legal.account-deletion') }}"><i class="bi bi-person-x me-1"></i><span>Eliminar mi cuenta</span></a>
@endsection

@section('content')
        <span class="legal-eyebrow"><i class="bi bi-shield-lock"></i>Privacidad</span>
        <h1 class="legal-title">Política de privacidad</h1>
        <p class="legal-meta">{{ $appName }} · Última actualización: {{ $policy->updatedOn()->format('d/m/Y') }}</p>

        <article class="legal-doc">
            {!! $policy->html() !!}
        </article>

        <section class="legal-contact" aria-labelledby="legal-contact-title">
            <h2 id="legal-contact-title">Contacto sobre privacidad</h2>
            @include('legal._contact')
        </section>
@endsection
