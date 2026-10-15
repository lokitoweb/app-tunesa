@php
    $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($tenant);
    $old = $old ?? [];
@endphp
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Canal ético — {{ $brandSettings->tradeName() }}</title>
    @include('layouts.partials.theme-boot')
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    @include('layouts.partials.brand-head', ['brandSettings' => $brandSettings])
    <style>
        body { background: var(--bs-tertiary-bg); }
        .ethics-wrap { max-width: 760px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        .ethics-secret { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1.6rem; font-weight: 700; letter-spacing: .08em; }
    </style>
</head>
<body>
<main class="ethics-wrap">
    <header class="d-flex align-items-center gap-3 mb-4">
        @if($logo = $brandSettings->logoUrl())
            <img src="{{ $logo }}" alt="" style="max-height: 40px; max-width: 160px;">
        @endif
        <div>
            <h1 class="h4 mb-0">Canal ético</h1>
            <div class="text-body-secondary small">{{ $brandSettings->tradeName() }}</div>
        </div>
    </header>

    @if($screen === 'created')
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-4">
                <i class="bi bi-shield-check text-success" style="font-size: 3rem;"></i>
                <h2 class="h5 mt-2">Recibimos tu denuncia</h2>
                <p class="text-body-secondary">Anota el código y la clave. Son la única forma de seguir tu denuncia y no los volveremos a mostrar.</p>
                <div class="row g-3 justify-content-center my-2">
                    <div class="col-12 col-sm-5"><div class="small text-body-secondary">Código de seguimiento</div><div class="ethics-secret" id="created-code">{{ $code }}</div></div>
                    <div class="col-12 col-sm-5"><div class="small text-body-secondary">Clave</div><div class="ethics-secret" id="created-token">{{ $token }}</div></div>
                </div>
                <p class="small text-body-secondary mb-3">Vuelve a esta misma página y usa «Seguir una denuncia» para ver las respuestas.</p>
                <a href="{{ route('ethics.public', $tenant->slug) }}" class="btn btn-outline-secondary">Listo</a>
            </div>
        </div>
    @elseif($screen === 'case')
        @if($notice)<div class="alert alert-success">{{ $notice }}</div>@endif
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="small text-body-secondary">Denuncia <span class="font-monospace">{{ $case->public_code }}</span> · {{ $case->categoryLabel() }} · {{ $case->reported_on->format('d/m/Y') }}</div>
                        <h2 class="h5 mt-1">{{ $case->subject }}</h2>
                    </div>
                    <span class="badge rounded-pill text-bg-{{ $case->isClosed() ? 'secondary' : ($case->status === 'needs_info' ? 'warning' : 'primary') }}">{{ $case->statusLabel() }}</span>
                </div>
                <p class="mb-0" style="white-space: pre-line">{{ $case->body }}</p>
                @if($case->isClosed() && $case->resolution)
                    <div class="alert alert-success mt-3 mb-0"><strong>Conclusión:</strong> {{ $case->resolution }}</div>
                @endif
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold">Conversación</div>
            <ul class="list-group list-group-flush">
                @forelse($case->messages as $m)
                    <li class="list-group-item {{ $m->fromReporter() ? '' : 'bg-body-tertiary' }}">
                        <div class="small text-body-secondary mb-1">{{ $m->fromReporter() ? 'Tú' : 'Equipo de investigación' }} · {{ $m->sent_on->format('d/m/Y') }}@if($m->attachments->count()) · {{ $m->attachments->count() }} foto(s)@endif</div>
                        <div style="white-space: pre-line">{{ $m->body }}</div>
                    </li>
                @empty
                    <li class="list-group-item text-body-secondary small">Aún no hay respuestas. Vuelve a consultar más adelante.</li>
                @endforelse
            </ul>
            @unless($case->isClosed())
                <div class="card-body border-top">
                    <form method="POST" action="{{ route('ethics.public.message', $tenant->slug) }}" enctype="multipart/form-data">
                        <input type="hidden" name="code" value="{{ $case->public_code }}">
                        <input type="hidden" name="token" value="{{ $token }}">
                        <label for="body" class="form-label small">Agregar información</label>
                        <textarea id="body" name="body" rows="3" maxlength="5000" required class="form-control @error('body') is-invalid @enderror"></textarea>
                        @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <input type="file" name="photos[]" accept="image/*" multiple class="form-control form-control-sm mt-2" aria-label="Adjuntar fotos (hasta 3)">
                        <button type="submit" class="btn btn-primary mt-2">Enviar</button>
                    </form>
                </div>
            @endunless
        </div>
        <div class="mt-3"><a href="{{ route('ethics.public', $tenant->slug) }}" class="small">Salir</a></div>
    @else
        <div class="alert alert-secondary small"><i class="bi bi-incognito me-1"></i>Esta página no te pide iniciar sesión ni guarda tu IP, tu navegador ni cookies. No necesitas decir quién eres.</div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Hacer una denuncia</h2>
                <form method="POST" action="{{ route('ethics.public.store', $tenant->slug) }}" enctype="multipart/form-data" novalidate>
                    <div class="mb-3">
                        <label for="category" class="form-label">Tipo de denuncia</label>
                        <select id="category" name="category" required class="form-select @error('category') is-invalid @enderror">
                            <option value="">Elige el tipo</option>
                            @foreach($categories as $key => $label)
                                <option value="{{ $key }}" @selected(($old['category'] ?? null) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="subject" class="form-label">Asunto</label>
                        <input type="text" id="subject" name="subject" maxlength="150" required value="{{ $old['subject'] ?? '' }}" placeholder="En pocas palabras, qué pasó" class="form-control @error('subject') is-invalid @enderror">
                        @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="body_new" class="form-label">Detalle</label>
                        <textarea id="body_new" name="body" rows="6" maxlength="5000" required placeholder="Qué ocurrió, cuándo, dónde y quiénes participaron." class="form-control @error('body') is-invalid @enderror">{{ $old['body'] ?? '' }}</textarea>
                        @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="photos" class="form-label">Fotos o capturas (opcional, hasta 3)</label>
                        <input type="file" id="photos" name="photos[]" accept="image/*" multiple class="form-control @error('photos') is-invalid @enderror @error('photos.*') is-invalid @enderror">
                        <div class="form-text">Les quitamos los datos ocultos (ubicación, modelo del celular, fecha).</div>
                        @error('photos')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @error('photos.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <p class="small text-body-secondary">Envía solo hechos que conozcas. Las denuncias falsas a sabiendas pueden tener consecuencias.</p>
                    <button type="submit" class="btn btn-primary">Enviar denuncia</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h5">Seguir una denuncia</h2>
                <form method="POST" action="{{ route('ethics.public.follow', $tenant->slug) }}" class="row g-2 align-items-end" novalidate>
                    <div class="col-12 col-sm-4">
                        <label for="code" class="form-label small">Código</label>
                        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="off" required value="{{ $followOld['code'] ?? '' }}" placeholder="123456" class="form-control font-monospace @error('code') is-invalid @enderror">
                    </div>
                    <div class="col-12 col-sm-5">
                        <label for="token" class="form-label small">Clave</label>
                        <input type="password" id="token" name="token" autocomplete="off" required placeholder="XXXX-XXXX" class="form-control font-monospace @error('code') is-invalid @enderror">
                    </div>
                    <div class="col-12 col-sm-3 d-grid"><button type="submit" class="btn btn-outline-primary">Consultar</button></div>
                    @error('code')<div class="col-12"><div class="text-danger small">{{ $message }}</div></div>@enderror
                </form>
            </div>
        </div>
    @endif
</main>
</body>
</html>
