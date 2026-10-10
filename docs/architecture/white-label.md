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
| Integraciones | `webhook_url`, `webhook_secret` (cifrado), `maps_api_key` (cifrado), `notification_endpoint`, `geocoding_provider`, `fcm_enabled`, `fcm_service_account_json` (cifrado, solo fila global) |
| Antifraude | `mock_location_policy` (`reject` / `flag`), `max_time_drift_seconds` |
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
- **`maps_api_key`**: clave de Google Maps Geocoding. Se hereda de la plataforma (no está en
  `NOT_INHERITED`) y la usa `App\Modules\Integrations\Services\Geocoding\GeocodingService` cuando el proveedor
  efectivo (`geocoding_provider`) es `google`; sin clave vuelve solo a OpenStreetMap/Nominatim.
  Solo se ve (enmascarada) en el panel y en `GET /api/admin/app-config`, nunca en la API pública.
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
`APP_KEY` dejan de poder leerse). Están en `$hidden`, nunca salen en `GET /api/v1/app/config`
ni en la API admin (enmascarados, `••••••••1234`). La auditoría registra qué secretos
cambiaron, no su valor.

**El secreto del webhook nunca está vacío.** Cada empresa recibe uno al crearse
(`Tenant::created` → `TenantSetting::ensureWebhookSecret()`, formato `whsec_` + 32 caracteres
aleatorios); la migración `backfill_webhook_secrets` lo genera para las empresas existentes y la
pantalla de Integraciones lo genera si aún falta (o si no se puede descifrar). En el panel va
precargado en un campo de contraseña con botones Mostrar/Ocultar y Copiar, y "Regenerar secreto"
(`POST /settings/branding/regenerate-webhook-secret` → `{"success":true,"secret":"whsec_…"}`,
auditado como `integration.webhook_secret_regenerated` sin el valor). Si se escribe uno propio
debe tener al menos 16 caracteres; `clear_webhook_secret` genera uno nuevo en lugar de borrarlo.
`clear_maps_api_key` sí borra la clave de mapas.

**Receptor de la plataforma.** `POST /api/v1/webhooks/receiver/{slug?}`
(`WebhookReceiverController`, 60/min) verifica `X-Tunesa-Signature` con el secreto de la empresa y
responde `{"ok":true,"signature_valid":true,"event":…}` o 401. Mientras la empresa no tenga su
URL, "URL del webhook" y "Endpoint de notificaciones" se precargan con esta dirección
(`?canal=notificaciones` para el segundo), así las pruebas funcionan desde el primer momento. Es la
única ruta del propio host (`APP_URL`) a la que se permite enviar aunque sea una red privada. En
desarrollo, el servidor de PHP atiende una petición a la vez y la prueba contra el receptor local
se queda esperando: arráncalo con varios procesos (desde `public/`:
`PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`).
En producción (php-fpm) no pasa.

### Integraciones: webhook y endpoint de notificaciones

`App\Modules\Integrations\Services\IntegrationDispatcher` (enganchado a los eventos de los modelos en
`AppServiceProvider`) encola un `App\Modules\Integrations\Jobs\SendTenantWebhook` (`ShouldQueue`) por evento, solo si
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
- Timeout de 10 s (`INTEGRATIONS_TIMEOUT`), sin seguir redirecciones. Cualquier respuesta que
  no sea 2xx o un error de conexión es un fallo.
- **Reintentos:** 5 intentos con espera exponencial (`SendTenantWebhook::BACKOFF`: 10 s, 1 min,
  5 min, 15 min). El fallo final queda como `failed` en el historial y en el log.
  La URL y el secreto se leen al ejecutar el job (el secreto no viaja en la cola).
- Nunca interrumpe la acción que lo dispara: con la cola `sync` el envío corre en la misma
  petición, un solo intento, y un error solo queda en el log y en el historial. En producción
  hace falta `QUEUE_CONNECTION=database` (por defecto) con un worker (`php artisan queue:work`):
  sin worker los eventos quedan en la tabla `jobs` sin enviarse y en el historial como
  "Pendiente" con intento 0. Ver [deployment/integrations-queue.md](../deployment/integrations-queue.md).
- **Redes privadas:** no se envía a direcciones privadas, locales o reservadas (`127.0.0.1`,
  `10.x`, `192.168.x`, `169.254.x`…), porque el historial muestra la respuesta. Solo en desarrollo
  o con un ERP en la misma red: `INTEGRATIONS_ALLOW_PRIVATE_URLS=true` (`config/integrations.php`).

### Historial de envíos (`webhook_deliveries`)

`App\Modules\Integrations\Services\WebhookSender` hace cada envío y lo registra en
`webhook_deliveries` (`App\Modules\Integrations\Models\WebhookDelivery`): una fila por envío, que los reintentos
actualizan (la identifica `uuid`, que viaja en el job como `deliveryUuid`).

| Columna | Contenido |
|---|---|
| `tenant_id` | Empresa; `NULL` = prueba hecha desde la vista global |
| `target`, `event`, `url` | `webhook_url` / `notification_endpoint`, evento y URL usada |
| `request_body`, `signature` | Cuerpo enviado (máx. 16 KB) y valor de `X-Tunesa-Signature` |
| `attempt`, `status` | Número de intento; `pending` (en cola o esperando reintento), `success`, `failed` |
| `http_status`, `response_ms`, `response_body`, `error` | Resultado del último intento (respuesta máx. 4 KB) |
| `next_retry_at` | Cuándo se reintenta (solo si el último intento falló y quedan intentos) |

