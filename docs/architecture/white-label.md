# Marca blanca y configuración de la app ("Marca y app")

Cada empresa puede tener su logo, sus colores, su nombre comercial y sus propias reglas para
la app móvil (selfie obligatoria, marcación sin conexión, versión mínima, etc.). La plataforma
tiene sus propios valores por defecto, y lo que la empresa no define lo hereda.

Archivos clave:

- Migración: `database/migrations/2026_10_13_000001_create_tenant_settings_table.php`
- Modelo: `app/Models/TenantSetting.php`
- Validación y guardado: `app/Services/TenantSettingsService.php`
- Panel: `app/Http/Controllers/Web/BrandingWebController.php` + `resources/views/settings/branding.blade.php`
- API: `app/Http/Controllers/Api/AppConfigController.php`
- Versión mínima: `app/Http/Middleware/EnsureSupportedAppVersion.php`
- Pruebas: `tests/Feature/WhiteLabelSettingsTest.php`

## Dónde vive cada dato

| Dato | Empresa | Plataforma |
|---|---|---|
| Logo claro | `tenants.logo_path` | `tenant_settings.logo_path` (fila global) |
| Color primario / secundario | `tenants.primary_color` / `tenants.secondary_color` | `tenant_settings.primary_color` / `secondary_color` (fila global) |
| Todo lo demás (logo oscuro, favicon, splash, acento, barra, app, reglas, integraciones) | fila de `tenant_settings` con su `tenant_id` | fila de `tenant_settings` con `tenant_id = NULL` |
| Política de geocerca | `company_configurations.geofence_policy` | — (no hay valor de plataforma) |
| Módulos visibles en la app | `company_configurations` (`/settings/mobile`) | — |

Las columnas `logo_path`, `primary_color` y `secondary_color` de `tenant_settings` **solo se
leen en la fila global** (`TenantSetting::PLATFORM_ONLY`): para una empresa se usan las de
`tenants`, que ya existían antes y siguen usando el login, `GET /api/tenant/branding`, etc.

## Tabla `tenant_settings`

Una fila por empresa (`tenant_id` único) más una fila global (`tenant_id = NULL`) que edita el
Super Admin. **Todas las columnas de configuración aceptan NULL y NULL significa "heredar".**

| Grupo | Columnas |
|---|---|
| Identidad | `trade_name`, `logo_path`*, `logo_dark_path`, `favicon_path`, `splash_path`, `contact_email`, `contact_phone`, `website`, `address` |
| Colores | `primary_color`*, `secondary_color`*, `accent_color`, `navbar_color` (hex `#RRGGBB`) |
| App móvil | `api_base_url`, `min_supported_version`, `latest_version`, `force_update`, `selfie_required`, `face_detection_required`, `offline_mode_enabled`, `offline_max_hours`, `max_gps_accuracy_meters` |
| Reglas | `default_grace_minutes`, `lunch_minutes`, `lunch_paid`, `default_geofence_radius` |
| Integraciones | `webhook_url`, `webhook_secret` (cifrado), `maps_api_key` (cifrado), `notification_endpoint` |
| Meta | `updated_by`, `created_at`, `updated_at` |

\* solo en la fila global.

**Fila única de la plataforma.** El índice único de `tenant_id` no impide varias filas con
`tenant_id = NULL`, así que el único camino que crea la fila global es
`TenantSetting::platform()`: busca la fila con `lockForUpdate()` dentro de una transacción y la
crea si no existe. `editableRowFor(null)` (panel global, `TenantSettingsService`) la usa, y
`rowFor(null)` lee siempre la de menor `id`.

### Orden de resolución

`TenantSetting::forTenant($tenant)` arma en memoria (no se guarda) la configuración efectiva:

