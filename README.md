# TuneSA

SaaS multi-empresa de RRHH y control de asistencias: marcación con GPS y selfie, geocercas por
sucursal, turnos fijos y rotativos, solicitudes (vacaciones, permisos, licencias), boletas de
pago, legajo digital, dotación de uniformes y marca blanca por empresa.

## Arquitectura en corto

- `app/`, `routes/`, `resources/views/`: backend **Laravel 11**. Expone la **API REST** para la
  app (`routes/api.php`, tokens Sanctum) y el **panel web** de RRHH en Blade (`routes/web.php`,
  sesión).
- `mobile/`: app **Flutter** para colaboradores (y supervisores/RRHH para su equipo).
- Multi-tenant: cada tabla tiene `tenant_id`; la empresa se resuelve por `X-Tenant-Slug`,
  subdominio o dominio propio y un global scope filtra todas las consultas.

Documentación completa en [`docs/`](docs/README.md):

- [Arquitectura general](docs/architecture/overview.md): componentes, multi-tenant, roles, módulos.
- [Marca blanca y configuración de la app](docs/architecture/white-label.md).
- [API para la app móvil](docs/api/mobile-endpoints.md).
- [Probar la app en un celular con Cloudflare Tunnel](docs/deployment/mobile-cloudflare-tunnel.md).

El prototipo del panel en Node (`serve_panel.js`, `server/`, `database/data.json`) se retiró: el
panel oficial es el de Laravel. Sigue en el historial de git si hace falta consultarlo.

## Arranque rápido (local)

Requisitos: PHP 8.2+ (con `pdo_sqlite`) y Composer.

```bash
composer install
composer run setup   # crea .env, genera APP_KEY, crea database.sqlite, migra + seed y enlaza storage
php artisan serve
```

- Panel web: http://localhost:8000/login
- API: `http://localhost:8000/api/...` con el header `X-Tenant-Slug: empresa-demo`
- Configuración de la app: `http://localhost:8000/api/v1/app/config?tenant_slug=empresa-demo`
- Usuarios demo: los imprime el seeder al terminar (`database/seeders/DatabaseSeeder.php`). Son
  solo para desarrollo.

Para volver a sembrar desde cero: `php artisan migrate:fresh --seed` (borra todos los datos locales).

La app móvil: ver [`mobile/README.md`](mobile/README.md) (`flutter run --dart-define-from-file=env/dev.json`).

## Pruebas

```bash
php artisan test            # backend (tests/Feature y tests/Unit)
cd mobile && flutter test   # app
```

En GitHub Actions corren solas: **Backend: pruebas** (`.github/workflows/backend-tests.yml`) y
**App móvil: APK** (`.github/workflows/mobile-apk.yml`, que además analiza y compila el APK en
cada PR que toca `mobile/`).

## Despliegue

- Producción del backend: ver la sección siguiente.
- Probar la app en un celular real sin servidor: [Cloudflare Tunnel](docs/deployment/mobile-cloudflare-tunnel.md).
  Detrás de un proxy, `TRUSTED_PROXIES` (por defecto `127.0.0.1,::1`) hace que Laravel genere
  URLs `https://`.
- APK: `mobile/scripts/build_apk.sh <entorno> [release|debug]` o el workflow **App móvil: APK**
  (entornos `prod`, `staging`, `tunnel`, `dev`; input opcional `api_base_url`).

## Producción

1. `.env` propio con `APP_ENV=production`, `APP_DEBUG=false`, un `APP_KEY` nuevo (`php artisan key:generate`) y credenciales nuevas de base de datos y correo. No reutilices ninguna clave de un `.env` que haya estado en el repositorio. Con HTTPS activa `SESSION_SECURE_COOKIE=true`, pon en `SANCTUM_STATEFUL_DOMAINS` los dominios del panel y configura `MAIL_MAILER` (SMTP u otro) para que lleguen los correos de recuperación de contraseña (ver `.env.example`). `APP_KEY` también cifra los secretos de "Marca y app" (webhook, clave de mapas): si la cambias, hay que volver a cargarlos.
2. `php artisan migrate --force`
3. Super Admin inicial: define `SEED_SUPERADMIN_EMAIL` y `SEED_SUPERADMIN_PASSWORD` (12+ caracteres) y corre `php artisan db:seed --force`. En producción el seeder no crea la empresa demo ni usuarios con contraseñas conocidas; luego borra la contraseña del `.env`. Al entrar se pedirá cambiarla.
4. Asigna el supervisor de cada sucursal (Sucursales → Editar): define a quién ve cada supervisor.
5. `php artisan storage:link` para servir logos, favicon y splash (disco `public`).

