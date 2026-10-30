@php
    $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($tenant);
    $old = $old ?? [];
    $followOld = $followOld ?? [];
@endphp
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Canal Ético Confidencial — {{ $brandSettings->tradeName() }}</title>
    @include('layouts.partials.theme-boot')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    @include('layouts.partials.brand-head', ['brandSettings' => $brandSettings])
    <style>
        :root {
            --brand-primary: #0284c7;
            --brand-primary-rgb: 2, 132, 199;
            --font-main: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --font-mono: 'JetBrains Mono', ui-monospace, monospace;
        }
        body {
            font-family: var(--font-main);
            background: #f8fafc;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        [data-bs-theme="dark"] body {
            background: #090d16;
            color: #f1f5f9;
        }
        .public-ethics-container {
            max-width: 820px;
            margin: 0 auto;
            width: 100%;
            padding: 2rem 1.25rem 4rem;
        }
        .portal-header-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.75rem 2rem;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.75rem;
        }
        [data-bs-theme="dark"] .portal-header-card {
            background: #111827;
            border-color: #1f2937;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.4);
        }
        .portal-shield-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: rgba(var(--brand-rgb), 0.1);
            color: var(--brand-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.65rem;
            flex-shrink: 0;
            border: 1px solid rgba(var(--brand-rgb), 0.2);
        }
        .guarantee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 0.85rem;
            margin-bottom: 1.75rem;
        }
        .guarantee-chip {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.8rem;
        }
        [data-bs-theme="dark"] .guarantee-chip {
            background: #111827;
            border-color: #1f2937;
        }
        .guarantee-chip-icon {
            font-size: 1.15rem;
            color: #10b981;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .portal-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
        }
        [data-bs-theme="dark"] .portal-card {
            background: #111827;
            border-color: #1f2937;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.4);
        }
        .portal-nav-pills {
            display: inline-flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 1.75rem;
            width: 100%;
        }
        [data-bs-theme="dark"] .portal-nav-pills {
            background: #0f172a;
            border-color: #1e293b;
        }
        .portal-nav-btn {
            flex: 1;
            text-align: center;
            padding: 0.65rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 9px;
            border: none;
            background: transparent;
            color: #64748b;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .portal-nav-btn.active {
            background: #ffffff;
            color: var(--brand-primary);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        }
        [data-bs-theme="dark"] .portal-nav-btn.active {
            background: #1e293b;
            color: #38bdf8;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        .ethics-secret-box {
            font-family: var(--font-mono);
            font-size: 1.65rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            text-align: center;
            color: #0f172a;
            user-select: all;
        }
        [data-bs-theme="dark"] .ethics-secret-box {
            background: #0b0f19;
            border-color: #334155;
            color: #f8fafc;
        }
        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.35rem;
        }
        [data-bs-theme="dark"] .form-label {
            color: #cbd5e1;
        }
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            padding: 0.65rem 0.95rem;
            font-size: 0.9rem;
            background: #ffffff;
            color: #0f172a;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        [data-bs-theme="dark"] .form-control, [data-bs-theme="dark"] .form-select {
            background: #0b0f19;
            border-color: #334155;
            color: #f1f5f9;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 3px rgba(var(--brand-rgb), 0.15);
        }
        .portal-btn-primary {
            background: var(--brand-primary);
            border: none;
            color: #ffffff;
            font-weight: 700;
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-size: 0.925rem;
            transition: all 0.15s ease;
            box-shadow: 0 2px 8px rgba(var(--brand-rgb), 0.3);
        }
        .portal-btn-primary:hover {
            background: #0369a1;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(var(--brand-rgb), 0.4);
        }
        .chat-bubble-reporter {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            border-top-left-radius: 2px;
            padding: 1rem 1.25rem;
            color: #0f172a;
        }
        [data-bs-theme="dark"] .chat-bubble-reporter {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }
        .chat-bubble-investigator {
            background: rgba(var(--brand-rgb), 0.08);
            border: 1px solid rgba(var(--brand-rgb), 0.25);
            border-radius: 14px;
            border-top-right-radius: 2px;
            padding: 1rem 1.25rem;
            color: #0f172a;
        }
        [data-bs-theme="dark"] .chat-bubble-investigator {
            background: rgba(var(--brand-rgb), 0.15);
            border-color: rgba(56, 189, 248, 0.3);
            color: #f1f5f9;
        }
    </style>
</head>
<body>