1. Parte de `TenantSetting::DEFAULTS` (valores de fábrica: nombre `TuneSA`, primario
   `#1E40AF`, secundario `#F59E0B`, acento `#10B981`, barra `#0F172A`, versión mínima `1.0.0`,
   selfie obligatoria, sin offline, 24 h offline, 100 m de precisión, 10 min de tolerancia,
   60 min de refrigerio, radio 50 m).
2. Encima, los valores no nulos de la **fila global**.
3. Si hay empresa: borra los campos que **no se heredan** y encima pone los valores no nulos de
   la **fila de la empresa** (ignorando `PLATFORM_ONLY`).

Resultado: **empresa → plataforma → fábrica**. Sin empresa (`$tenant = null`) se obtienen los
valores de la plataforma.

Casos especiales del modelo:

- **No se heredan** de la plataforma (`TenantSetting::NOT_INHERITED`): `trade_name`,
  `webhook_url`, `webhook_secret`, `notification_endpoint`. Si la empresa no tiene
  `trade_name`, se usa `tenants.company_name`.
- **Contacto** (`contact()`): el de la empresa es suyo (su fila o los datos de `tenants`:
  email, teléfono, dirección), nunca el soporte de la plataforma.
- **Colores primario/secundario**: `tenants.*_color` → fila global → fábrica.
- **Logo oscuro**: si la empresa tiene logo propio pero no versión oscura, se usa su logo claro
  (nunca el de la plataforma junto a su marca).
- **Precisión GPS** (`maxGpsAccuracy()`): empresa → plataforma → `ATTENDANCE_MAX_GPS_ACCURACY`
  de `.env` (`config/attendance.php`), no el `DEFAULTS`.
- **`maps_api_key`**: se hereda de la plataforma (no está en `NOT_INHERITED`), pero hoy
  **ningún código la usa**: está reservada para una futura geocodificación. Solo se ve
  (enmascarada) en el panel y en `GET /api/admin/app-config`, nunca en la API pública.
- **`config_version`**: la fecha más reciente entre `tenants.updated_at`, la fila de la empresa,
  la fila global y `company_configurations.updated_at`. La app puede usarla para saber si recargar.

### `geofence_strict_mode` ↔ `company_configurations.geofence_policy`

La política de geocerca tiene **una sola fuente**: `company_configurations.geofence_policy`
(`reject` por defecto, o `flag`). La pestaña Reglas del panel y `PUT /api/admin/app-config`
la escriben ahí (`TenantSettingsService::update`). La app la recibe como booleano:

- `geofence_strict_mode: true` ⇔ `reject`: fuera del radio la marcación se rechaza.
- `geofence_strict_mode: false` ⇔ `flag`: se guarda con `within_geofence = false` para revisión.
- Sin empresa (config de plataforma) siempre es `true`.

### Secretos

`webhook_secret` y `maps_api_key` usan el cast `encrypted` (se cifran con `APP_KEY`; si cambias
`APP_KEY` dejan de poder leerse). Están en `$hidden`, nunca salen en `GET /api/v1/app/config`,
y al editar se muestran enmascarados (`••••••••1234`). En el formulario, vacío = no cambiar;
las casillas `clear_webhook_secret` / `clear_maps_api_key` los borran. La auditoría registra
qué secretos cambiaron, no su valor.

### Integraciones: webhook y endpoint de notificaciones

`App\Services\IntegrationDispatcher` (enganchado a los eventos de los modelos en
`AppServiceProvider`) encola un `App\Jobs\SendTenantWebhook` (`ShouldQueue`) por evento, solo si
**la fila de la empresa** tiene la URL (no se heredan de la plataforma):

| Destino | Eventos |
|---|---|
| `webhook_url` | `attendance.clock_in`, `attendance.break_start`, `attendance.break_end`, `attendance.clock_out` (al llenarse cada hora de la marcación, desde la API o el panel) y `leave_request.status_changed` (cada cambio de `status`, incluida la cancelación). |
| `notification_endpoint` | `notification.created`: cada aviso in-app (canal `database`) creado para un usuario de la empresa. |