Programar el scheduler para el control de faltas (`attendance:mark-absences`, 01:30 cada día) y la
limpieza de tokens vencidos (`sanctum:prune-expired`, diario), ambos en `routes/console.php`:
`* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1`

Si alguna empresa usa el webhook o el endpoint de notificaciones de "Marca y app", deja corriendo
un worker de la cola (`QUEUE_CONNECTION=database`): `php artisan queue:work --tries=3`. Sin worker
los envíos quedan pendientes en la tabla `jobs` (ver `docs/architecture/white-label.md`).

## Multi-tenant, roles y permisos

- **Aislamiento:** todo modelo con `tenant_id` usa el trait `App\Models\Concerns\BelongsToTenant`: un global scope filtra por la empresa resuelta en la request y `tenant_id` se completa solo al crear. `EnforceTenantScope` rechaza tokens de otra empresa.
- **Resolución de la empresa:** header `X-Tenant-Slug`, subdominio (`empresa.tunesa.app`, dominio base en `APP_DOMAIN`) o dominio propio (`tenants.custom_domain`).
- **Roles:** `super_admin`, `company_admin`, `hr_manager`, `supervisor`, `employee`. La matriz de permisos está en `database/seeders/RolesAndPermissionsSeeder.php`; las rutas la exigen con `->middleware('permission:shifts.manage')` y en código se usa `$user->hasPermission(...)` o `$user->can(...)`.
- **Super Admin:** entra por `POST /api/superadmin/auth/login` (sin `X-Tenant-Slug`) y administra empresas en `/api/superadmin/tenants`. En el panel, sin empresa elegida en el selector, trabaja en la "vista global".

Detalle en [docs/architecture/overview.md](docs/architecture/overview.md).

## Marca blanca y configuración de la app

- **Panel:** `Configuración > Marca y app` (`/settings/branding`) con pestañas Marca, App, Reglas e Integraciones. En la vista global del Super Admin se editan los valores por defecto de la plataforma, que heredan las empresas en lo que no definan. `/settings/mobile` decide qué módulos ve el colaborador.
- **API:** `GET /api/v1/app/config` (pública: marca, reglas de la app, versión mínima), `GET|PUT /api/admin/app-config` (Company Admin). Siguen existiendo `GET /api/tenant/branding` (público) y `PUT /api/admin/branding` (guarda con el mismo servicio: sin SVG, borra el logo anterior y audita).
- **Versión mínima:** con "Forzar actualización", una app que manda `X-App-Version` menor que la mínima recibe `426 APP_UPDATE_REQUIRED`.

Detalle en [docs/architecture/white-label.md](docs/architecture/white-label.md).

## Panel web de RRHH

- **Dashboard** con métricas del día y mapa de sucursales que se refresca solo.
- **Personal y turnos** (`/shifts`): turnos fijos o rotativos (N días de trabajo / M de descanso), tolerancia de tardanza y asignación por empleado.
- **Feriados** (`/holidays`): el control de faltas no registra ausencias en esas fechas.
- **Control de faltas** (`/attendance`): faltas marcadas cada madrugada (justificadas automáticamente si hay una licencia aprobada) y revisión de justificaciones.
- **Aprobaciones** (`/approvals`): vacaciones, permisos y justificaciones con flujo pendiente → aprobado / rechazado.
- **Boletas** (`/payslips/bulk`): carga masiva de PDF; cada archivo se asigna al empleado cuyo DNI o código aparece en el nombre (ej. `boleta_45879632.pdf`). También por API: `POST /api/admin/payslips/bulk`.
- **Bitácora** (`/audit`): auditoría de acciones sensibles.
- El acceso a cada módulo depende de los permisos del rol, y el panel aplica el logo y los colores de la empresa (o de la plataforma).

## Fase 3: legajo, dotación y planilla