<main class="public-ethics-container">
    {{-- Header del Portal Corporativo --}}
    <header class="portal-header-card">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="portal-shield-icon">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <div>
                    <h1 class="h4 fw-bold mb-0 text-body">Canal Ético & Confidencial</h1>
                    <div class="text-secondary small fw-medium mt-0.5">
                        <i class="bi bi-building me-1"></i>{{ $brandSettings->tradeName() }}
                    </div>
                </div>
            </div>
            @if($logo = $brandSettings->displayLogoUrl())
                <div class="d-none d-sm-block">
                    <img src="{{ $logo }}" alt="{{ $brandSettings->tradeName() }}" style="max-height: 42px; max-width: 170px; object-fit: contain;">
                </div>
            @endif
        </div>
    </header>

    {{-- Garantías de Máxima Privacidad --}}
    <div class="guarantee-grid">
        <div class="guarantee-chip">
            <i class="bi bi-incognito guarantee-chip-icon"></i>
            <div>
                <strong class="text-body d-block">100% Anónimo</strong>
                <span class="text-secondary">No registramos tu dirección IP, tu navegador ni tu ubicación.</span>
            </div>
        </div>
        <div class="guarantee-chip">
            <i class="bi bi-shield-check guarantee-chip-icon"></i>
            <div>
                <strong class="text-body d-block">Cifrado de Extremo a Extremo</strong>
                <span class="text-secondary">Los hechos y mensajes se guardan cifrados bajo estrictos estándares.</span>
            </div>
        </div>
        <div class="guarantee-chip">
            <i class="bi bi-cookie guarantee-chip-icon"></i>
            <div>
                <strong class="text-body d-block">Sin Rastros de Sesión</strong>
                <span class="text-secondary">No se instalan cookies ni queda historial de tu navegación.</span>
            </div>
        </div>
    </div>

    {{-- Pantalla 1: Registro Exitoso con Código y Clave --}}
    @if($screen === 'created')
        <div class="portal-card text-center py-5">
            <div style="width: 72px; height: 72px; border-radius: 50%; background: rgba(16, 185, 129, 0.1); color: #10b981; display: inline-flex; align-items: center; justify-content: center; font-size: 2.25rem; margin-bottom: 1.25rem; border: 2px solid rgba(16, 185, 129, 0.25);">
                <i class="bi bi-check-lg"></i>
            </div>
            <h2 class="h4 fw-bold text-body mb-2">¡Recibimos tu denuncia correctamente!</h2>
            <p class="text-secondary mb-4" style="max-width: 540px; margin: 0 auto;">
                Tu reporte ha sido asignado al equipo de investigación confidencial. Para proteger tu anonimato, no solicitamos correo ni teléfono.
            </p>

            <div class="alert alert-warning border text-start small mb-4 mx-auto" style="max-width: 580px; border-radius: 12px;">
                <div class="d-flex align-items-start gap-2">
                    <i class="bi bi-exclamation-triangle-fill fs-5 text-warning flex-shrink-0 mt-0.5"></i>
                    <div>
                        <strong class="d-block mb-1">¡IMPORTANTE! Guarda tu Código y tu Clave ahora mismo</strong>
                        Son los <strong>únicos datos</strong> que existen para consultar respuestas o agregar pruebas. Por razones de seguridad extrema, si los pierdes <strong>nadie podrá recuperarlos</strong>.
                    </div>
                </div>
            </div>

            <div class="row g-3 justify-content-center mb-4 text-start" style="max-width: 580px; margin: 0 auto;">
                <div class="col-12 col-sm-6">
                    <label class="form-label text-secondary small">Código de seguimiento</label>
                    <div class="d-flex align-items-center gap-2">
                        <div class="ethics-secret-box flex-grow-1" id="created-code">{{ $code }}</div>
                        <button type="button" class="btn btn-outline-secondary py-2 px-3" onclick="copyBox('{{ $code }}', this)" title="Copiar código">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label text-secondary small">Clave secreta</label>
                    <div class="d-flex align-items-center gap-2">
                        <div class="ethics-secret-box flex-grow-1" id="created-token">{{ $token }}</div>
                        <button type="button" class="btn btn-outline-secondary py-2 px-3" onclick="copyBox('{{ $token }}', this)" title="Copiar clave">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-center gap-3 flex-wrap">
                <button type="button" class="btn btn-outline-primary px-4 py-2 fw-semibold" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Imprimir / Guardar PDF
                </button>
                <a href="{{ route('ethics.public', $tenant->slug) }}" class="btn portal-btn-primary px-4 py-2">
                    <i class="bi bi-arrow-return-left me-1"></i>Volver al inicio
                </a>
            </div>
        </div>

    {{-- Pantalla 2: Seguimiento de Denuncia / Dossier para el Denunciante --}}
    @elseif($screen === 'case')
        @if($notice)
            <div class="alert alert-success d-flex align-items-center gap-2 mb-4" style="border-radius: 12px;">
                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                <div>{{ $notice }}</div>
            </div>
        @endif

        {{-- Encabezado del Caso --}}
        <div class="portal-card mb-4">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 pb-3 border-bottom mb-3">
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                        <span class="badge font-monospace bg-body-secondary text-body border px-2.5 py-1 fs-6">
                            #{{ $case->public_code }}
                        </span>
                        <h2 class="h5 fw-bold text-body mb-0">{{ $case->subject }}</h2>
                    </div>
                    <div class="text-secondary small">
                        <span>Categoría: <strong>{{ $case->categoryLabel() }}</strong></span>
                        <span class="mx-1.5">·</span>
                        <span>Registrada el {{ $case->reported_on->format('d/m/Y') }}</span>
                    </div>
                </div>
                <div>
                    <span class="badge rounded-2 bg-{{ $case->statusTone() }}-subtle text-{{ $case->statusTone() }}-emphasis border border-{{ $case->statusTone() }}-subtle px-3 py-1.5 fs-6 fw-semibold">
                        <i class="bi bi-{{ $case->statusIcon() }} me-1"></i>{{ $case->statusLabel() }}
                    </span>
                </div>
            </div>

            <div class="p-3 rounded-3 mb-0" style="background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color);">
                <div class="small fw-bold text-secondary mb-1">Detalle de los hechos reportados:</div>
                <p class="mb-0 text-body" style="white-space: pre-line; line-height: 1.6;">{{ $case->body }}</p>
                @if($case->attachments->whereNull('message_id')->isNotEmpty())
                    <div class="d-flex align-items-center gap-2 mt-2 pt-2 border-top small text-secondary">
                        <i class="bi bi-paperclip"></i>
                        <span>{{ $case->attachments->whereNull('message_id')->count() }} foto(s) o evidencia(s) adjunta(s)</span>
                    </div>
                @endif
            </div>

            {{-- Resolución Final si está cerrada --}}
            @if($case->isClosed() && $case->resolution)
                <div class="alert alert-success d-flex align-items-start gap-3 mt-3 mb-0" style="border-radius: 12px;">
                    <i class="bi bi-check-circle-fill text-success fs-4 flex-shrink-0 mt-0.5"></i>
                    <div>
                        <strong class="d-block mb-1">Conclusión final de la investigación:</strong>
                        <div style="white-space: pre-line; line-height: 1.6;">{{ $case->resolution }}</div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Conversación con el Equipo de Investigación --}}
        <div class="portal-card">
            <h3 class="h6 fw-bold text-body mb-3 pb-2 border-bottom d-flex align-items-center gap-2">
                <i class="bi bi-chat-dots text-primary"></i>
                <span>Comunicaciones con el Equipo de Investigación</span>
            </h3>

            <div class="d-flex flex-column gap-3 mb-4">
                @forelse($case->messages as $m)
                    <div class="d-flex flex-column {{ $m->fromReporter() ? 'align-self-start' : 'align-self-end' }}" style="max-width: 85%;">
                        <div class="small text-secondary mb-1 {{ $m->fromReporter() ? '' : 'text-end' }}">
                            <strong>{{ $m->fromReporter() ? 'Tú (Denunciante)' : 'Equipo de Investigación' }}</strong>
                            <span>· {{ $m->sent_on->format('d/m/Y') }}</span>
                        </div>
                        <div class="{{ $m->fromReporter() ? 'chat-bubble-reporter' : 'chat-bubble-investigator' }}">
                            <div style="white-space: pre-line; line-height: 1.6;">{{ $m->body }}</div>
                            @if($m->attachments->isNotEmpty())
                                <div class="mt-2 pt-1 border-top small opacity-75">
                                    <i class="bi bi-images me-1"></i>{{ $m->attachments->count() }} archivo(s) adjunto(s)
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="text-center py-4 text-secondary small">
                        <i class="bi bi-chat-square-dots fs-3 d-block mb-2 text-muted"></i>
                        Aún no hay mensajes del equipo de investigación. Revisa periódicamente con tu código y clave.
                    </div>
                @endforelse
            </div>

            @unless($case->isClosed())
                <div class="pt-3 border-top">
                    <form method="POST" action="{{ route('ethics.public.message', $tenant->slug) }}" enctype="multipart/form-data">
                        <input type="hidden" name="code" value="{{ $case->public_code }}">
                        <input type="hidden" name="token" value="{{ $token }}">
                        <div class="mb-3">
                            <label for="msg_body" class="form-label">
                                <i class="bi bi-reply me-1 text-primary"></i>Enviar un mensaje o aportar más información
                            </label>
                            <textarea id="msg_body" name="body" rows="3" maxlength="5000" required 
                                      class="form-control @error('body') is-invalid @enderror" 
                                      placeholder="Escribe aquí aclaraciones, respuestas o datos adicionales para el investigador..."></textarea>
                            @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="msg_photos" class="form-label small text-secondary">
                                <i class="bi bi-paperclip me-1"></i>Adjuntar capturas o fotos adicionales (opcional, máx. 3)
                            </label>
                            <input type="file" id="msg_photos" name="photos[]" accept="image/*" multiple class="form-control form-control-sm">
                            <div class="form-text small">Se eliminarán automáticamente los metadatos de ubicación y dispositivo.</div>
                        </div>
                        <button type="submit" class="btn portal-btn-primary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-send-fill"></i>
                            <span>Enviar mensaje seguro</span>
                        </button>
                    </form>
                </div>
            @endunless

            <div class="mt-4 pt-3 border-top text-center">
                <a href="{{ route('ethics.public', $tenant->slug) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-box-arrow-left me-1"></i>Salir de esta consulta
                </a>
            </div>
        </div>

    {{-- Pantalla 3: Home (Pestañas entre Registrar Denuncia y Consultar Seguimiento) --}}
    @else
        <div class="portal-card">
            {{-- Barra de Selección de Modo --}}
            <div class="portal-nav-pills" role="tablist">
                <button type="button" class="portal-nav-btn {{ !empty($followOld['code']) ? '' : 'active' }}" id="tabBtnNew" onclick="switchPortalTab('new')">
                    <i class="bi bi-shield-plus"></i>
                    <span>Hacer una denuncia</span>
                </button>
                <button type="button" class="portal-nav-btn {{ !empty($followOld['code']) ? 'active' : '' }}" id="tabBtnFollow" onclick="switchPortalTab('follow')">
                    <i class="bi bi-search"></i>
                    <span>Seguir una denuncia</span>
                </button>
            </div>

            {{-- Tab 1: Registrar Denuncia --}}
            <div id="tabContentNew" style="display: {{ !empty($followOld['code']) ? 'none' : 'block' }};">
                <h2 class="h5 fw-bold text-body mb-1">Hacer una denuncia</h2>
                <p class="text-secondary small mb-4">
                    Describe los hechos con la mayor claridad posible. Recuerda que no necesitas identificarte.
                </p>

                <form method="POST" action="{{ route('ethics.public.store', $tenant->slug) }}" enctype="multipart/form-data" novalidate id="formNewReport">
                    {{-- Tipo de Denuncia / Categoría --}}
                    <div class="mb-3">
                        <label for="category" class="form-label">
                            Tipo de falta o situación denunciada <span class="text-danger">*</span>
                        </label>
                        <select id="category" name="category" required class="form-select @error('category') is-invalid @enderror">
                            <option value="">Selecciona el tipo de situación</option>
                            @foreach($categories as $key => $label)
                                <option value="{{ $key }}" @selected(($old['category'] ?? null) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Asunto --}}
                    <div class="mb-3">
                        <label for="subject" class="form-label">
                            Asunto o resumen breve <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="subject" name="subject" maxlength="150" required 
                               value="{{ $old['subject'] ?? '' }}" 
                               placeholder="Ej. Incumplimiento de normas de seguridad en almacén" 
                               class="form-control @error('subject') is-invalid @enderror">
                        <div class="form-text small">Un título corto que resuma la situación.</div>
                        @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Narrativa / Detalle --}}
                    <div class="mb-3">
                        <label for="body_new" class="form-label">
                            Descripción detallada de los hechos <span class="text-danger">*</span>
                        </label>
                        <textarea id="body_new" name="body" rows="6" maxlength="5000" required 
                                  placeholder="Indica qué ocurrió, en qué fecha aproximada, en qué sede o área y quiénes estuvieron involucrados. Cuantos más detalles objetivos aportes, mejor podrá investigarse." 
                                  class="form-control @error('body') is-invalid @enderror">{{ $old['body'] ?? '' }}</textarea>
                        @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Fotos o Sustentos --}}
                    <div class="mb-4">
                        <label for="photos" class="form-label">
                            Evidencias fotográficas o capturas (opcional, hasta 3 fotos)
                        </label>
                        <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple 
                               class="form-control @error('photos') is-invalid @enderror @error('photos.*') is-invalid @enderror">
                        <div class="form-text small text-success">
                            <i class="bi bi-shield-check me-1"></i>Protección de privacidad: Eliminamos automáticamente los datos EXIF (GPS, modelo del móvil y fecha) de todas las fotos.
                        </div>
                        @error('photos')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @error('photos.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Compromiso de buena fe --}}
                    <div class="alert alert-light border small text-secondary mb-4 p-3" style="border-radius: 10px;">
                        <i class="bi bi-info-circle me-1 text-primary"></i>
                        Este canal está diseñado para promover una cultura de integridad y transparencia. Agradecemos reportar hechos verídicos y de buena fe.
                    </div>

                    <button type="submit" class="btn portal-btn-primary w-100 py-2.5 d-flex align-items-center justify-content-center gap-2">
                        <i class="bi bi-shield-lock-fill"></i>
                        <span>Enviar denuncia de forma confidencial</span>
                    </button>
                </form>
            </div>

            {{-- Tab 2: Seguir una Denuncia Existente --}}
            <div id="tabContentFollow" style="display: {{ !empty($followOld['code']) ? 'block' : 'none' }};">
                <h2 class="h5 fw-bold text-body mb-1">Consultar Estado de tu Denuncia</h2>
                <p class="text-secondary small mb-4">
                    Ingresa el código de 6 dígitos y la clave que te brindó el sistema al registrar tu reporte.
                </p>

                <form method="POST" action="{{ route('ethics.public.follow', $tenant->slug) }}" novalidate>
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label for="code" class="form-label">
                                Código de seguimiento <span class="text-danger">*</span>
                            </label>
                            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="off" required 
                                   value="{{ $followOld['code'] ?? '' }}" 
                                   placeholder="Ej. 123456" 
                                   class="form-control font-monospace fs-5 @error('code') is-invalid @enderror">
                            <div class="form-text small">Los 6 dígitos que anotaste.</div>
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="token" class="form-label">
                                Clave secreta <span class="text-danger">*</span>
                            </label>
                            <input type="password" id="token" name="token" autocomplete="off" required 
                                   placeholder="XXXX-XXXX" 
                                   class="form-control font-monospace fs-5 @error('code') is-invalid @enderror">
                            <div class="form-text small">La clave de letras y números (ej. 3K8M-9P2W).</div>
                        </div>

                        @error('code')
                            <div class="col-12">
                                <div class="alert alert-danger py-2 small d-flex align-items-center gap-2" style="border-radius: 8px;">
                                    <i class="bi bi-exclamation-circle-fill"></i>
                                    <span>{{ $message }}</span>
                                </div>
                            </div>
                        @enderror

                        <div class="col-12 mt-4">
                            <button type="submit" class="btn portal-btn-primary w-100 py-2.5 d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-search"></i>
                                <span>Consultar expediente confidencial</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Footer Discreto --}}
    <footer class="text-center text-secondary small mt-4 pt-3 border-top" style="font-size: 0.775rem;">
        <div>{{ $brandSettings->tradeName() }} · Sistema de Canal Ético y Cumplimiento</div>
        <div class="mt-0.5 opacity-75">
            <i class="bi bi-lock-fill me-1"></i>Protección estricta de la confidencialidad de la persona denunciante
        </div>
    </footer>
</main>

<script>
function switchPortalTab(tab) {
    const btnNew = document.getElementById('tabBtnNew');
    const btnFollow = document.getElementById('tabBtnFollow');
    const contentNew = document.getElementById('tabContentNew');
    const contentFollow = document.getElementById('tabContentFollow');

    if (tab === 'new') {
        btnNew.classList.add('active');
        btnFollow.classList.remove('active');
        contentNew.style.display = 'block';
        contentFollow.style.display = 'none';
    } else {
        btnFollow.classList.add('active');
        btnNew.classList.remove('active');
        contentFollow.style.display = 'block';
        contentNew.style.display = 'none';
    }
}

function copyBox(text, btn) {
    if (!navigator.clipboard) {
        prompt('Copia este valor:', text);
        return;
    }
    navigator.clipboard.writeText(text).then(() => {
        const originalHtml = btn.innerHTML;
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        btn.innerHTML = '<i class="bi bi-check2"></i>';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 2000);
    }).catch(() => {
        prompt('Copia este valor:', text);
    });
}
</script>

</body>
</html>
