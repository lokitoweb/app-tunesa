<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Instalación · Paso {{ $step }} de {{ count($steps) }}</title>
    <script>
        {{-- Tema según el sistema operativo (aún no hay preferencias guardadas) --}}
        document.documentElement.setAttribute('data-bs-theme',
            window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    </script>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <style>
        :root { --wz-accent: #3C729E; --wz-accent-soft: rgba(60, 114, 158, .12); }
        [data-bs-theme="dark"] { --wz-accent: #6ea8d8; --wz-accent-soft: rgba(110, 168, 216, .16); }
        body { min-height: 100vh; background: var(--bs-tertiary-bg); }
        .wz-card { max-width: 760px; border: 0; border-radius: 18px; box-shadow: 0 18px 50px rgba(0, 0, 0, .08); }
        .wz-steps { display: flex; gap: .25rem; list-style: none; padding: 0; margin: 0 0 1.75rem; }
        .wz-steps li { flex: 1; text-align: center; font-size: .78rem; color: var(--bs-secondary-color); }
        .wz-steps .dot { width: 34px; height: 34px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
            font-weight: 700; background: var(--bs-secondary-bg); color: var(--bs-secondary-color); margin-bottom: .35rem; }
        .wz-steps li.done .dot { background: var(--bs-success); color: #fff; }
        .wz-steps li.current .dot { background: var(--wz-accent); color: #fff; box-shadow: 0 0 0 4px var(--wz-accent-soft); }
        .wz-steps li.current { color: var(--bs-body-color); font-weight: 600; }
        .wz-check { display: flex; justify-content: space-between; align-items: center; padding: .55rem .75rem; border-radius: 10px; background: var(--bs-body-bg); border: 1px solid var(--bs-border-color-translucent); }
        .wz-log { max-height: 220px; overflow: auto; font-size: .75rem; background: var(--bs-dark-bg-subtle); border-radius: 10px; padding: .75rem; white-space: pre-wrap; }
        .btn-wz { background: var(--wz-accent); border-color: var(--wz-accent); color: #fff; }
        .btn-wz:hover, .btn-wz:focus { filter: brightness(1.08); color: #fff; }
        @media (max-width: 576px) { .wz-steps .label { display: none; } }
    </style>
</head>
<body class="d-flex align-items-center py-4 px-3">
<main class="card wz-card mx-auto w-100">
    <div class="card-body p-4 p-md-5">
        <div class="d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-rocket-takeoff-fill fs-3" style="color: var(--wz-accent)"></i>
            <div>
                <h1 class="h5 fw-bold mb-0">Instalación de la plataforma</h1>
                <div class="small text-body-secondary">Configura el servidor en cinco pasos</div>
            </div>
        </div>

        <ol class="wz-steps" aria-label="Pasos">
            @foreach($steps as $n => $label)
                <li class="{{ $n < $step ? 'done' : ($n === $step ? 'current' : '') }}" @if($n === $step) aria-current="step" @endif>
                    <span class="dot">@if($n < $step)<i class="bi bi-check-lg"></i>@else{{ $n }}@endif</span>
                    <div class="label">{{ $label }}</div>
                </li>
            @endforeach
        </ol>

        @if($errors->any())
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $errors->first() }}
            </div>
        @endif

        @switch($step)
            @case(1)
                <h2 class="h6 fw-bold">Requisitos del servidor</h2>
                <p class="text-body-secondary small">Si algo sale en rojo, pídele a tu hosting que lo active y recarga la página.</p>
                <div class="wz-check mb-3">
                    <span><i class="bi bi-filetype-php me-2"></i>PHP {{ $req['php']['required'] }} o superior</span>
                    <span class="badge {{ $req['php']['ok'] ? 'text-bg-success' : 'text-bg-danger' }}">{{ $req['php']['current'] }}</span>
                </div>
                <div class="row g-2 mb-3">
                    @foreach($req['extensions'] as $ext)
                        <div class="col-6 col-md-4">
                            <div class="wz-check">
                                <span class="font-monospace small">{{ $ext['name'] }}</span>
                                <i class="bi {{ $ext['ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' }}" aria-label="{{ $ext['ok'] ? 'Cargada' : 'Falta' }}"></i>
                            </div>
                        </div>
                    @endforeach
                </div>
                <h3 class="h6 fw-semibold mt-3">Permisos de escritura</h3>
                <div class="d-grid gap-2 mb-4">
                    @foreach($req['folders'] as $dir)
                        <div class="wz-check">
                            <span class="font-monospace small">{{ $dir['name'] }}</span>
                            <span class="badge {{ $dir['ok'] ? 'text-bg-success' : 'text-bg-danger' }}">{{ $dir['ok'] ? 'Escribible' : 'Sin permiso' }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ route('installer.requirements') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>Volver a revisar</a>
                    @if($req['ok'])
                        <a href="{{ route('installer.database') }}" class="btn btn-wz">Continuar<i class="bi bi-arrow-right ms-1"></i></a>
                    @else
                        <button class="btn btn-wz" disabled>Continuar</button>
                    @endif
                </div>
                @break

            @case(2)
                <h2 class="h6 fw-bold">Base de datos MySQL / MariaDB</h2>
                <p class="text-body-secondary small">Crea antes una base vacía y un usuario con permisos sobre ella en tu hosting.</p>
                <form method="POST" action="{{ route('installer.database.save') }}" id="dbForm" novalidate>
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold" for="driver">Motor</label>
                            <select class="form-select" id="driver" name="driver">
                                <option value="mysql" @selected(old('driver', $defaults['driver']) === 'mysql')>MySQL</option>
                                <option value="mariadb" @selected(old('driver', $defaults['driver']) === 'mariadb')>MariaDB</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold" for="host">Host</label>
                            <input class="form-control" id="host" name="host" required value="{{ old('host', $defaults['host']) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold" for="port">Puerto</label>
                            <input class="form-control" id="port" name="port" type="number" min="1" max="65535" required value="{{ old('port', $defaults['port']) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold" for="database">Nombre de la base</label>
                            <input class="form-control" id="database" name="database" required value="{{ old('database', $defaults['database']) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold" for="username">Usuario</label>
                            <input class="form-control" id="username" name="username" required autocomplete="off" value="{{ old('username', $defaults['username']) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold" for="password">Contraseña</label>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password">
                        </div>
                    </div>
                    <div id="dbResult" class="alert d-none mt-3 mb-0" role="status"></div>
                    <div class="d-flex justify-content-between gap-2 mt-4 flex-wrap">
                        <a href="{{ route('installer.requirements') }}" class="btn btn-link text-body-secondary px-0"><i class="bi bi-arrow-left me-1"></i>Atrás</a>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary" id="btnTest">
                                <span class="spinner-border spinner-border-sm d-none me-1" id="testSpin"></span><i class="bi bi-plug me-1"></i>Probar conexión
                            </button>
                            <button type="submit" class="btn btn-wz">Guardar y continuar<i class="bi bi-arrow-right ms-1"></i></button>
                        </div>
                    </div>
                </form>
                @break

            @case(3)
                <h2 class="h6 fw-bold">Estructura de la base</h2>
                <p class="text-body-secondary small">Se crean todas las tablas, los roles y la matriz de permisos. No se cargan empresas ni usuarios de prueba.</p>
                <div id="migrateResult" class="alert {{ $migrated ? 'alert-success' : 'd-none' }}" role="status">
                    @if($migrated)<i class="bi bi-check-circle-fill me-1"></i>La estructura ya está creada. Puedes volver a ejecutarla para aplicar migraciones pendientes.@endif
                </div>
                <pre class="wz-log d-none" id="migrateLog"></pre>
                <div class="d-flex justify-content-between gap-2 mt-4 flex-wrap">
                    <a href="{{ route('installer.database') }}" class="btn btn-link text-body-secondary px-0"><i class="bi bi-arrow-left me-1"></i>Atrás</a>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" id="btnMigrate">
                            <span class="spinner-border spinner-border-sm d-none me-1" id="migSpin"></span><i class="bi bi-database-gear me-1"></i>Crear estructura
                        </button>
                        <a href="{{ route('installer.admin') }}" class="btn btn-wz {{ $migrated ? '' : 'disabled' }}" id="btnNext" aria-disabled="{{ $migrated ? 'false' : 'true' }}">Continuar<i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                </div>
                @break

            @case(4)
                <h2 class="h6 fw-bold">Plataforma y Super Admin</h2>
                <p class="text-body-secondary small">El Super Admin administra todas las empresas. Las empresas, sedes y colaboradores se crean luego desde el panel.</p>
                <form method="POST" action="{{ route('installer.admin.save') }}">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="trade_name">Nombre de la plataforma</label>
                            <input class="form-control" id="trade_name" name="trade_name" required maxlength="120" value="{{ old('trade_name', $defaults['trade_name']) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="login_email_domain">Dominio de los correos de acceso</label>
                            <div class="input-group">
                                <span class="input-group-text">@</span>
                                <input class="form-control" id="login_email_domain" name="login_email_domain" required placeholder="midominio.com" value="{{ old('login_email_domain', $defaults['login_email_domain']) }}">
                            </div>
                            <div class="form-text">Los colaboradores ingresan con [DNI]&#64;este dominio. Cada empresa puede cambiarlo.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="timezone">Zona horaria</label>
                            <select class="form-select" id="timezone" name="timezone">
                                @foreach(\DateTimeZone::listIdentifiers(\DateTimeZone::AMERICA) as $tz)
                                    <option value="{{ $tz }}" @selected(old('timezone', 'America/Lima') === $tz)>{{ $tz }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12"><hr class="my-1"></div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="first_name">Nombres</label>
                            <input class="form-control" id="first_name" name="first_name" required value="{{ old('first_name') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="last_name">Apellidos</label>
                            <input class="form-control" id="last_name" name="last_name" required value="{{ old('last_name') }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="email">Correo del Super Admin</label>
                            <input class="form-control" id="email" name="email" type="email" required autocomplete="username" value="{{ old('email') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="admin_password">Contraseña</label>
                            <input class="form-control" id="admin_password" name="password" type="password" required minlength="10" autocomplete="new-password">
                            <div class="form-text">Mínimo 10 caracteres, con mayúsculas, minúsculas y números.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold" for="password_confirmation">Repite la contraseña</label>
                            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="d-flex justify-content-between gap-2 mt-4">
                        <a href="{{ route('installer.migrate') }}" class="btn btn-link text-body-secondary px-0"><i class="bi bi-arrow-left me-1"></i>Atrás</a>
                        <button type="submit" class="btn btn-wz"><i class="bi bi-shield-lock me-1"></i>Instalar y bloquear el asistente</button>
                    </div>
                </form>
                @break

            @case(5)
                <div class="text-center py-3">
                    <i class="bi bi-patch-check-fill text-success" style="font-size: 3.5rem;"></i>
                    <h2 class="h4 fw-bold mt-3">Instalación completa</h2>
                    <p class="text-body-secondary mb-1">Ingresa con <strong>{{ $email }}</strong>.</p>
                    <p class="text-body-secondary small">El asistente quedó bloqueado: <span class="font-monospace">/install</span> ya no está disponible.
                        Para reinstalar habría que borrar <span class="font-monospace">storage/app/installed.lock</span> en el servidor.</p>
                    <div class="alert alert-warning text-start small mt-3">
                        <div class="fw-semibold mb-1"><i class="bi bi-terminal me-1"></i>Último paso, en el servidor (una sola vez, como root)</div>
                        La web no puede instalar las tareas programadas ni el despliegue automático. En la carpeta del proyecto corre:
                        <pre class="bg-body-tertiary border rounded p-2 mt-2 mb-1 user-select-all">sudo bash scripts/server-setup.sh</pre>
                        Sin esto no se registran faltas, no salen los recordatorios ni los avisos push, y los cambios no se publican solos.
                    </div>
                    <a href="{{ url('/login') }}" class="btn btn-wz btn-lg mt-2"><i class="bi bi-box-arrow-in-right me-1"></i>Ir al panel</a>
                </div>
                @break
        @endswitch
    </div>
</main>

<script>
(function () {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const show = (el, ok, text) => {
        el.className = 'alert mt-3 mb-0 ' + (ok ? 'alert-success' : 'alert-danger');
        el.textContent = text;
    };

    const btnTest = document.getElementById('btnTest');
    if (btnTest) {
        btnTest.addEventListener('click', async () => {
            const form = document.getElementById('dbForm');
            const out = document.getElementById('dbResult');
            const spin = document.getElementById('testSpin');
            btnTest.disabled = true; spin.classList.remove('d-none');
            try {
                const res = await fetch(@json(route('installer.database.test')), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                    body: new FormData(form),
                });
                const data = await res.json();
                show(out, !!data.ok, data.message || Object.values(data.errors || {}).flat()[0] || 'Revisa los datos.');
            } catch (e) {
                show(out, false, 'No se pudo probar la conexión.');
            } finally {
                btnTest.disabled = false; spin.classList.add('d-none');
            }
        });
    }

    const btnMigrate = document.getElementById('btnMigrate');
    if (btnMigrate) {
        btnMigrate.addEventListener('click', async () => {
            const out = document.getElementById('migrateResult');
            const log = document.getElementById('migrateLog');
            const spin = document.getElementById('migSpin');
            const next = document.getElementById('btnNext');
            btnMigrate.disabled = true; spin.classList.remove('d-none');
            show(out, true, 'Creando tablas… esto puede tardar un minuto.');
            try {
                const res = await fetch(@json(route('installer.migrate.run')), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                });
                const data = await res.json();
                show(out, !!data.ok, data.message || 'Falló la migración.');
                if (data.log) { log.textContent = data.log; log.classList.remove('d-none'); }
                if (data.ok) { next.classList.remove('disabled'); next.setAttribute('aria-disabled', 'false'); }
            } catch (e) {
                show(out, false, 'La migración no respondió. Revisa storage/logs/laravel.log.');
            } finally {
                btnMigrate.disabled = false; spin.classList.add('d-none');
            }
        });
    }
})();
</script>
</body>
</html>