```http
POST <url>
Content-Type: application/json
X-Tunesa-Event: attendance.clock_in
X-Tunesa-Signature: sha256=<hex de HMAC-SHA256(cuerpo, webhook_secret)>

{"event":"attendance.clock_in","occurred_at":"2026-10-09T08:02:11-05:00",
 "tenant":{"slug":"empresa-demo"},
 "data":{"id":120,"user_id":7,"employee_code":"EMP-001","branch_id":1,"work_date":"2026-10-09",
         "clock_in_at":"2026-10-09T08:02:11-05:00","break_start_at":null,"break_end_at":null,
         "clock_out_at":null,"status":"present","late_minutes":0,"break_minutes":null,
         "worked_hours":null,"clock_in_within_geofence":true,"clock_out_within_geofence":null}}
```

- Para verificar: calcular el HMAC del cuerpo **tal como llega** con el secreto y comparar.
  Sin `webhook_secret` el envío va sin `X-Tunesa-Signature`. El endpoint de notificaciones
  usa el mismo secreto.
- `leave_request.status_changed` trae `id`, `user_id`, `type`, `start_date`, `end_date`, `days`,
  `status`, `previous_status`, `reviewed_by`, `review_notes`. `notification.created` trae `id`,
  `type`, `user_id`, `title`, `body`, `data`.
- Timeout de 5 s, 3 intentos (esperas de 10 s y 60 s); el fallo final se registra en el log.
  La URL y el secreto se leen al ejecutar el job (el secreto no viaja en la cola).
- Nunca interrumpe la acción que lo dispara: con la cola `sync` el envío corre en la misma
  petición y un error solo queda en el log. En producción conviene `QUEUE_CONNECTION=database`
  (por defecto) con un worker (`php artisan queue:work`): sin worker los eventos quedan en la
  tabla `jobs` sin enviarse.

### Refrigerio pagado (`lunch_paid`)

Al marcar la salida, `AttendanceService::clockOut` resta `break_minutes` de las horas
trabajadas salvo que el `lunch_paid` efectivo (empresa → plataforma → `false`) sea verdadero.
`lunch_minutes` solo se usa como duración de descanso propuesta al crear un turno.

## Panel: `/settings/branding`

Ruta `settings.branding` (permiso `company.configure`, o sea Administrador de empresa y Super
Admin). Un solo formulario con cuatro pestañas (`BrandingWebController::TABS`):

| Pestaña (`?tab=`) | Campos |
|---|---|
| `marca` | nombre comercial, contacto, logo claro/oscuro, favicon, splash, 4 colores con vista previa |
| `app` | URL del servidor, versión mínima / última, forzar actualización, selfie, rostro, offline, horas offline, precisión GPS |
| `reglas` | tolerancia, refrigerio, refrigerio pagado, radio de geocerca por defecto, política de geocerca (solo empresa) |
| `integraciones` | webhook, secreto, clave de mapas, endpoint de notificaciones |

- Con empresa en contexto edita su fila; en la **vista global** del Super Admin edita la fila de
  la plataforma (muestra un aviso y la ruta `settings.branding.update` está permitida en
  `SetWebTenant::GLOBAL_WRITE_ROUTES`).
- Los placeholders muestran el valor heredado; dejar un campo de texto o número vacío = heredar.
- Opciones Sí/No (`force_update`, `selfie_required`, `face_detection_required`,
  `offline_mode_enabled`, `lunch_paid`; `TenantSettingsService::BOOLEANS`): en la fila de la
  plataforma son interruptores (desmarcado = No). En una empresa son un selector de tres
  estados (`resources/views/settings/partials/bool-setting.blade.php`): "Heredar de la
  plataforma (actual: Sí/No)" guarda `NULL`, "Sí" guarda `true` y "No" `false`. Con "Heredar" la
  empresa sigue a la plataforma aunque esta cambie después. Un booleano ausente del formulario
  no se modifica.
