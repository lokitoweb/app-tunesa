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
- **`maps_api_key`**: se hereda de la plataforma (no está en `NOT_INHERITED`).
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

> **Estado actual:** `webhook_url`, `webhook_secret`, `maps_api_key`, `notification_endpoint` y
> `lunch_paid` se guardan y validan, pero **ningún código del backend los usa todavía** (no se
> envía ningún webhook ni se calcula el refrigerio pagado).

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
- Los placeholders muestran el valor heredado; dejar un campo vacío = heredar.
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

> **Ojo con los interruptores del panel:** como un checkbox desmarcado no se envía,
> `BrandingWebController::update` convierte los ausentes en `false`. Al guardar el formulario
> los cinco booleanos (`force_update`, `selfie_required`, `face_detection_required`,
> `offline_mode_enabled`, `lunch_paid`) quedan **fijados en la fila de la empresa** con el valor
> que se veía, y desde el panel ya no se pueden volver a "heredar". Si después cambia el valor
> de la plataforma, esa empresa no lo recibe. Por API sí se puede mandar `null`.

También existe la API anterior de marca: `GET /api/tenant/branding` (pública, con
`X-Tenant-Slug`; devuelve las claves históricas de `tenants` más las nuevas) y
`PUT /api/admin/branding` (permiso `tenants.branding`; cambia `company_name`, colores y logo en
`tenants`).

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

<!-- verificar tras unir la app -->
### Comportamiento de la app (pendiente de verificar)

> Esta sección describe el trabajo de la app Flutter que se está haciendo en paralelo y **todavía
> no está en esta rama**. Revisar contra `mobile/lib` cuando se una.

- **Arranque:** al abrir, la app pide `GET /api/v1/app/config` con el slug guardado (o sin slug
  antes de elegir empresa) y guarda la respuesta en caché. Si no hay red usa la última
  configuración guardada.
- **Tema dinámico:** construye el `ThemeData` con `branding.primary_color`, `secondary_color`,
  `accent_color` y `navbar_color`, y muestra `logo_url` / `logo_dark_url` / `splash_url`.
- **Conexión del servidor (oculta):** manteniendo presionado el logo se abre el diálogo
  "Conexión del servidor" para cambiar la URL del backend sin recompilar (útil con túneles
  cuya URL cambia). `mobile.api_base_url`, si viene, también indica la URL a usar.
- **`X-App-Version`:** la app envía su versión en todas las peticiones.
- **Pantalla de actualización:** si la versión instalada es menor que
  `mobile.min_supported_version` con `force_update`, o si una petición devuelve
  `426 APP_UPDATE_REQUIRED`, muestra una pantalla que bloquea el uso hasta actualizar.
- **Cola offline:** con `mobile.offline_mode_enabled = true`, si no hay conexión al marcar, la
  app guarda la marcación con la hora real y la envía después con `offline_recorded_at`
  (el backend la rechaza si pasó más de `offline_max_hours`).
- **Reglas en la app:** `selfie_required` decide si pide la foto; `max_gps_accuracy_meters`
  reemplaza al `MAX_GPS_ACCURACY` compilado; `face_detection_required` activa la detección de rostro.
