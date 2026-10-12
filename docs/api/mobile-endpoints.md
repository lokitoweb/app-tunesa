# API para la app móvil

Contrato entre la app Flutter (`mobile/`) y el backend. Fuente de verdad: `app/Modules/*/Routes/api.php`, los
controladores de `app/Modules/*/Controllers/Api/`, los FormRequest de `app/Modules/Attendance/Requests/` y los
Resources de `app/Modules/*/Resources/`. Los ejemplos están armados a partir de esos archivos y de
los datos demo del seeder.

## Base URL y cabeceras

- Base: `<API_BASE_URL>/api` (por ejemplo `https://xxxx.trycloudflare.com/api`). La app arma esa
  URL en `Env.apiUrl` (`mobile/lib/core/config/env.dart`) y acepta el dominio con o sin `/api`.
- **No hay prefijo de versión**, salvo los alias `GET /api/v1/app/config` y
  `POST /api/v1/attendances/sync-batch`.

| Cabecera | Cuándo | Para qué |
|---|---|---|
| `Accept: application/json` | siempre | Respuestas JSON (incluidos los errores). |
| `X-Tenant-Slug: empresa-demo` | todas las rutas de empresa | Identifica la empresa (`ResolveTenant`). Sin ella: `404 TENANT_NOT_FOUND`. |
| `Authorization: Bearer <token>` | rutas protegidas | Token Sanctum del login. |
| `X-App-Version: 1.2.3` | rutas protegidas (opcional) | Si es menor que la versión mínima y la empresa fuerza actualizar: `426 APP_UPDATE_REQUIRED`. Sin la cabecera no se bloquea. No aplica a `/api/auth/*` ni a la config. |
| `Content-Type: multipart/form-data` | marcaciones con foto, `sync-batch`, documentos, solicitudes con adjunto | Subida de archivos. |

Cadena de middlewares de las rutas protegidas: `tenant` → `app.version` → `auth:sanctum` →
`tenant.scope` → `password.fresh` → `permission:<x>`.

## Formato de errores

```json
{
  "success": false,
  "message": "Texto en español para mostrar al usuario.",
  "code": "CODIGO_MAQUINA",
  "errors": { "campo": ["mensaje"] }
}
```

`errors` solo viene en validaciones. La app debería decidir por `code` (y el HTTP status), no por
el texto.

