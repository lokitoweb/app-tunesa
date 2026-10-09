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
