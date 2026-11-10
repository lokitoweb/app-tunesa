# Arquitectura (fase 1, 09/11/2026)

## Mapa

```
App móvil (Flutter, Android/iOS) ──HTTPS + token Sanctum (30 días; 12 h Super Admin)──┐
Panel web (107 vistas Blade + 48 páginas Vue/Inertia) ──sesión web──────────────────────┤
                                                                                         ▼
                Laravel 12.69 / PHP 8.2 — monolito modular (app/Modules/*), 496 rutas (341 web, 155 API)
                Multiempresa: ResolveTenant (X-Tenant-Slug / dominio) + EnforceTenantScope (API),
                SetWebTenant (panel), TenantScope + guard de guardado en 67 de 74 modelos
                Permisos: rol base + ajustes por empresa (role_permission_overrides) + roles propios
                     │                         │                                │
              MySQL (producción)      Disco privado (selfies, legajo)    Cola y caché en la base de datos;
              SQLite (local/pruebas)  servido por URL firmada de 5 min   scheduler cada minuto (cron)
                     │
     Externos: Firebase FCM (push) · Nominatim / Google (geocodificación y teselas) · Migo (RUC/DNI)
               · SMTP por empresa · webhooks salientes/entrantes (HMAC) · Telegram · Nubefact (solo configuración)

Despliegue: push a master → GitHub Actions (pruebas) → rama production → scripts/deploy.sh
            (respaldo de la base, composer, npm build, migrate, roles, vuelta atrás si algo falla)
```

## Tecnologías

| Capa | Versión / paquetes |
|---|---|
| Backend | PHP 8.2, Laravel 12.69, Sanctum 4, Inertia 2, dompdf 3, PhpSpreadsheet 5 |
| Web | Vue 3.5, Bootstrap 5.3, Vite 6, Leaflet (OSM, Esri o Google Map Tiles) |
| Móvil | Flutter 3.47 (Dart ^3.13), Riverpod 3, go_router 18, Dio, flutter_secure_storage, sqflite_sqlcipher, geolocator 14, ML Kit (rostro), Firebase Messaging |
| Datos | MySQL en producción; caché, sesiones y colas en la base (sin Redis) |
| CI | GitHub Actions: pruebas backend (SQLite y MySQL), contrato app↔API, emuladores Android 29/35, APK/AAB, iOS manual |

## Módulos

| Módulo | Rutas web/API | Responsabilidad |
|---|---|---|
| Core | 49/49 | Empresas, accesos, roles, sedes, dashboard, ajustes, auditoría, páginas legales |
| Attendance | 28/19 | Marcaciones, geocerca, selfies, calendario, reportes, tablero en vivo, contingencia |
| HR | 76/44 | Legajo, turnos y programación, permisos, CTS, liquidaciones, feriados, portal |
| Finance | 83/3 | Requisiciones, tesorería, caja chica, inventario, combustible, costos |
| Payroll | 13/11 | Boletas, exportación de planilla |
| Training, Announcements, Benefits, Birthdays, CompanyDocs, Ethics, Onboarding, Integrations | menores | Cada módulo se apaga por empresa (ModuleRegistry) |

## Hallazgos de arquitectura (pendientes, bloque 7)

- ~~Regla de estado de la jornada repetida~~: resuelto en el bloque 2 (`AttendanceService::closedDayStatus` / `closedDayHours`).
- Tres tableros "en vivo" distintos (API liveDashboard, API teamLive, web LiveBoardService) y estadísticas con SQL propio en dos sitios.
- Sedes editables en varios lugares (`branches/index` modal, `branches/form`, `OrganizationWebController::updateBranch`, API `BranchController`) con reglas de validación duplicadas.
- Código muerto: `Finance/Support/LegacyLinker.php`, abilities de Sanctum asignadas pero nunca exigidas, `_to_delete/google-services.json.vacio`.
- 60 endpoints `api/admin/*` y `api/superadmin/*` sin cliente (solo pruebas) que duplican lógica del panel.
- Nubefact: solo columnas y credenciales; ninguna clase llama a su API (decisión del propietario).
