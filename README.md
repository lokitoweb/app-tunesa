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

## Multi-tenant, roles y permisos

- **Aislamiento:** todo modelo con `tenant_id` usa el trait `App\Models\Concerns\BelongsToTenant`: un global scope filtra por la empresa resuelta en la request y `tenant_id` se completa solo al crear. `EnforceTenantScope` rechaza tokens de otra empresa.
- **Resolución de la empresa:** header `X-Tenant-Slug`, subdominio (`empresa.tunesa.app`) o dominio propio (`tenants.custom_domain`).
- **Marca blanca:** `GET /api/tenant/branding` (público) y `PUT /api/admin/branding` (Company Admin).
- **Roles:** `super_admin`, `company_admin`, `hr_manager`, `supervisor`, `employee`. La matriz de permisos está en `database/seeders/RolesAndPermissionsSeeder.php`; las rutas la exigen con `->middleware('permission:shifts.manage')` y en código se usa `$user->hasPermission(...)` o `$user->can(...)`.
- **Super Admin:** entra por `POST /api/superadmin/auth/login` (sin `X-Tenant-Slug`) y administra empresas en `/api/superadmin/tenants`.
