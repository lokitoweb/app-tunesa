# API para la app móvil

Contrato entre la app Flutter (`mobile/`) y el backend. Fuente de verdad: `routes/api.php`, los
controladores de `app/Http/Controllers/Api/`, los FormRequest de `app/Http/Requests/Api/` y los
Resources de `app/Http/Resources/`. Los ejemplos están armados a partir de esos archivos y de
los datos demo del seeder.

## Base URL y cabeceras

- Base: `<API_BASE_URL>/api` (por ejemplo `https://xxxx.trycloudflare.com/api`). La app arma esa
  URL en `Env.apiUrl` (`mobile/lib/core/config/env.dart`) y acepta el dominio con o sin `/api`.
- **No hay prefijo de versión**, salvo el alias `GET /api/v1/app/config`.

| Cabecera | Cuándo | Para qué |
|---|---|---|
| `Accept: application/json` | siempre | Respuestas JSON (incluidos los errores). |
| `X-Tenant-Slug: empresa-demo` | todas las rutas de empresa | Identifica la empresa (`ResolveTenant`). Sin ella: `404 TENANT_NOT_FOUND`. |
| `Authorization: Bearer <token>` | rutas protegidas | Token Sanctum del login. |
| `X-App-Version: 1.2.3` | rutas protegidas (opcional) | Si es menor que la versión mínima y la empresa fuerza actualizar: `426 APP_UPDATE_REQUIRED`. Sin la cabecera no se bloquea. No aplica a `/api/auth/*` ni a la config. |
| `Content-Type: multipart/form-data` | clock-in/out, documentos, solicitudes con adjunto | Subida de archivos. |

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
| 404 | `NO_OPEN_ATTENDANCE` | Descanso o salida sin jornada abierta. |
| 409 | `CONFLICT`, `OVERLAPPING_REQUEST`, `NOT_PENDING`, `DOCUMENT_LOCKED` | Estado incompatible. |
| 409 | `ALREADY_CLOCKED_IN`, `BREAK_ALREADY_STARTED`, `BREAK_NOT_STARTED`, `BREAK_ALREADY_ENDED` | Estado de la jornada incompatible (ver [Marcación](#marcación)). |
| 423 | `PAYMENT_INFO_LOCKED` | Datos de pago ya verificados por RRHH y bloqueados. |
| 422 | `VALIDATION_ERROR` | Validación de campos. |
| 422 | `GEOFENCE_VIOLATION`, `NO_BRANCH_ASSIGNED`, `BREAK_NOT_ALLOWED`, `MOCK_LOCATION_DETECTED`, `GPS_ACCURACY_TOO_LOW`, `FACE_NOT_DETECTED`, `OFFLINE_*` | Reglas de marcación, ver [Marcación](#marcación). |
| 423 | `ACCOUNT_LOCKED` | Demasiados intentos fallidos (`LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCK_MINUTES`). |
| 426 | `APP_UPDATE_REQUIRED` | Versión de la app por debajo de la mínima con `force_update`. |
| 429 | `TOO_MANY_ATTEMPTS` | Ráfaga de intentos de login (`LoginGuard`). Los límites `throttle:` de las rutas devuelven el 429 estándar de Laravel, sin `code`. |
| 500 | `SERVER_ERROR` | Error inesperado al marcar (se registra en el log; el mensaje no expone detalles internos). |

Los errores de la marcación salen de `App\Exceptions\AttendanceException` (código + HTTP
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
      "geofence_strict_mode": true
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
- `modules` dice qué secciones se ven; los permisos de edición (`upload_documents`,
  `edit_payment_info`, `edit_uniforms`), `required_documents` y `max_upload_mb` están en
  `GET /api/employee/config`.

Errores: `404 TENANT_NOT_FOUND` ("No encontramos una empresa activa con ese código.").

La ruta anterior `GET /api/tenant/branding` (pública, con `X-Tenant-Slug` obligatorio) sigue
existiendo y es la que usa hoy la app (`ApiEndpoints.branding`).

## Autenticación

Rutas públicas (con `X-Tenant-Slug`): `login` (`throttle:10,1`), `forgot-password` y
`reset-password` (`throttle:5,1`).

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

Todas requieren el permiso `attendance.mark` (colaborador y supervisor).

### `POST /api/attendance/clock-in` y `POST /api/attendance/clock-out`

`multipart/form-data` (`ClockInRequest`, `ClockOutRequest` y el trait
`Concerns/MarksWithTenantSettings`):

| Campo | Tipo | Reglas |
|---|---|---|
| `latitude` | número | obligatorio, -90..90 |
| `longitude` | número | obligatorio, -180..180 |
| `photo` | archivo | JPG/JPEG/PNG/WebP, máx. 5 MB. **Obligatorio solo si `selfie_required = true`**; si no, opcional |
| `accuracy_meters` | número | opcional, ≥ 0 (en clock-in también ≤ 10000) |
| `is_mocked` | booleano (`1`/`0`) | opcional; la app lo toma de `position.isMocked` |
| `device_id` | texto | opcional, máx. 255 |
| `device_os` | `android` \| `ios` | opcional |
| `app_version` | texto | opcional, máx. 20 |
| `offline_recorded_at` | fecha ISO 8601 | opcional; hora real de una marcación hecha sin conexión. No puede estar más de 2 min en el futuro |
| `face_detected` | booleano | opcional; resultado de la detección de rostro de la app |

Qué valida el backend, en orden (`AttendanceController::rejectUntrustedLocation`,
`resolveOfflineTime` y `AttendanceService`):

1. `is_mocked = true` y `ATTENDANCE_REJECT_MOCK_LOCATION=true` → `422 MOCK_LOCATION_DETECTED`.
2. `accuracy_meters` mayor que la precisión máxima (empresa → plataforma →
   `ATTENDANCE_MAX_GPS_ACCURACY`, 100 m) → `422 GPS_ACCURACY_TOO_LOW`. Si no se envía
   `accuracy_meters`, no se valida.
3. `face_detection_required = true` y la app envió `face_detected = false` →
   `422 FACE_NOT_DETECTED`. Si no envía `face_detected`, no se rechaza: la detección es de la app.
4. Con `offline_recorded_at`: si la empresa no permite offline → `422 OFFLINE_NOT_ALLOWED`; si es
   más vieja que `offline_max_hours` → `422 OFFLINE_TOO_OLD`. Si pasa, la marcación se guarda con
   esa hora (y la tardanza se calcula con ella), con `offline: true` y `synced_at` en el meta, y
   queda en auditoría (`attendance.offline_synced`).
5. Clock-in: si ya marcó entrada ese día → `409 ALREADY_CLOCKED_IN`. Si no tiene sucursal
   vigente → `422 NO_BRANCH_ASSIGNED` "No tienes ninguna sucursal asignada. Contacta a RRHH."
6. Clock-out: sin jornada abierta → `404 NO_OPEN_ATTENDANCE` ("Primero debes marcar tu entrada."). Una
   salida offline busca la jornada del día de `offline_recorded_at`; si es anterior a la entrada
   → `422 OFFLINE_BEFORE_CLOCK_IN`.
7. **Geocerca** (Haversine contra la sucursal): fuera del radio, según
   `company_configurations.geofence_policy`:
   - `reject` (por defecto): `422 GEOFENCE_VIOLATION` con el mensaje
     "Estás fuera del área permitida. Distancia: 180m. Máximo permitido: 100m. Necesitas acercarte 80m más."
   - `flag`: se registra igual con `within_geofence: false`, queda en auditoría
     (`attendance.clock_in_out_of_geofence` / `clock_out_out_of_geofence`) y el mensaje avisa
     que RRHH la revisará.

Respuesta de clock-in (`201`):

```json
{
  "success": true,
  "message": "✅ Entrada registrada correctamente.",
  "data": {
    "id": 120,
    "work_date": "2026-10-09",
    "status": "late",
    "status_label": "Tardanza",
    "is_open": true,
    "is_complete": false,
    "is_on_break": false,
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
  }
}
```

Fuera del radio con política `flag` el mensaje es
"⚠️ Entrada registrada fuera del radio de la sucursal (180 m). RRHH la revisará.". Clock-out
responde `200` con "🏁 Salida registrada. Horas trabajadas hoy: 8.5h." y el bloque `clock_out`.
`photo_url` es una URL firmada que vence a los `SIGNED_URL_MINUTES` (5) minutos, o `null` si no
hubo selfie.

### Descansos

`POST /api/attendance/break-start` y `POST /api/attendance/break-end` (JSON o form):
`latitude`, `longitude` obligatorios; opcionales `accuracy_meters`, `is_mocked`, `device_id`,
`device_os`, `app_version`. No piden foto ni exigen geocerca, pero aplican **los mismos controles
anti-fraude que la entrada**: `422 MOCK_LOCATION_DETECTED` y `422 GPS_ACCURACY_TOO_LOW` (precisión
máxima efectiva de Marca y app). La distancia a la sucursal y los metadatos del dispositivo se
guardan en `clock_in_meta.breaks.start` / `.end` (solo informativo).

La hora es la del servidor en la **zona horaria de la empresa** (`tenant_timezone()`), igual que
la entrada y la salida, y `break_minutes` se calcula con esas horas. No admiten modo offline: un
`offline_recorded_at` enviado a estas rutas se ignora y el descanso queda con la hora en que llega
al servidor (ver nota en "Comportamiento de la app").

Errores: `404 NO_OPEN_ATTENDANCE` sin jornada abierta; `409 BREAK_ALREADY_STARTED` "Ya iniciaste
tu descanso.", `409 BREAK_NOT_STARTED` "No has iniciado un descanso.", `409 BREAK_ALREADY_ENDED`
"Ya finalizaste tu descanso."; `422 BREAK_NOT_ALLOWED` "Tu turno no permite descansos.".

Horas trabajadas al marcar la salida: de la entrada a la salida, menos `break_minutes`, salvo que
"Refrigerio pagado" (`lunch_paid` efectivo: empresa → plataforma → `false`) esté activo; en ese
caso el descanso no se descuenta.

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

(Ejemplo de `app/Notifications/LeaveRequestReviewed.php`; los demás avisos están en
`app/Notifications/` y los dispara `app/Services/NotificationDispatcher.php`.) Push por FCM **no está implementado**: el backend guarda el token
(`refresh-fcm`) pero no envía push. Si la empresa configuró un "Endpoint de notificaciones" en
Marca y app, cada aviso creado también se reenvía por POST firmado a ese endpoint
(`notification.created`, ver [white-label.md](../architecture/white-label.md#integraciones-webhook-y-endpoint-de-notificaciones)).

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
- **Descansos offline:** la app también encola inicio/fin de descanso, pero el backend ignora
  `offline_recorded_at` en esas rutas: el descanso queda con la hora de sincronización.
- **Selfie y rostro** (`clock_screen.dart`, `face_detection_service.dart`): la selfie solo se pide
  y se envía en entrada/salida cuando `selfie_required`. Con `face_detection_required`, ML Kit
  analiza la foto en el teléfono; si no encuentra rostro el botón de enviar queda bloqueado, y
  si ML Kit no puede analizarla no se envía `face_detected` (el backend no rechaza).
- **Precisión GPS:** la app exige `max_gps_accuracy_meters` antes de enviar (respaldo:
  `MAX_GPS_ACCURACY` compilado) y manda `accuracy_meters` e `is_mocked` también en los descansos.
- **Errores de marcación** (`_SendErrorCard`): ofrece volver a leer el GPS ante
  `GEOFENCE_VIOLATION`, `MOCK_LOCATION_DETECTED` y `GPS_ACCURACY_TOO_LOW`, y pide repetir la
  selfie ante `FACE_NOT_DETECTED`. Los demás códigos solo muestran `message`.
- **Conexión del servidor** (`features/server/server_connection_dialog.dart`): diálogo oculto que
  se abre manteniendo presionado el logo de la pantalla de código de empresa o de login. Ver
  [mobile-cloudflare-tunnel.md](../deployment/mobile-cloudflare-tunnel.md#conexión-del-servidor).