- Validación (`TenantSettingsService::rules`): colores `#RRGGBB` (primario y secundario son
  obligatorios para una empresa), imágenes PNG/JPG/WebP hasta 2 MB, favicon PNG/ICO (SVG
  excluido), `api_base_url` solo `https://` y **sin `/api` al final**, versiones tipo `1.2.3`,
  `offline_max_hours` 1–72, `max_gps_accuracy_meters` 10–1000, radio 10–5000.
- Las imágenes se guardan en el disco `public`: `tenants/{id}/branding/` o `platform/branding/`.
- Cada guardado queda en auditoría como `tenant_settings.updated` con la lista de campos cambiados.
- `/settings/mobile` (`CompanySettingsWebController`) es otra pantalla: qué **módulos** ve y
  edita el colaborador (boletas, legajo, datos de pago, dotación).

Lo mismo por API (Administrador de empresa): `GET /api/admin/app-config` devuelve
`settings` (lo guardado, con secretos enmascarados) y `effective` (lo resuelto), y
`PUT`/`POST /api/admin/app-config` (multipart) guarda con las mismas reglas. La empresa siempre
es la de la request, nunca un campo del input.

Por API, `null` (JSON) o un campo vacío (multipart) en un booleano o un número también es
"heredar"; un campo ausente no se cambia.

También existe la API anterior de marca: `GET /api/tenant/branding` (pública, con
`X-Tenant-Slug`; devuelve las claves históricas de `tenants` más las nuevas) y
`PUT /api/admin/branding` (permiso `tenants.branding`; cambia `company_name`, colores y logo en
`tenants`). Mantiene su ruta, permiso y forma de respuesta, pero guarda a través de
`TenantSettingsService::update`: logo solo PNG/JPG/WebP (sin SVG), borra el logo anterior al
reemplazarlo y deja la auditoría `tenant_settings.updated`.

## Marca dinámica en el panel y el login

- `resources/views/layouts/app.blade.php` (panel) y `resources/views/layouts/guest.blade.php`
  (login, recuperar contraseña) calculan `$brandSettings = TenantSetting::forTenant(...)` e
  incluyen `resources/views/layouts/partials/brand-head.blade.php`.
- Ese parcial pone el favicon, `<meta name="theme-color">` y variables CSS en `:root`:
  `--brand`, `--brand-rgb`, `--brand-text`, `--brand-secondary(-rgb)`, `--brand-accent(-rgb)`,
  `--brand-navbar`, `--brand-navbar-text`. El color del texto sobre cada fondo lo decide
  `TenantSetting::contrastText()` (contraste WCAG: blanco u oscuro). La barra superior solo se
  pinta si la empresa o la plataforma eligió `navbar_color` (`hasCustomNavbar()`).
- El logo claro u oscuro se alterna según el tema (`data-bs-theme="dark"`).
- El título de la pestaña usa `tradeName()`.

### Login por subdominio o dominio propio

`Tenant::fromHost($host)` (`app/Models/Tenant.php`) decide la marca del login y en qué empresa
buscar al usuario:

- `acme.<APP_DOMAIN>` → empresa con slug `acme` (excepto `www`, `api`, `admin`, `app`, `mail`, `cdn`).
- `APP_DOMAIN`, `localhost` o `127.0.0.1` → sin empresa (marca de la plataforma).
- Cualquier otro host → empresa cuyo `custom_domain` coincida; si no hay, marca de la plataforma.

Lo usan `Web\AuthController` (login), `PasswordResetController` y `layouts/guest.blade.php`.
Con un túnel `*.trycloudflare.com` no hay empresa por host: se ve la marca de la plataforma.

## `GET /api/v1/app/config` (lo que recibe la app)

