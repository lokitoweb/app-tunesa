<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de privacidad — {{ $appName }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    @include('layouts.partials.brand-head', ['brandSettings' => $settings])
    <style>
        {{-- Documento legal: página limpia (sin fondos del login), legible en el teléfono y en el escritorio --}}
        :root { --legal-text: #1f2937; --legal-muted: #6b7280; --legal-line: #e5e7eb; --legal-bg: #f6f7f9; --legal-surface: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --legal-text: #e5e7eb; --legal-muted: #9ca3af; --legal-line: #2b3340; --legal-bg: #0f141b; --legal-surface: #161d26; }
        }
        body { margin: 0; background: var(--legal-bg); color: var(--legal-text); font-family: 'Roboto', system-ui, sans-serif; -webkit-text-size-adjust: 100%; }
        .legal-top { position: sticky; top: 0; z-index: 10; background: var(--legal-surface); border-bottom: 1px solid var(--legal-line); }
        .legal-top-inner, .legal-main, .legal-foot { max-width: 820px; margin: 0 auto; padding-left: 20px; padding-right: 20px; }
        .legal-top-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; height: 64px; }
        .legal-brand { display: flex; align-items: center; gap: 10px; min-width: 0; color: inherit; text-decoration: none; }
        .legal-brand img { height: 36px; width: auto; max-width: 120px; object-fit: contain; }
        .legal-top a.legal-action { font-size: 0.85rem; color: var(--legal-muted); text-decoration: none; white-space: nowrap; }
        .legal-top a.legal-action:hover { color: var(--brand); }
        .legal-main { padding-top: 32px; padding-bottom: 32px; }
        .legal-eyebrow { display: inline-flex; align-items: center; gap: 6px; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--brand); }
        .legal-title { font-size: clamp(1.6rem, 1.3rem + 1.2vw, 2.1rem); font-weight: 700; letter-spacing: -0.02em; margin: 8px 0 6px; }
        .legal-meta { font-size: 0.9rem; color: var(--legal-muted); margin-bottom: 24px; }
        .legal-doc { background: var(--legal-surface); border: 1px solid var(--legal-line); border-radius: 14px; padding: 28px 32px; font-size: 1rem; line-height: 1.7; overflow-wrap: anywhere; }
        .legal-doc > :first-child { margin-top: 0; }
        .legal-doc h1, .legal-doc h2, .legal-doc h3 { font-size: 1.1rem; font-weight: 700; margin: 28px 0 10px; padding-top: 20px; border-top: 1px solid var(--legal-line); }
        .legal-doc ul { padding-left: 1.2rem; margin-bottom: 1rem; }
        .legal-doc li { margin-bottom: 6px; }
        .legal-doc li::marker { color: var(--brand); }
        .legal-doc a { color: var(--brand); }
        .legal-contact { margin-top: 20px; background: var(--legal-surface); border: 1px solid var(--legal-line); border-radius: 14px; padding: 20px 24px; }
        .legal-contact h2 { font-size: 1rem; font-weight: 700; margin: 0 0 8px; }
        .legal-contact ul { font-size: 0.95rem !important; }
        .legal-contact li { margin-bottom: 4px; }
        .legal-foot { padding-bottom: 32px; font-size: 0.8rem; color: var(--legal-muted); text-align: center; }
        @media (max-width: 575.98px) {
            .legal-top-inner, .legal-main, .legal-foot { padding-left: 16px; padding-right: 16px; }
            .legal-main { padding-top: 20px; }
            .legal-doc { padding: 20px 18px; font-size: 0.95rem; border-radius: 12px; }
            .legal-top a.legal-action span { display: none; }
        }
    </style>
</head>
<body>
    <header class="legal-top">
        <div class="legal-top-inner">
            <a class="legal-brand" href="{{ url('/') }}">
                @php($darkLogo = $settings->logoDarkUrl() ?: ($settings->usesDefaultLogo() ? asset(\App\Modules\Core\Models\TenantSetting::DEFAULT_LOGO_DARK) : null))
                <picture>
                    @if($darkLogo)<source srcset="{{ $darkLogo }}" media="(prefers-color-scheme: dark)">@endif
                    <img src="{{ $settings->displayLogoUrl() }}" alt="{{ $appName }}">
                </picture>
            </a>
            <a class="legal-action" href="{{ route('legal.account-deletion') }}"><i class="bi bi-person-x me-1"></i><span>Eliminar mi cuenta</span></a>
        </div>
    </header>

    <main class="legal-main">
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
    </main>

    <footer class="legal-foot">© {{ now()->year }} {{ $appName }}</footer>
</body>
</html>