| HTTP | `code` | Origen |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Sin token, token vencido o revocado (`bootstrap/app.php`). |
| 403 | `INSUFFICIENT_PERMISSION` | El rol no tiene el permiso (`CheckPermission`, trae `required_permissions`). |
| 403 | `TENANT_SCOPE_VIOLATION` | El usuario es de otra empresa (`EnforceTenantScope`). |
| 403 | `USER_NO_TENANT` | Cuenta sin empresa que no es Super Admin. |
| 403 | `TENANT_INACTIVE` | Empresa suspendida. |
| 403 | `PASSWORD_CHANGE_REQUIRED` | Contraseña temporal: solo se permite `me`, `change-password`, `logout`, `logout-all` (`EnsurePasswordChanged`). |
| 403 | `USER_INACTIVE` | Login de cuenta desactivada/suspendida. |
| 403 | `MODULE_DISABLED` | La empresa ocultó ese módulo en la app (boletas, legajo, datos de pago, dotación). |
| 404 | `TENANT_NOT_FOUND` | Slug inexistente (o suspendido, en la ruta de config). |
| 403 | `UNAUTHORIZED` | Falla una autorización de Laravel (`AuthorizationException`, p. ej. `Gate::authorize`). |
| 404 | `NOT_FOUND` | Recurso inexistente (`findOrFail` con un id que no existe o no es tuyo). |
| 403 | `ONBOARDING_PENDING` | Marcación (también `sync-batch`) de alguien cuya incorporación no aprobó RRHH. `data.status` y `data.next_stage` dicen a dónde llevarlo; ver [Incorporación](#incorporación). |
| 404 | `NO_OPEN_ATTENDANCE` | Descanso o salida sin jornada abierta. |
| 409 | `CONFLICT`, `OVERLAPPING_REQUEST`, `NOT_PENDING`, `DOCUMENT_LOCKED` | Estado incompatible. |
| 409 | `ALREADY_CLOCKED_IN`, `BREAK_ALREADY_STARTED`, `BREAK_NOT_STARTED`, `BREAK_ALREADY_ENDED` | Estado de la jornada incompatible (ver [Marcación](#marcación)). |
| 409 | `CLIENT_UUID_CONFLICT` | El `client_uuid` ya lo usó otra persona de la empresa. |
| 423 | `PAYMENT_INFO_LOCKED` | Datos de pago ya verificados por RRHH y bloqueados. |
| 422 | `VALIDATION_ERROR` | Validación de campos. |
| 422 | `GEOFENCE_VIOLATION`, `NO_BRANCH_ASSIGNED`, `BREAK_NOT_ALLOWED`, `MOCK_LOCATION_DETECTED`, `GPS_ACCURACY_TOO_LOW`, `FACE_NOT_DETECTED`, `OFFLINE_NOT_ALLOWED`, `OFFLINE_TOO_OLD`, `OFFLINE_BEFORE_CLOCK_IN`, `OFFLINE_OUT_OF_ORDER` | Reglas de marcación, ver [Marcación](#marcación). |
| 423 | `ACCOUNT_LOCKED` | Demasiados intentos fallidos (`LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCK_MINUTES`). |
| 404 | `ETHICS_CASE_NOT_FOUND` | Código o token del canal ético incorrectos (misma respuesta para ambos). |
| 426 | `APP_UPDATE_REQUIRED` | Versión de la app por debajo de la mínima con `force_update`. |
| 423 | `TRAINING_LOCKED` | Agotó los intentos del cuestionario (`POST /api/trainings/{id}/quiz`); RRHH debe dar intentos extra desde el panel. `GET /api/trainings/{id}` trae `max_attempts`, `attempts_left` y `locked`. |
| 429 | `TOO_MANY_ATTEMPTS` | Ráfaga de intentos de login (`LoginGuard`). Los límites `throttle:` de las rutas devuelven el 429 estándar de Laravel, sin `code`. |
| 500 | `SERVER_ERROR` | Error inesperado al marcar (se registra en el log; el mensaje no expone detalles internos). |
| 503 | `GEOCODING_UNAVAILABLE` | El proveedor de mapas (Nominatim/Google) no respondió (`/api/geocode/reverse`). |

Los errores de la marcación salen de `App\Modules\Attendance\Exceptions\AttendanceException` (código + HTTP
status), que `AttendanceController::handleAttendanceException` devuelve tal cual.
`GEOFENCE_VIOLATION` es **solo** distancia a la sucursal.

Ejemplo de 426:

```json
{
  "success": false,
  "code": "APP_UPDATE_REQUIRED",
  "message": "Hay una nueva versión de la app. Actualízala para seguir marcando.",
  "data": { "min_supported_version": "1.2.0", "latest_version": "1.3.0" }
}
```

## Configuración de arranque

`GET /api/v1/app/config` (alias `GET /api/app/config`) — pública, sin token, `throttle:60,1`.
Empresa por `?tenant_slug=empresa-demo` o `X-Tenant-Slug`. Sin empresa devuelve la configuración
de la plataforma con `"tenant": null`. Detalle de cada campo en
[white-label.md](../architecture/white-label.md).

Respuesta para `empresa-demo` sin nada configurado en "Marca y app" (valores de fábrica):

```json
{
  "success": true,
  "data": {
    "tenant": {
      "slug": "empresa-demo",
      "company_name": "Empresa Demo S.A.",
      "trade_name": "Empresa Demo S.A."
    },
    "branding": {
      "logo_url": null,
      "logo_dark_url": null,
      "favicon_url": null,
      "splash_url": null,
      "primary_color": "#1E40AF",
      "secondary_color": "#F59E0B",
      "accent_color": "#10B981",
      "navbar_color": "#0F172A"
    },
    "mobile": {
      "api_base_url": null,
      "min_supported_version": "1.0.0",
      "latest_version": null,
      "force_update": false,
      "selfie_required": true,
      "face_detection_required": false,
      "offline_mode_enabled": false,
      "offline_max_hours": 24,
      "max_gps_accuracy_meters": 100,
      "geofence_strict_mode": true,
      "mock_location_policy": "reject",
      "max_time_drift_seconds": 300,
      "geocoding_provider": "nominatim",
      "fcm_enabled": false
    },
    "rules": {
      "grace_minutes": 10,
      "lunch_minutes": 60,
      "lunch_paid": false,
      "default_geofence_radius": 50
    },
    "contact": {
      "email": "admin@empresa-demo.com",
      "phone": "+51 1 234-5678",
      "website": null,
      "address": "Av. Javier Prado Este 2578, San Isidro, Lima"
    },
    "modules": {
      "payslips": true,
      "documents": true,
      "payment_info": true,
      "uniforms": true
    }
  },
  "meta": { "config_version": "2026-10-13T15:04:05+00:00" }
}
```

- Las URLs de imágenes son absolutas (`https://<host>/storage/...`).
- `max_gps_accuracy_meters` sin valor en empresa ni plataforma sale de `ATTENDANCE_MAX_GPS_ACCURACY`.
- `geofence_strict_mode: true` = política `reject`; `false` = `flag`.
- `mock_location_policy`: `reject` (GPS simulado = `422 MOCK_LOCATION_DETECTED`) o `flag` (se
  acepta como sospechosa). Sin valor en empresa ni plataforma sale de `ATTENDANCE_REJECT_MOCK_LOCATION`.
- `max_time_drift_seconds`: desfase de reloj tolerado antes de marcar `TIME_DRIFT` (nunca rechaza).
- `geocoding_provider`: el que **realmente** se usa (`google` solo si está elegido y hay clave).
- `fcm_enabled`: `true` solo si la empresa (o la plataforma) activó push **y** la plataforma cargó
  la cuenta de servicio de Firebase. La app puede usarlo para pedir permiso de notificaciones y
  enviar su token con `POST /api/auth/refresh-fcm`.
- `modules` dice qué secciones se ven; los permisos de edición (`upload_documents`,
  `edit_payment_info`, `edit_uniforms`), `required_documents` y `max_upload_mb` están en
  `GET /api/employee/config`.

Errores: `404 TENANT_NOT_FOUND` ("No encontramos una empresa activa con ese código.").

La ruta anterior `GET /api/tenant/branding` (pública, con `X-Tenant-Slug` obligatorio) sigue
existiendo y es la que usa hoy la app (`ApiEndpoints.branding`).

## Autenticación

Rutas públicas (con `X-Tenant-Slug`): `login` (`throttle:10,1`), `forgot-password` y
`reset-password` (`throttle:5,1`).

### `POST /api/v1/auth/login` (sin código de empresa)

Es el login que usa la app: solo `email` y `password`, **sin** `X-Tenant-Slug`. El correo es
único en toda la plataforma, así que el servidor reconoce la empresa del usuario. Responde lo
mismo que `POST /api/auth/login`; la app guarda `data.user.company.slug` y lo envía como
`X-Tenant-Slug` en las demás llamadas. Errores: `422` credenciales, `423` / `429` bloqueo,
`403 USER_INACTIVE`, `403 TENANT_INACTIVE`. `POST /api/v1/auth/forgot-password` funciona igual.

### `POST /api/auth/login`

Body (`LoginRequest`): `email` (obligatorio), `password` (obligatorio, mín. 6), `device_name`
(opcional, máx. 100; por defecto `mobile_app`), `fcm_token` (opcional).

```json
{
  "success": true,
  "message": "Bienvenido, Ana.",
  "data": {
    "token": "tunesa_1|AbC...",
    "token_type": "Bearer",
    "expires_at": "2026-11-08T14:00:00-05:00",
    "must_change_password": false,
    "user": {
      "id": 5, "full_name": "Ana Torres", "first_name": "Ana", "last_name": "Torres",
      "email": "ana.torres@empresa-demo.com", "role": "employee", "status": "active",
      "must_change_password": false, "employee_code": "EMP-003", "position": "Vendedora",
      "department": null, "phone": null, "avatar_url": null, "hire_date": "2022-01-10",
      "contract_type": null,
      "company": { "id": 1, "name": "Empresa Demo S.A.", "slug": "empresa-demo", "timezone": "America/Lima", "currency": "PEN" },
      "permissions": {
        "can_view_dashboard": false, "can_manage_employees": false, "can_manage_shifts": false,
        "can_upload_payslips": false, "can_approve_requests": false, "can_view_all_tenants": false
      }
    }
  }
}
```

- El token vence a los `MOBILE_TOKEN_DAYS` días (30). Un nuevo login con el mismo `device_name`
  revoca el token anterior de ese dispositivo, y se conservan como máximo `MAX_DEVICES_PER_USER`
  (3) sesiones: las más viejas se revocan.
- Errores: `422 VALIDATION_ERROR` (credenciales), `423 ACCOUNT_LOCKED`, `429 TOO_MANY_ATTEMPTS`,
  `403 USER_INACTIVE`.
- Con `must_change_password: true` la app debe llevar a cambiar la contraseña: el resto de rutas
  responde `403 PASSWORD_CHANGE_REQUIRED`.

### Resto de rutas de sesión

| Método y ruta | Body | Respuesta |
|---|---|---|
| `GET /api/auth/me` | — | `data`: el mismo objeto `user` del login. |
| `POST /api/auth/logout` | — | Revoca solo el token actual. |
| `POST /api/auth/logout-all` | — | Revoca todos los tokens del usuario. |
| `GET /api/auth/sessions` | — | Lista `{id, device, last_used_at, expires_at, current}`. |
| `DELETE /api/auth/sessions/{id}` | — | Revoca una sesión. |
| `PUT /api/auth/profile` | `first_name`, `last_name`, `phone`, `avatar` (imagen ≤ 2 MB) | Usuario actualizado. |
| `PUT /api/auth/change-password` | `current_password`, `new_password`, `new_password_confirmation` (mín. 8, mayúsculas y minúsculas, números, distinta a la actual) | Quita `must_change_password` y revoca los demás dispositivos. |
| `POST /api/auth/refresh-fcm` | `fcm_token` (obligatorio) | `{"success": true}`. Guarda `users.fcm_token`. |
| `POST /api/auth/forgot-password` | `email` | Siempre el mismo mensaje (no revela si existe). |
| `POST /api/auth/reset-password` | `token`, `email`, `password`, `password_confirmation` | `422` si el enlace no es válido o venció. |

El correo de recuperación enlaza a `APP_URL/reset-password?token=...&email=...&tenant=<slug>`.

## Marcación

Todas requieren el permiso `attendance.mark` (colaborador y supervisor). Las cuatro rutas y
`sync-batch` pasan por `AttendanceService::punch()`, que en **una sola transacción** actualiza la
jornada (`attendances`) y escribe un evento en la bitácora inmutable `attendance_punches` (ver
[attendance-integrity.md](../architecture/attendance-integrity.md)).

### Campos de una marcación

`POST /api/attendance/clock-in`, `/break-start`, `/break-end`, `/clock-out` (`multipart/form-data`,
o JSON si no hay foto). Reglas en `Concerns/MarksWithTenantSettings::integrityRules()`; los mismos
campos van en cada elemento de `sync-batch`.

| Campo | Tipo | Reglas |
|---|---|---|
| `latitude` | número | obligatorio, -90..90 |
| `longitude` | número | obligatorio, -180..180 |
| `photo` | archivo | JPG/JPEG/PNG/WebP, máx. 5 MB. Entrada/salida: **obligatoria solo si `selfie_required = true`**. Descansos: siempre opcional. El servidor la guarda como WebP (calidad 80, lado mayor ≤ 1280 px) |
| `accuracy_meters` | número | opcional, 0..100000 |
| `is_mocked` | booleano (`1`/`0`) | opcional; `position.isMocked` |
| `face_detected` | booleano | opcional; resultado de ML Kit en el teléfono |
| `device_time` | fecha ISO 8601 **con zona** | opcional; reloj del teléfono en el momento de la marcación (p. ej. `2026-10-09T08:02:11-05:00`). Se guarda en UTC |
| `offline_recorded_at` | fecha ISO 8601 | opcional; hora real de una marcación hecha sin conexión. No puede estar más de 2 min en el futuro. **También en descansos** |
| `device_sent_at` | fecha ISO 8601 | opcional; reloj del teléfono al enviar (útil en `sync-batch`, ver desfase) |
| `client_uuid` | UUID | opcional (obligatorio en `sync-batch`); idempotencia, único por empresa |
| `device_id` | texto | opcional, máx. 255 |
| `device_os` | `android` \| `ios` | opcional |
| `app_version` | texto | opcional, máx. 40 |

### Qué valida el backend, en orden

1. **Idempotencia:** si la empresa ya tiene un evento con ese `client_uuid` (sin distinguir
   mayúsculas), responde `200` con `duplicate: true` y la jornada/evento existentes; no crea nada
   ni vuelve a validar. Si el `client_uuid` es de **otra persona** → `409 CLIENT_UUID_CONFLICT`.
2. **GPS simulado** (`is_mocked = true`), según `mock_location_policy` (empresa → plataforma →
   `ATTENDANCE_REJECT_MOCK_LOCATION`): `reject` → `422 MOCK_LOCATION_DETECTED`; `flag` → se acepta
   con motivo `MOCK_LOCATION`. Aplica también a los descansos.
3. `accuracy_meters` mayor que la precisión máxima (empresa → plataforma →
   `ATTENDANCE_MAX_GPS_ACCURACY`, 100 m) → `422 GPS_ACCURACY_TOO_LOW`. Sin `accuracy_meters` no se valida.
4. **Rostro** con `face_detection_required = true`: `face_detected = false` → `422 FACE_NOT_DETECTED`;
   en entrada/salida **sin** `face_detected` (con foto o selfie obligatoria) se acepta con motivo
   `FACE_UNVERIFIED`.
5. **Offline** (`offline_recorded_at`): si la empresa no lo permite → `422 OFFLINE_NOT_ALLOWED`; más
   viejo que `offline_max_hours` → `422 OFFLINE_TOO_OLD`. Si pasa, la marcación (también un
   descanso) se guarda con esa hora (la tardanza y los minutos de descanso se calculan con ella),
   con `offline: true`, y queda en auditoría (`attendance.offline_synced`).
6. **Desfase de reloj** (`time_drift_seconds` = teléfono − servidor, en segundos): en línea se mide
   con `device_time`; en una marcación offline solo con `device_sent_at` (si no, `null`). Si supera
   `max_time_drift_seconds` (300 por defecto) se acepta con motivo `TIME_DRIFT`. **Nunca rechaza.**
7. Estado de la jornada: entrada repetida → `409 ALREADY_CLOCKED_IN`; sin sucursal vigente →
   `422 NO_BRANCH_ASSIGNED`; descanso o salida sin jornada abierta → `404 NO_OPEN_ATTENDANCE`
   (una marcación offline busca la jornada del día de `offline_recorded_at`); evento offline
   anterior a la entrada → `422 OFFLINE_BEFORE_CLOCK_IN`; fin de descanso anterior a su inicio →
   `422 OFFLINE_OUT_OF_ORDER`; `409 BREAK_ALREADY_STARTED`, `409 BREAK_NOT_STARTED`,
   `409 BREAK_ALREADY_ENDED`, `422 BREAK_NOT_ALLOWED`.
8. **Geocerca** (Haversine contra la sucursal), solo entrada y salida. La precisión máxima es la
   de la sede (`branches.max_gps_accuracy_meters`) o la de la empresa → `422 GPS_ACCURACY_TOO_LOW`.
   Con distancia d, radio r, precisión a y tolerancia t de la sede (`branches.geofence_tolerance_meters`,
   15 m por defecto, 0 la desactiva):
   - d ≤ r: dentro.
   - r < d ≤ r + min(a, t): **zona de borde**, se acepta con motivo `GEOFENCE_EDGE` y
     `within_geofence: false`, sin aviso ni rechazo.
   - más lejos: política de la sede (`branches.geofence_policy`) o, si está vacía,
     `company_configurations.geofence_policy`:
   - `reject` (por defecto): `422 GEOFENCE_VIOLATION` con el mensaje
     "Estás fuera del área permitida. Distancia: 180m. Máximo permitido: 100m. Necesitas acercarte 80m más."
     y aviso `attendance.outside_geofence` al colaborador y al supervisor (máx. 1 cada 10 min por persona).
   - `flag`: se registra con `within_geofence: false`, motivo `OUT_OF_GEOFENCE`, auditoría
     (`attendance.clock_in_out_of_geofence` / `clock_out_out_of_geofence`) y el mismo aviso.
   En los descansos la distancia se guarda pero no se exige.
9. **Horario:** entrada o salida fuera de la ventana del turno asignado
   (`inicio − tolerancia` .. `fin + tolerancia`; los turnos nocturnos cruzan la medianoche) o en un
   día sin turno (teniendo turnos asignados) → se acepta con motivo `OUTSIDE_SCHEDULE` y aviso
   `attendance.outside_schedule`. Sin turnos asignados no se evalúa.

10. **Ubicación inventada** (no rechaza): `IMPOSSIBLE_TRAVEL` si desde la marcación anterior habría
   que moverse a más de 150 km/h (y más de 2 km); `REPEATED_COORDINATES` si las coordenadas
   coinciden (±1 cm) con una marcación de otro día de los últimos 30 días.

Motivos de sospecha (`suspicion_reasons`): `MOCK_LOCATION`, `TIME_DRIFT`, `FACE_UNVERIFIED`,
`OUT_OF_GEOFENCE`, `GEOFENCE_EDGE`, `OUTSIDE_SCHEDULE`, `UNSAFE_DEVICE`, `IMPOSSIBLE_TRAVEL`,
`REPEATED_COORDINATES`. Con al menos uno el evento queda `suspicious: true` y la
jornada `is_suspicious: true`.

### Respuesta

Entrada nueva `201`; descansos, salida y duplicados `200`:

```json
{
  "success": true,
  "duplicate": false,
  "message": "✅ Entrada registrada correctamente.",
  "data": {
    "id": 120,
    "work_date": "2026-10-09",
    "status": "late",
    "status_label": "Tardanza",
    "is_open": true,
    "is_complete": false,
    "is_on_break": false,
    "is_suspicious": false,
    "clock_in": {
      "time": "08:12:31",
      "timestamp": "2026-10-09T08:12:31-05:00",
      "latitude": -12.0912,
      "longitude": -77.0245,
      "distance_meters": 23,
      "within_geofence": true,
      "photo_url": "https://<host>/secure-files/selfie-in/120?expires=...&signature=..."
    },
    "break": { "start_time": null, "end_time": null, "duration_min": null },
    "summary": { "late_minutes": 2, "break_minutes": null, "worked_hours": 0, "overtime_hours": 0 },
    "justification": { "status": null, "notes": null, "has_file": false },
    "branch": { "id": 1, "name": "Sede Principal - San Isidro", "code": "SED-01" },
    "shift": { "id": 1, "name": "Turno Mañana", "start_time": "08:00:00", "end_time": "17:00:00" }
  },
  "punch": {
    "id": 311,
    "type": "clock_in",
    "client_uuid": "0d6c1c0e-7a2b-4c39-9a51-3e6d1b2f8a10",
    "attendance_id": 120,
    "server_time": "2026-10-09T13:12:31+00:00",
    "device_time": "2026-10-09T13:12:27+00:00",
    "time_drift_seconds": -4,
    "offline": false,
    "offline_recorded_at": null,
    "latitude": -12.0912,
    "longitude": -77.0245,
    "accuracy_meters": 8.5,
    "distance_meters": 23,
    "within_geofence": true,
    "address": null,
    "face_detected": true,
    "mock_location_detected": false,
    "suspicious": false,
    "suspicion_reasons": [],
    "photo_url": "https://<host>/secure-files/punch-photo/311?expires=...&signature=..."
  }
}
```

- `punch.address` lo completa después el job `ReverseGeocodePunch`; en la respuesta inmediata
  suele venir `null`.
- Las horas de `punch` van en UTC; las de `data`, en la zona de la empresa.
- Mensajes: fuera del radio con `flag` "⚠️ Entrada registrada fuera del radio de la sucursal
  (180 m). RRHH la revisará."; descansos "☕ Descanso iniciado." / "🔄 Regreso de descanso
  registrado."; salida "🏁 Salida registrada. Horas trabajadas hoy: 8.5h."; duplicado "Esta
  marcación ya estaba registrada.".
- `photo_url` es una URL firmada que vence a los `SIGNED_URL_MINUTES` (5) minutos (15 en `punch`),
  o `null` si no hubo foto.

Horas trabajadas al marcar la salida: de la entrada a la salida, menos `break_minutes`, salvo que
"Refrigerio pagado" (`lunch_paid` efectivo: empresa → plataforma → `false`) esté activo; en ese
caso el descanso no se descuenta. La hora de cada evento es la del servidor en la **zona horaria
de la empresa** (`tenant_timezone()`), o `offline_recorded_at` si vino.

### `POST /api/v1/attendances/sync-batch`

Alias `POST /api/attendances/sync-batch`. Permiso `attendance.mark`, `throttle:30,1`. Sirve para
vaciar la cola offline de la app en un solo envío (`AttendanceSyncController`).

`multipart/form-data`:

| Campo | Reglas |
|---|---|
| `punches[i][type]` | obligatorio: `clock_in`, `break_start`, `break_end`, `clock_out` |
| `punches[i][client_uuid]` | **obligatorio**, UUID |
| `punches[i][latitude]`, `punches[i][longitude]` | obligatorios |
| `punches[i][accuracy_meters]`, `[device_time]`, `[offline_recorded_at]`, `[is_mocked]`, `[face_detected]`, `[photo]`, `[device_id]`, `[device_os]`, `[app_version]` | como en las rutas individuales |
| `device_sent_at` | opcional, en la raíz: reloj del teléfono al enviar (mide el desfase de las marcaciones offline) |

- Máximo **50** elementos (`punches` vacío o con más de 50: `422 VALIDATION_ERROR` del lote entero).
- Se procesan en **orden cronológico** (`offline_recorded_at`; si no, `device_time`; si no, la hora
  de llegada; empate: entrada < inicio de descanso < fin de descanso < salida), cada uno en **su
  propia transacción**: un rechazo nunca aborta el resto.
- Cada elemento pasa por las mismas reglas que las rutas individuales (incluidas
  `offline_mode_enabled` y `offline_max_hours`, la selfie obligatoria en entrada/salida y la
  geocerca).
- `results` viene en el **mismo orden que `punches[]`**.

```json
{
  "success": true,
  "data": {
    "results": [
      { "client_uuid": "6f1d…", "status": "created", "code": null,
        "message": "🏁 Salida registrada. Horas trabajadas hoy: 8.1h.", "attendance_id": 120, "punch_id": 314 },
      { "client_uuid": "a2c9…", "status": "duplicate", "code": null,
        "message": "Esta marcación ya estaba registrada.", "attendance_id": 120, "punch_id": 311 },
      { "client_uuid": "9be0…", "status": "rejected", "code": "OFFLINE_TOO_OLD",
        "message": "La marcación sin conexión tiene más de 24 h. Pide a RRHH que la registre manualmente.",
        "attendance_id": null, "punch_id": null }
    ]
  },
  "meta": { "created": 1, "duplicates": 1, "rejected": 1 }
}
```

`code` de un rechazo: cualquier código de la tabla de errores de marcación, `VALIDATION_ERROR`
(con el primer mensaje de validación) o `SERVER_ERROR`. La app puede borrar de su cola los
`created` y `duplicate`, y los `rejected` (salvo `SERVER_ERROR`, que conviene reintentar).

### `POST /api/attendance/attempts`

La app reporta una marcación que **ella misma** bloqueó antes de enviarla, para que RRHH la vea
en Asistencia > Intentos rechazados con la etiqueta «Desde la app». Requiere `attendance.mark`;
máximo 30 por minuto. Idempotente por `client_uuid`: `201` si es nuevo, `200 {duplicate: true}` si ya estaba.

| Campo | Regla |
|---|---|
| `client_uuid` | UUID, obligatorio |
| `type` | `clock_in`, `break_start`, `break_end` o `clock_out` |
| `code` | `MOCK_LOCATION_DETECTED`, `GPS_ACCURACY_TOO_LOW` o `GEOFENCE_VIOLATION` |
| `occurred_at` | ISO 8601 con zona; de hace 7 días como máximo |
| `latitude`, `longitude`, `accuracy_meters` | la lectura descartada, opcionales |
| `distance_meters`, `allowed_radius`, `branch_id` | solo fuera del radio; una sede no asignada se cambia por la principal |
| `is_mocked`, `offline`, `device_id`, `device_os`, `app_version` | opcionales |

Al tercer `MOCK_LOCATION_DETECTED` del día (sumando los que rechaza el servidor) se avisa una sola
vez al supervisor de la sede, a administradores y a RRHH (`attendance.mock_repeated`). Los intentos
se borran a los 12 meses (`model:prune` diario). La app guarda en el teléfono hasta 30 reportes que
no pudo enviar (sin señal, 5xx o 429) y los reenvía con el siguiente; un 4xx se descarta.

### `GET /api/geocode/reverse?lat=&lng=`

Con token de la empresa (cualquier rol), `throttle:60,1`. Dirección legible de un punto con el
proveedor efectivo de la empresa (Nominatim o Google):

```json
{ "success": true, "data": { "address": "Av. Javier Prado Este 2578, San Isidro, Lima, Perú", "provider": "nominatim" } }
```

`address` es `null` si el proveedor no encontró nada. Proveedor caído:
`503 GEOCODING_UNAVAILABLE`. Coordenadas inválidas: `422 VALIDATION_ERROR`.

### `GET /api/attendance/today`

Permiso `attendance.view_own`. Devuelve la marcación de hoy (o `null`) y qué botón mostrar:

```json
{
  "success": true,
  "data": null,
  "next_action": { "action": "clock_in", "label": "Marcar Entrada", "icon": "login" },
  "server_time": "2026-10-09T07:55:00-05:00",
  "timezone": "America/Lima"
}
```

`next_action.action`: `clock_in`, `break_or_clock_out` (con `actions` = iniciar descanso / marcar
salida), `break_end`, `clock_out` o `completed`.

### `GET /api/attendance/history?month=10&year=2026`

Permiso `attendance.view_own`. `month` 1–12 y `year` ≥ 2020 (por defecto el mes actual).
Respuesta: `period`, `summary` (`total_days`, `present`, `late`, `absent`, `total_hours`,
`overtime_hours`, `late_minutes`), `data` (lista de marcaciones como arriba) y `meta`
(`current_page`, `total`). Pagina de a 31.

### `POST /api/attendance/{id}/justification`

Multipart: `notes` (obligatorio, máx. 1000) y `file` opcional (PDF/JPG/PNG, 5 MB). Deja la
justificación `pending`. `409 CONFLICT` si ya estaba aprobada.

## Turnos y sucursales

| Ruta | Permiso | Devuelve |
|---|---|---|
| `GET /api/my-shifts` | `shifts.view_own` | Asignaciones vigentes: `effective_from`, `effective_until`, `rotation_anchor_date`, `branch`, `shift`. |
| `GET /api/schedule?from=&to=` | `shifts.view_own` | Un elemento por día con `date`, `is_workday` y `shift`. Máx. 92 días (`422 RANGE_TOO_LARGE`). |
| `GET /api/my-branches` | autenticado | Sucursales vigentes y activas del usuario. |
| `GET /api/admin/branches/{id}` | `branches.manage` | Detalle de la sede con `staffing`: por cargo, `planned` (plazas previstas), `actual` (personal activo) y `gap` (> 0 faltan, < 0 sobran). |

## Cargos

Los cargos (Atención al cliente, Almacenero, Chofer, Estibador...) se administran en el panel, en
Organización, junto con la plantilla de cada sede. El perfil (`GET /api/employee/profile`,
`data.user`) y `GET /api/auth/me` traen `position` (nombre) y `position_id`.

| Ruta | Permiso | Devuelve |
|---|---|---|
| `GET /api/admin/positions` | `users.manage` o `branches.manage` | Cargos activos: `id`, `name`, `code`, `description`, `department {id, name}`, `is_roaming`. `?include_inactive=1` agrega los inactivos. |
| `PUT /api/admin/employees/{id}` | `users.manage` | Acepta `position_id`; el nombre del cargo se copia en `position`. |

## Solicitudes (vacaciones, permisos, licencias)

Permiso `leave.request`.

| Ruta | Detalle |
|---|---|
| `GET /api/leave-requests?status=&type=` | Mis solicitudes, 20 por página. |
| `POST /api/leave-requests` | `type` (`vacation`, `permission`, `medical_leave`), `start_date`, `end_date` (≥ inicio), `start_time`/`end_time` opcionales (`HH:MM`), `reason` (obligatorio, máx. 1000), `attachment` (PDF/JPG/PNG, 5 MB; **obligatorio para `medical_leave`**). `201`. `409 OVERLAPPING_REQUEST` si choca con otra pendiente o aprobada. |
| `GET /api/leave-requests/{id}` | Una solicitud propia. |
| `POST /api/leave-requests/{id}/cancel` | Solo pendientes; si no, `409 NOT_PENDING`. |

Formato de cada solicitud:

```json
{
  "id": 14, "type": "vacation", "type_label": "Vacaciones",
  "start_date": "2026-11-02", "end_date": "2026-11-06", "start_time": null, "end_time": null,
  "days": 5, "reason": "Viaje familiar", "has_attachment": false,
  "status": "pending", "review_notes": null, "reviewed_at": null, "reviewer": null,
  "created_at": "2026-10-09T10:00:00-05:00"
}
```

Al crearla se notifica al supervisor de la sucursal y a RRHH; al aprobarla o rechazarla, al
colaborador (eventos de modelo en `AppServiceProvider`).

### Aprobaciones (supervisor, RRHH, admin)

Permiso `leave.approve_team` o `leave.approve_all`. El supervisor solo ve solicitudes de
colaboradores de sus sucursales; con `leave.approve_all`, toda la empresa.

| Ruta | Detalle |
|---|---|
| `GET /api/approvals/leave-requests?status=pending&type=&user_id=` | Bandeja (por defecto `pending`), con `employee: {id, name, employee_code}`. |
| `POST /api/approvals/leave-requests/{id}/approve` | `notes` opcional. |
| `POST /api/approvals/leave-requests/{id}/reject` | `notes` **obligatorio**. |

Errores: `403 SELF_APPROVAL` (nadie aprueba lo suyo), `409 NOT_PENDING`.

## Supervisor: mi equipo

Permiso `attendance.view_team` (supervisor, RRHH y admin).

| Ruta | Detalle |
|---|---|
| `GET /api/supervisor/team` | Personal activo que marca (roles `employee` y `supervisor`) visible para el usuario, sin él mismo, como `UserResource`. Es el mismo equipo que cuenta `attendance/live` (`User::teamOf`). |
| `GET /api/supervisor/attendance/live` | Marcaciones de hoy del equipo + `summary` (`team_size`, `present`, `late`, `on_break`, `clocked_out`, `absent`). |
| `GET /api/supervisor/attendance?from=&to=&user_id=&status=` | Reporte paginado (50), por defecto desde el inicio de mes. Incluye también a los integrantes ya inactivos (historial). |

## Boletas

Permiso `payslips.view_own` o `payslips.manage`. Si la empresa apagó "Boletas de pago", el
colaborador recibe `403 MODULE_DISABLED`.

| Ruta | Detalle |
|---|---|
| `GET /api/payslips` | Mis boletas **publicadas**, de la más nueva a la más vieja: `id`, `title`, `period_month`, `period_year`, `period_label`, `status`, `is_read`, `read_at`, `published_at`. |
| `GET /api/payslips/{id}` | Detalle con `financials` (`gross_salary`, `deductions`, `net_salary`, `breakdown`) y `file` (`name`, `size_kb`). La marca como leída. |
| `GET /api/payslips/{id}/download` | El PDF (`application/pdf`, `Cache-Control: private, no-store`). `404 FILE_NOT_FOUND` si falta el archivo. |
| `POST /api/payslips/{id}/read` | Marca como leída. |

## Legajo, datos de pago y dotación

Permiso `profile.manage_own`. Cada sección respeta los interruptores de `/settings/mobile`
(`403 MODULE_DISABLED` si está apagada).

| Ruta | Detalle |
|---|---|
| `GET /api/employee/config` | `modules`, `permissions` (`upload_documents`, `edit_payment_info`, `edit_uniforms`), `required_documents`, `max_upload_mb`. |
| `GET /api/employee/profile` | `user`, `profile` (datos de pago enmascarados), `uniforms`, `documents` (`items`, `required`, `missing`), `onboarding` (`steps`, `progress`), `config`, `catalogs`. |
| `PUT /api/employee/payment-info` | `bank_name`, `bank_account_type`, `bank_account_currency` (3 letras), `bank_account_number`, `interbank_code`, `pension_system`, `pension_fund` (obligatorio si AFP), `pension_code`, `health_insurance`. `423 PAYMENT_INFO_LOCKED` si RRHH ya verificó la cuenta y la empresa bloquea los datos verificados. |
| `PUT /api/employee/contact` | `address`, `district`, `emergency_contact_name`, `emergency_contact_phone`. |
| `GET /api/employee/documents` | Mis documentos vigentes. |
| `POST /api/employee/documents` | Multipart, `throttle:20,1`: `type` (`dni`, `cv`, `proof_of_address`, `certificate`, `other`), `file` (PDF/JPG/PNG, máx. `max_upload_mb`, por defecto 5 MB; se valida el tipo real y se guarda su SHA-256), `title` opcional, `expires_at` opcional (fecha futura). `201` "Documento enviado. RRHH lo revisará." |
| `GET /api/employee/documents/{id}/url` | `{url, expires_in, mime_type}`: URL firmada temporal (sin token). |
| `DELETE /api/employee/documents/{id}` | Solo `pending` o `rejected`; si no, `409 DOCUMENT_LOCKED`. |
| `GET /api/employee/uniform-sizes` | `sizes` y `deliveries`. |
| `PUT /api/employee/uniform-sizes` | `shirt_size`, `pants_size`, `shoe_size` (obligatorios), `shoe_type`, `notes`. |

Formato de un documento (`EmployeeDocument::toApi`): `id`, `type`, `type_label`, `title`,
`original_name`, `mime_type`, `size_kb`, `sha256`, `status` (`pending`, `approved`, `rejected`,
`superseded`), `status_label`, `rejection_reason`, `expires_at`, …

## Incorporación

Un ingreso nuevo no puede marcar hasta que completa sus etapas y RRHH aprueba
(`users.onboarding_status`, también en `GET /api/auth/me` como `onboarding_status`).
Quien ya marcaba antes de este cambio queda `approved`. Si la empresa apaga el módulo
Onboarding, no hay candado.

| Ruta | Detalle |
|---|---|
| `GET /api/onboarding` | `status` (`pending`, `submitted`, `approved`, `rejected`), `can_punch`, `can_submit`, `next_stage`, `rejection_reason`, `submitted_at`, `approved_at` y `stages[]`: `key` (`personal_data`, `uniforms`, `documents`, `induction`), `title`, `description`, `required`, `status` (`completed`, `pending`, `not_applicable`), `missing[]` (textos listos para mostrar). |
| `POST /api/onboarding/submit` | Envía a RRHH cuando las etapas obligatorias están completas (`throttle:10,1`). `422` con `errors.onboarding` si falta una etapa o ya fue enviada. |

Cada etapa se completa con las rutas de [Legajo, datos de pago y dotación](#legajo-datos-de-pago-y-dotación)
y de capacitaciones; los documentos exigidos son `required_documents` de `GET /api/employee/config`.
Avisos: `onboarding.approved` y `onboarding.rejected` al colaborador (`data.screen = onboarding`).

## Canal ético

Denuncias anónimas. Estas rutas **no usan Sanctum**: la app las llama sin `Authorization`
aunque tenga sesión, y el servidor descarta IP, user agent y encabezados del teléfono antes
del controlador (`StripIdentity`). La empresa sale de `X-Tenant-Slug`. Límite por minuto
(por hash de IP en caché): 5 denuncias, 20 consultas, 10 mensajes → `429 TOO_MANY_ATTEMPTS`.

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/ethics/categories` | Tipos de denuncia (`key`, `label`). |
| POST | `/api/ethics/cases` | `category`, `subject` (5–150), `body` (20–5000), `push_channel?` (16–64, `[a-zA-Z0-9_-]`), `photos[]?` (máx. 3 imágenes de 5 MB). `201` con `data.code` (`ET-XXXXXX`) y `data.token` (6 grupos de 4), que **no se vuelve a mostrar**. |
| GET | `/api/ethics/cases/{code}` | Cabecera `X-Ethics-Token`. Estado, conversación y conclusión (si está cerrada). `404 ETHICS_CASE_NOT_FOUND` si el código o el token no coinciden. |
| POST | `/api/ethics/cases/{code}/messages` | Cabecera `X-Ethics-Token`. `body`, `photos[]?`. `422` si la denuncia está cerrada. |

Avisos: si la denuncia lleva `push_channel`, la app se suscribe al tema FCM `ethics_<push_channel>`
y el servidor publica ahí `{type: ethics.update}` cuando quien investiga responde o cambia el estado.
Las fotos se re-codifican a JPEG en el servidor (sin EXIF). El token solo se guarda como SHA-256;
asunto, detalle y mensajes van cifrados con la `APP_KEY`.

## Notificaciones in-app

Cualquier rol autenticado.

| Ruta | Detalle |
|---|---|
| `GET /api/notifications?page=` | 20 por página; `meta` con `current_page`, `last_page`, `total`, `unread_count`. |
| `POST /api/notifications/{id}/read` | `id` es UUID. Devuelve la notificación y `unread_count`. |
| `POST /api/notifications/read-all` | `unread_count: 0`. |

```json
{
  "id": "9b1c...-uuid",
  "type": "leave_request.approved",
  "title": "Solicitud aprobada",
  "body": "Vacaciones del 02/11/2026 al 06/11/2026: aprobada.",
  "data": { "leave_request_id": 14, "status": "approved", "screen": "leave_requests" },
  "read_at": null,
  "created_at": "2026-10-09T10:05:00-05:00"
}
```

(Ejemplo de `app/Modules/HR/Notifications/LeaveRequestReviewed.php`; los demás avisos están en
`app/Modules/*/Notifications/` y los dispara `app/Modules/Core/Services/NotificationDispatcher.php`.) Si la empresa
configuró un "Endpoint de notificaciones" en Marca y app, cada aviso creado también se reenvía por
POST firmado a ese endpoint (`notification.created`, ver
[white-label.md](../architecture/white-label.md#integraciones-webhook-y-endpoint-de-notificaciones)).

Tipos de aviso de asistencia (además de `leave_request.*`, `justification.*`, `document.*`,
`payslip.*`):

| `type` | Cuándo | `data` |
|---|---|---|
| `attendance.outside_schedule` | Entrada o salida aceptada fuera de la ventana del turno (motivo `OUTSIDE_SCHEDULE`) | `attendance_id`, `punch_id`, `punch_type`, `user_id`, `work_date`, `screen` (`attendance`; `team_attendance` para el supervisor) |
| `attendance.mock_repeated` | Tercer intento del día con ubicación simulada (solo a supervisor, administradores y RRHH; in-app, sin push) | `user_id`, `attempts`, `screen` (`attendance_attempts`) |
| `attendance.outside_geofence` | Entrada o salida lejos de la sucursal: rechazada (`rejected: true`, `GEOFENCE_VIOLATION`) o aceptada con `flag` | `punch_type`, `branch_id`, `distance_meters`, `allowed_radius`, `rejected`, `screen` y, si se aceptó, `attendance_id`, `punch_id` |

El colaborador los recibe in-app **y por push**; el supervisor de la sucursal, solo in-app.

### Push (FCM)

El backend envía push con Firebase Cloud Messaging (API HTTP v1, `App\Modules\Integrations\Services\Push\FcmSender`)
**cada vez que crea un aviso in-app** para un usuario que:

1. tiene `users.fcm_token` (lo manda la app en el login o con `POST /api/auth/refresh-fcm`), y
2. es de una empresa con `fcm_enabled` efectivo (`mobile.fcm_enabled` de la config).

Mensaje enviado:

```json
{
  "message": {
    "token": "<fcm_token>",
    "notification": { "title": "Solicitud aprobada", "body": "Vacaciones del 02/11/2026 al 06/11/2026: aprobada." },
    "data": { "type": "leave_request.approved", "notification_id": "9b1c...-uuid",
              "leave_request_id": "14", "status": "approved", "screen": "leave_requests" },
    "android": { "priority": "high", "notification": { "channel_id": "tunesa_default" } },
    "apns": { "headers": { "apns-priority": "10" }, "payload": { "aps": { "sound": "default" } } }
  }
}
```

- `data` lleva `type`, `notification_id` (el id del aviso in-app, para marcarlo leído con
  `POST /api/notifications/{id}/read`) y el mismo `data` del aviso, **todo como strings**
  (booleanos `"true"`/`"false"`).
- Canal Android: `tunesa_default` (`FCM_ANDROID_CHANNEL_ID`): la app debe crearlo al iniciar.
- Si FCM responde que el token ya no existe (`404` / `UNREGISTERED`) el backend borra
  `users.fcm_token`; la app debe volver a enviarlo con `refresh-fcm` cuando Firebase le dé uno nuevo.
- El envío va en cola (`SendPushNotification`, 3 intentos ante errores de red/429/5xx).

Configuración de Firebase para la app (la hace el equipo móvil con `--dart-define`):
`FIREBASE_API_KEY`, `FIREBASE_APP_ID`, `FIREBASE_MESSAGING_SENDER_ID`, `FIREBASE_PROJECT_ID`,
`FIREBASE_IOS_APP_ID`, `FIREBASE_IOS_BUNDLE_ID`. Pasos completos en
[attendance-integrity.md](../architecture/attendance-integrity.md#push-fcm-puesta-en-marcha).

## Notas y diferencias conocidas

- El objeto `user.permissions` de login/me se calcula por **rol** fijo en `UserResource`, no con
  la matriz de permisos de la base de datos.
- `NOT_FOUND` / `UNAUTHORIZED`: Laravel convierte `ModelNotFoundException` y
  `AuthorizationException` en `NotFoundHttpException` / `AccessDeniedHttpException` antes de
  llamar a los renderers, así que `bootstrap/app.php` atiende esas excepciones convertidas y mira
  `getPrevious()` (`tests/Feature/ApiExceptionRenderingTest.php`). Una ruta inexistente o un
  `abort(404/403)` sin excepción original siguen con la respuesta estándar de Laravel (sin `code`).

## Comportamiento de la app

Verificado contra `mobile/lib` (rutas entre paréntesis).

- **Arranque** (`core/config/app_config_controller.dart`): pinta al instante la última
  configuración guardada (`SharedPreferences`, por slug) y la refresca en segundo plano con
  `GET /api/v1/app/config?tenant_slug=` al abrir y al volver a la app. Sin caché espera como
  máximo 4 s en el splash. Si la ruta nueva responde 404 sin código (backend viejo) usa
  `GET /api/tenant/branding`. Sin red conserva la caché o los valores por defecto.
- **Tema** (`app.dart`, `core/theme/app_theme.dart`): `ThemeData` claro y oscuro con los cuatro
  colores de `branding`; el logo usa `logo_dark_url` en tema oscuro (si falta, `logo_url`).
  `splash_url` se guarda pero **la app todavía no lo muestra** (el splash usa el logo).
- **Versión** (`core/network/api_client.dart`, `core/config/version_gate.dart`): manda
  `X-App-Version` en todas las peticiones. Bloquea con la pantalla de actualización si recibe un
  `426 APP_UPDATE_REQUIRED` o si su versión es menor que `min_supported_version` con
  `force_update`; si es menor sin forzar, o menor que `latest_version`, muestra un aviso
  descartable.
- **Cola offline** (`features/attendance/data/offline_clock_queue.dart`): con
  `offline_mode_enabled`, si no hay señal (o se cae al enviar) guarda la marcación cifrada en el
  teléfono (`flutter_secure_storage`; la selfie se copia a los documentos de la app) y la envía
  después con `offline_recorded_at`, al recuperar señal, al volver a la app o al deslizar para
  actualizar. Decide por **HTTP status**, no por `code`: reintenta en errores de red, 5xx y
  401/408/425/426/429; cualquier otro rechazo (por ejemplo `422 OFFLINE_TOO_OLD`,
  `OFFLINE_NOT_ALLOWED`, `OFFLINE_BEFORE_CLOCK_IN`) la descarta y avisa. Las que superan
  `offline_max_hours` se descartan en el teléfono sin enviarse. Solo envía las del usuario con
  sesión iniciada.
- **Descansos offline:** la app también encola inicio/fin de descanso; el backend ahora respeta
  `offline_recorded_at` también en esas rutas (y en `sync-batch`).
- **Selfie y rostro** (`clock_screen.dart`, `face_detection_service.dart`): la selfie solo se pide
  y se envía en entrada/salida cuando `selfie_required`. Con `face_detection_required`, ML Kit
  analiza la foto en el teléfono; si no encuentra rostro el botón de enviar queda bloqueado, y
  si ML Kit no puede analizarla no se envía `face_detected` (el backend no rechaza: la marca como
  sospechosa con `FACE_UNVERIFIED`).
- **Precisión GPS:** la app toma hasta 3 lecturas en 8 s (corta antes si una baja de ±10 m), envía
  la más precisa y la trata como simulada si cualquiera lo fue. Exige la precisión más permisiva
  entre `max_gps_accuracy_meters` de la empresa y el de sus sedes (`/api/my-branches`,
  `geofence.max_gps_accuracy_meters`; respaldo: `MAX_GPS_ACCURACY` compilado) y manda
  `accuracy_meters` e `is_mocked` también en los descansos. Su verificación previa de geocerca usa
  la misma zona de borde que el servidor (`geofence.tolerance_meters`).
- **Errores de marcación** (`_SendErrorCard`): ofrece volver a leer el GPS ante
  `GEOFENCE_VIOLATION`, `MOCK_LOCATION_DETECTED` y `GPS_ACCURACY_TOO_LOW`, y pide repetir la
  selfie ante `FACE_NOT_DETECTED`. Los demás códigos solo muestran `message`.
- **Conexión del servidor** (`features/server/server_connection_dialog.dart`): diálogo oculto que
  se abre manteniendo presionado el logo de la pantalla de código de empresa o de login. Ver
  [mobile-cloudflare-tunnel.md](../deployment/mobile-cloudflare-tunnel.md#conexión-del-servidor).