La fila se crea como `pending` al encolar el evento (después del commit) y se actualiza en cada
intento. Si falla el guardado del historial, el envío sigue igual. Las filas de más de 30 días se
borran con `model:prune` (`WebhookDelivery` es `Prunable`), programado a diario a las 03:15 en
`routes/console.php`.

### Botones de prueba (pestaña Integraciones)

Mismo permiso que la pantalla (`company.configure`); `IntegrationsWebController`. En la vista
global prueban y listan las filas de la plataforma (`tenant_id NULL`). Límite: 20 pruebas por minuto.

| Botón | Ruta | Qué hace |
|---|---|---|
| Probar Webhook Ahora | `POST /settings/branding/test-webhook` | Envía al instante (sin cola) `{"event":"ping","occurred_at","tenant":{"slug"},"data":{"message":"Prueba desde TuneSA"}}` con `X-Tunesa-Event: ping` y la firma, a la URL escrita en el formulario (o la guardada) y con el secreto escrito (o el guardado). |
| Enviar Notificación de Prueba | `POST /settings/branding/test-notification` | Igual, al endpoint de notificaciones, evento `notification.test`. |
| Enviar push de prueba | `POST /settings/branding/test-push` | `App\Modules\Integrations\Services\Push\FcmSender::test(usuario)`: el del correo escrito en "Probar push" (solo usuarios de la empresa) o el actual si va vacío. Queda en auditoría como `push.tested`. Si el usuario no tiene `fcm_token` explica que debe iniciar sesión en la app con ese usuario. |
| Probar geocodificación | `GET /geocode/reverse?lat=&lng=` | Dirección de la primera sucursal (o el centro de Lima) y el proveedor que respondió. |

Las pruebas de webhook y notificaciones responden `{ok, status, ms, body|error, signed, delivery_id}`
y quedan en el historial. La tabla "Últimos 20 envíos" sale de `GET /settings/branding/deliveries`
(`{"success":true,"data":[{event, target, url, host, attempt, status, http_status, response_ms,
response_body, error, created_at, next_retry_at}]}`), con botón Actualizar y refresco automático
opcional cada 10 s.

### Geocodificación y push

- **Geocodificación:** `geocoding_provider` = `nominatim` (OpenStreetMap, gratis) o `google`
  (usa `maps_api_key`; sin clave cae a OpenStreetMap). En una empresa el selector tiene
  "Heredar de la plataforma". Lo usa el formulario de sucursales (abajo).
- **Push:** `fcm_enabled` (Sí/No con el mismo selector de tres estados) y la cuenta de servicio
  de Firebase, **solo en la fila global**: se pega el JSON o se sube el archivo; debe traer
  `project_id`, `client_email` y `private_key` (`BrandingWebController::fcmServiceAccountInput`).
  Se guarda cifrada; la empresa solo ve "Configurado por la plataforma" / "No configurado".

### Búsqueda de direcciones en sucursales

`resources/views/branches/form.blade.php`: sobre el mapa hay un buscador predictivo
(`GET /geocode/search?q=`, 400 ms de espera, mínimo 3 caracteres, flechas/Enter/Escape). Elegir
un resultado centra el mapa, mueve el pin y el círculo, y llena latitud, longitud, dirección y
ciudad (deducida del texto del resultado). Al arrastrar el pin, hacer clic en el mapa, usar "mi
ubicación" o cambiar las coordenadas, `GET /geocode/reverse` (600 ms de espera) llena la
dirección si el usuario no la escribió a mano; si la escribió, ofrece "Usar dirección sugerida".
Si el servicio no responde se muestra el error bajo el buscador y el formulario sigue funcionando.

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
| `reglas` | tolerancia, refrigerio, refrigerio pagado, radio de geocerca por defecto, política de geocerca (solo empresa), ubicación simulada (Bloquear / Marcar como sospechosa), diferencia máxima de hora |
| `integraciones` | webhook, secreto, endpoint de notificaciones (con botones de prueba), geocodificación (proveedor, clave de Google, prueba), push (activar, cuenta de servicio de Firebase, prueba) e historial "Últimos 20 envíos" |

- Con empresa en contexto edita su fila; en la **vista global** del Super Admin edita la fila de
  la plataforma (muestra un aviso y la ruta `settings.branding.update` está permitida en
  `SetWebTenant::GLOBAL_WRITE_ROUTES`).
- Los placeholders muestran el valor heredado; dejar un campo de texto o número vacío = heredar.
  Los selectores y radios de una empresa (`geocoding_provider`, `mock_location_policy`) tienen
  una opción "Heredar de la plataforma" que guarda `NULL`.
- Los campos de geocodificación, push y antifraude solo se muestran si su columna existe en
  `tenant_settings` (`BrandingWebController::settingColumns()`).
- Opciones Sí/No (`force_update`, `selfie_required`, `face_detection_required`,
  `offline_mode_enabled`, `lunch_paid`; `TenantSettingsService::BOOLEANS`, más `fcm_enabled`): en la fila de la
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
