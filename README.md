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

## App móvil

Ver [`mobile/README.md`](mobile/README.md): entornos y URL del backend, compilación del APK (`mobile/scripts/build_apk.sh`) y firma. El workflow **App móvil: APK** de GitHub Actions compila el APK en cada PR que toca `mobile/`.