Público, sin token, `throttle:60,1`. La empresa sale de `?tenant_slug=` o de la cabecera
`X-Tenant-Slug`. Sin empresa devuelve los valores de la plataforma con `tenant: null`. Con un
slug inexistente o una empresa suspendida: `404 TENANT_NOT_FOUND`. No incluye secretos.
El ejemplo completo está en [mobile-endpoints.md](../api/mobile-endpoints.md#configuración-de-arranque).

Bloques: `tenant`, `branding` (URLs absolutas de imágenes + 4 colores), `mobile` (URL del
servidor, versiones, selfie, rostro, offline, precisión GPS, `geofence_strict_mode`), `rules`,
`contact`, `modules` (solo qué módulos están visibles; los permisos de editar siguen en
`GET /api/employee/config`), y `meta.config_version`.

### Versión mínima (`EnsureSupportedAppVersion`, alias `app.version`)

En las rutas autenticadas, si la app manda `X-App-Version` menor que `min_supported_version` y
la empresa tiene `force_update = true`, responde `426 APP_UPDATE_REQUIRED` con
`data.min_supported_version` y `data.latest_version`. No aplica a `/api/auth/*` ni a la ruta de
config. Si la app no manda la cabecera, no se bloquea. Se compara ignorando un prefijo `v` y el
número de build (`1.2.3+45` → `1.2.3`).

### Comportamiento de la app

Verificado contra `mobile/lib`:

- **Arranque** (`core/config/app_config_controller.dart`): pinta al instante la configuración
  guardada para el slug actual (el guardado o `DEFAULT_TENANT`; sin slug, la de la plataforma) y
  la refresca en segundo plano con `GET /api/v1/app/config` al abrir y al volver a la app. Sin
  caché espera como máximo 4 s en el splash. Sin red conserva la caché (o los valores por
  defecto). Si la ruta nueva no existe (404 sin código) usa `GET /api/tenant/branding`.
- **Tema dinámico** (`core/theme/app_theme.dart`): `ThemeData` claro y oscuro con
  `primary_color`, `secondary_color`, `accent_color` y `navbar_color`. El logo usa
  `logo_dark_url` en tema oscuro (si falta, `logo_url`). `splash_url` se recibe y se guarda,
  pero **la app todavía no lo muestra**.
- **Conexión del servidor (oculta)** (`features/server/server_connection_dialog.dart`): se abre
  manteniendo presionado el logo en la pantalla de código de empresa o en la de login. Ver
  [mobile-cloudflare-tunnel.md](../deployment/mobile-cloudflare-tunnel.md#conexión-del-servidor).
- **`mobile.api_base_url`:** si viene y es distinto del dominio en uso, la app comprueba que
  responda y se cambia sola, sin avisar (`_maybeSwitchServer`).
- **`X-App-Version`:** la app envía su versión en todas las peticiones (`core/network/api_client.dart`).
- **Actualización** (`core/config/version_gate.dart`): pantalla bloqueante si la versión es menor
  que `min_supported_version` con `force_update`, o si una petición devuelve
  `426 APP_UPDATE_REQUIRED`; aviso descartable (una vez por arranque) si es menor sin forzar o
  menor que `latest_version`.
- **Cola offline** (`features/attendance/data/offline_clock_queue.dart`): con
  `offline_mode_enabled`, guarda la marcación cifrada en el teléfono con la hora real y la envía
  después con `offline_recorded_at`. Las que superan `offline_max_hours` se descartan en el
  teléfono; un rechazo 4xx definitivo del backend (p. ej. `OFFLINE_TOO_OLD`) la descarta y avisa.
  Los descansos también se encolan, pero el backend no acepta hora offline en esas rutas.
- **Reglas en la app:** `selfie_required` decide si pide y envía la foto (solo entrada/salida);
  `max_gps_accuracy_meters` reemplaza al `MAX_GPS_ACCURACY` compilado;
  `face_detection_required` activa la detección con ML Kit en el teléfono
  (`features/attendance/data/face_detection_service.dart`): sin rostro no deja enviar, y si ML
  Kit falla no se envía `face_detected`.
