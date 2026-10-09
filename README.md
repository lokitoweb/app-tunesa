# TuneSA

SaaS multi-tenant de control de asistencias: marcación con GPS y selfie, geocercas por sucursal, turnos y boletas de pago.

- `app/`, `routes/`, `resources/views/`: backend Laravel 11 (API para la app móvil + panel web).
- `mobile/`: app Flutter para colaboradores.
- `serve_panel.js` + `server/`: prototipo del panel en Node (sin Laravel).

## Levantar el backend en local

Requisitos: PHP 8.2+ (con `pdo_sqlite`) y Composer.

```bash
composer install
composer run setup   # crea .env, genera APP_KEY, crea database.sqlite, migra + seed y enlaza storage
php artisan serve
```

- Panel web: http://localhost:8000/login
- API: `http://localhost:8000/api/...` con el header `X-Tenant-Slug: empresa-demo`
- Usuarios demo: los imprime el seeder al terminar (`database/seeders/DatabaseSeeder.php`).

Tests: `php artisan test`

Programar el scheduler para el control de faltas (`attendance:mark-absences`, 01:30 cada día):
`* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1`

## Multi-tenant, roles y permisos

- **Aislamiento:** todo modelo con `tenant_id` usa el trait `App\Models\Concerns\BelongsToTenant`: un global scope filtra por la empresa resuelta en la request y `tenant_id` se completa solo al crear. `EnforceTenantScope` rechaza tokens de otra empresa.
- **Resolución de la empresa:** header `X-Tenant-Slug`, subdominio (`empresa.tunesa.app`) o dominio propio (`tenants.custom_domain`).
- **Marca blanca:** `GET /api/tenant/branding` (público) y `PUT /api/admin/branding` (Company Admin).
- **Roles:** `super_admin`, `company_admin`, `hr_manager`, `supervisor`, `employee`. La matriz de permisos está en `database/seeders/RolesAndPermissionsSeeder.php`; las rutas la exigen con `->middleware('permission:shifts.manage')` y en código se usa `$user->hasPermission(...)` o `$user->can(...)`.
- **Super Admin:** entra por `POST /api/superadmin/auth/login` (sin `X-Tenant-Slug`) y administra empresas en `/api/superadmin/tenants`.

## Panel web de RRHH

- **Dashboard** con métricas del día y mapa de sucursales que se refresca solo.
- **Personal y turnos** (`/shifts`): turnos fijos o rotativos (N días de trabajo / M de descanso), tolerancia de tardanza y asignación por empleado.
- **Control de faltas** (`/attendance`): faltas marcadas cada madrugada (justificadas automáticamente si hay una licencia aprobada) y revisión de justificaciones.
- **Aprobaciones** (`/approvals`): vacaciones, permisos y justificaciones con flujo pendiente → aprobado / rechazado.
- **Boletas** (`/payslips/bulk`): carga masiva de PDF; cada archivo se asigna al empleado cuyo DNI o código aparece en el nombre (ej. `boleta_45879632.pdf`). También por API: `POST /api/admin/payslips/bulk`.
- El acceso a cada módulo depende de los permisos del rol, y el panel aplica el logo y los colores de la empresa.

## Fase 3: legajo, dotación y planilla

Tras actualizar, corre `php artisan migrate` y vuelve a sembrar los permisos con `php artisan db:seed --class=RolesAndPermissionsSeeder`.

- **Sin auto-registro:** no existe `POST /api/auth/register`. Solo Super Admin, Company Admin y RRHH crean personal, y nadie puede crear o editar a alguien de rango igual o superior. La contraseña temporal obliga a cambiarla en el primer ingreso (`must_change_password`).
- **Login endurecido:** bloqueo temporal por intentos fallidos (`AUTH_MAX_ATTEMPTS`, `AUTH_LOCKOUT_MINUTES`), contraseñas con Argon2id (las bcrypt antiguas se rehashean al entrar), tokens Sanctum con prefijo y caducidad (`MOBILE_TOKEN_DAYS`), máximo de dispositivos (`AUTH_MAX_DEVICES`), cierre de todas las sesiones y desbloqueo desde el panel.
- **Archivos privados:** documentos y selfies van al disco `private` (`DOCUMENTS_DISK`, `ATTENDANCE_DISK`) y solo se ven con URLs firmadas que caducan (`SIGNED_URL_MINUTES`). Cada archivo se valida por su tipo real y guarda su SHA-256.
- **API del empleado:** `GET /api/employee/profile`, `PUT /api/employee/payment-info`, `PUT /api/employee/contact`, `POST /api/employee/documents` (multipart), `DELETE /api/employee/documents/{id}`, `PUT /api/employee/uniform-sizes`.
- **API de administración:** `/api/admin/documents` (aprobar o rechazar), `/api/admin/employees/{id}/profile`, `/api/admin/uniforms`, `/api/admin/payroll/export`, `/api/admin/company-configuration`, `/api/admin/attendance/{id}`.
- **Panel web:** visor de marcación con selfie ampliada y mapa de geocerca, revisión de documentos, legajo por empleado con verificación de cuenta bancaria, dotación con exportación para logística, consolidado de planilla en CSV y `Configuración > App móvil` con los switches de lo que el empleado ve y edita.
- **Datos sensibles:** números de cuenta y CCI cifrados en base de datos, enmascarados por defecto y auditados cuando alguien los revela (`audit_logs`).

## App móvil

Ver [`mobile/README.md`](mobile/README.md): entornos y URL del backend, compilación del APK (`mobile/scripts/build_apk.sh`) y firma. El workflow **App móvil: APK** de GitHub Actions compila el APK en cada PR que toca `mobile/`.