Tras actualizar, corre `php artisan migrate` y vuelve a sembrar los permisos con `php artisan db:seed --class=RolesAndPermissionsSeeder`.

- **Sin auto-registro:** no existe `POST /api/auth/register`. Solo Super Admin, Company Admin y RRHH crean personal, y nadie puede crear o editar a alguien de rango igual o superior. La contraseña temporal obliga a cambiarla en el primer ingreso (`must_change_password`).
- **Login endurecido:** bloqueo temporal por intentos fallidos (`LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCK_MINUTES`), contraseñas con Argon2id (las bcrypt antiguas se rehashean al entrar), tokens Sanctum con prefijo (`SANCTUM_TOKEN_PREFIX`) y caducidad (`MOBILE_TOKEN_DAYS`), máximo de dispositivos (`MAX_DEVICES_PER_USER`), cierre de todas las sesiones y desbloqueo desde el panel.
- **Archivos privados:** documentos y selfies van al disco `private` (`DOCUMENTS_DISK`, `ATTENDANCE_DISK`) y solo se ven con URLs firmadas que caducan (`SIGNED_URL_MINUTES`). Cada archivo se valida por su tipo real y guarda su SHA-256.
- **API del empleado:** `GET /api/employee/profile`, `PUT /api/employee/payment-info`, `PUT /api/employee/contact`, `POST /api/employee/documents` (multipart), `DELETE /api/employee/documents/{id}`, `PUT /api/employee/uniform-sizes`.
- **API de administración:** `/api/admin/documents` (aprobar o rechazar), `/api/admin/employees/{id}/profile`, `/api/admin/uniforms`, `/api/admin/payroll/export`, `/api/admin/company-configuration`, `/api/admin/attendance/{id}`.
- **Panel web:** visor de marcación con selfie ampliada y mapa de geocerca, revisión de documentos, legajo por empleado con verificación de cuenta bancaria, dotación con exportación para logística, consolidado de planilla en CSV y `Configuración > App móvil` con los switches de lo que el empleado ve y edita.
- **Datos sensibles:** números de cuenta y CCI cifrados en base de datos, enmascarados por defecto y auditados cuando alguien los revela (`audit_logs`).

## Notificaciones

- **In-app (canal `database`):** el colaborador recibe aviso cuando aprueban o rechazan su solicitud o su justificación, cuando le publican una boleta y cuando RRHH revisa un documento de su legajo; el supervisor de su sucursal y RRHH, cuando envía una solicitud. API (cualquier rol, con `X-Tenant-Slug`): `GET /api/notifications`, `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all`. En el panel: campana del topbar y `/notifications`.
- **Correo:** con `NOTIFY_BY_MAIL=true` (y `MAIL_MAILER` configurado) las decisiones sobre solicitudes también se envían por correo.

### Notificaciones push

El backend ya acepta el token de Firebase Cloud Messaging en `POST /api/auth/refresh-fcm` (y en el login como `fcm_token`; columna `users.fcm_token`), pero el envío push no está activo porque necesita el proyecto de Firebase del cliente, y la app todavía no integra Firebase ni envía el token. Para activarlo hace falta: crear el proyecto en Firebase y registrar la app Android (y la de iOS con su clave APNs), poner su `google-services.json` en la app y hacer que registre su token con `refresh-fcm`, generar una cuenta de servicio con permiso de Firebase Cloud Messaging y guardar su JSON en el servidor (fuera del repositorio), e instalar un canal de notificaciones FCM para Laravel (p. ej. `laravel-notification-channels/fcm`) agregando `'fcm'` al `via()` de las clases en `app/Notifications`. Los datos de cada aviso (`type`, `title`, `body`, `data`) son los mismos que guarda el canal `database`.

## App móvil

Ver [`mobile/README.md`](mobile/README.md): entornos y URL del backend, compilación del APK (`mobile/scripts/build_apk.sh`) y firma. El workflow **App móvil: APK** de GitHub Actions compila el APK en cada PR que toca `mobile/`. Para probar en un celular contra tu PC, ver [docs/deployment/mobile-cloudflare-tunnel.md](docs/deployment/mobile-cloudflare-tunnel.md). El contrato de la API está en [docs/api/mobile-endpoints.md](docs/api/mobile-endpoints.md).
