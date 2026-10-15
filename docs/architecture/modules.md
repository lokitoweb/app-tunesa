# Monolito modular

TuneSA es un solo despliegue de Laravel organizado por **módulos de negocio**. Cada módulo
trae todo su ciclo de vida (rutas, controladores, modelos, servicios, migraciones y pantallas)
y una empresa puede tener módulos apagados.

## Módulos

| Módulo | Qué contiene |
|---|---|
| `Core` | Acceso (login, contraseñas, tokens), empresas y multi-tenant, roles y permisos, sucursales, marca blanca, notificaciones in-app, bitácora de auditoría. No se puede apagar. |
| `Attendance` | Marcación (GPS, selfie, geocerca Haversine), bitácora inmutable de marcaciones, sincronización offline, faltas, tablero en vivo y reportes. |
| `HR` | Colaboradores, turnos y asignaciones, feriados, solicitudes y aprobaciones, legajo digital y dotación. |
| `Integrations` | Webhooks firmados (HMAC), receptor de la plataforma, geocodificación (OpenStreetMap / Google) y push (FCM). |
| `Payroll` | Boletas de pago y exportación para planilla. |
| `Training` | Capacitaciones, evaluaciones con intentos y constancias. |
| `Onboarding` | Incorporación del colaborador por etapas, aprobada por RRHH. |
| `Ethics` | Canal ético (denuncias anónimas o identificadas). |
| `Announcements` | Comunicados de RR. HH. (avisos, reuniones, actualizaciones) a toda la empresa, sede, área, cargo o persona; envío inmediato o programado (`announcements:dispatch` cada minuto) y lectura por persona. |
| `Benefits` | Beneficios para el personal por categoría y público. |
| `Birthdays` | Cumpleaños: saludo y aviso a compañeros (`birthdays:notify`). |
| `CompanyDocs` | Documentos de la empresa (PDF en carpetas) con confirmación de lectura. |

## Estructura de un módulo (backend)

```
app/Modules/<Módulo>/
├── Routes/web.php            Panel (sesión): pantallas Inertia y Blade
├── Routes/api.php            API de la app Flutter y webhooks (prefijo /api, nombres api.*)
├── Controllers/Web|Api/      Delgados: validan, llaman al servicio y devuelven props / JSON
├── Models/                   Eloquent con el scope de empresa (BelongsToTenant)
├── Services/                 Lógica de negocio; la usan por igual el panel, la API y los jobs
├── Database/Migrations/      Migraciones propias
├── Console/                  Comandos artisan (se registran solos)
├── Jobs/ Notifications/ ...  Lo que el módulo necesite
└── Providers/<Módulo>ServiceProvider.php   Opcional: bindings y eventos propios
```

`App\Providers\ModuleServiceProvider` recorre `app/Modules/*` y registra todo lo anterior: para
agregar un módulo basta crear su carpeta. No hay que tocar `bootstrap/` ni `composer.json`
(el autoload PSR-4 de `App\` ya cubre `App\Modules\<Módulo>\...`).

Reglas:

- **Un módulo solo sirve sus propios controladores** (lo comprueba `ModularArchitectureTest`).
- La lógica vive en `Services/`: el controlador web y el de la API llaman al mismo servicio
  (p. ej. `TenantSettingsService::updateFromPanel` guarda "Marca y app" e "Integraciones";
  `AttendanceService` atiende la app y el panel).
- `Core` no depende de los demás, salvo el menú (`Core/Support/PanelNavigation`), que conoce
  las pantallas de todos para pintarlas.
- Las rutas de la API llevan el prefijo de nombre `api.` (`api.shifts.index`), así un
  `apiResource` nunca pisa el nombre de una ruta del panel (`shifts.index`).

## Activar o apagar módulos por empresa

`App\Modules\ModuleRegistry` es el catálogo. Los módulos apagados de una empresa se guardan en
`tenants.settings.disabled_modules` y se editan en **Empresas › Editar › Módulos activos**
(Super Admin).

Cada ruta lleva la acción `module` (la pone `ModuleServiceProvider`). Al resolver la empresa:

- API (`ResolveTenant`): `403 MODULE_DISABLED`.
- Panel (`SetWebTenant`): `403`, y el menú deja de mostrar las pantallas del módulo.

La API guarda la empresa en caché 5 minutos, así que apagar un módulo puede tardar ese tiempo
en notarse en la app.

## Frontend: Vue 3 + Inertia + Vite

```
resources/js/
├── app.js                               Arranque: resuelve cada pantalla con import() diferido
└── Modules/<Módulo>/
    ├── Pages/...                        Pantallas (lo que nombra Inertia::render)
    ├── Components/                      Piezas del módulo
    └── Layouts/                         Solo Core: AppLayout.vue (menú, barra, avisos)
```

- El controlador nombra la pantalla por su ruta dentro de `Modules`:
  `Inertia::render('HR/Pages/Shifts/Index', [...])` → `resources/js/Modules/HR/Pages/Shifts/Index.vue`.
- `vite.config.js` compila **un chunk por módulo** (`hr-[hash].js`, `integrations-[hash].js`,
  `core-[hash].js`) más `vendor` (Vue, Inertia, Bootstrap). El navegador descarga el chunk de un
  módulo la primera vez que el usuario abre una de sus pantallas. Sin sourcemaps en producción.
- La plantilla raíz `resources/views/app.blade.php` solo tiene el `<head>` (tema, colores de
  marca) y el contenedor de Inertia.
- `Core/Middleware/HandleInertiaRequests` comparte con todas las pantallas el usuario, la marca,
  el menú, la campana y los avisos. **Nunca poner secretos ahí**: viajan en cada pantalla.

### Pantallas migradas a Vue

| Pantalla | Componente | Controlador |
|---|---|---|
| Turnos y horarios (listado, asignaciones, formulario) | `HR/Pages/Shifts/Index.vue`, `Form.vue` | `HR\Controllers\Web\ShiftWebController` |
| Bitácora de auditoría | `Core/Pages/Audit/Index.vue` | `Core\Controllers\Web\AuditLogWebController` |
| Comunicados (listado, formulario, detalle con lecturas) | `Announcements/Pages/Announcements/Index.vue`, `Form.vue`, `Show.vue` | `Announcements\Controllers\Web\AnnouncementWebController` |
| Integraciones (`/settings/integrations`, antes pestaña de "Marca y app") | `Integrations/Pages/Settings/Index.vue` | `Integrations\Controllers\Web\IntegrationsWebController` |

El resto del panel sigue en Blade (`resources/views/`) con el mismo aspecto: ambos layouts usan
la misma hoja de estilos (`public/css/panel.css`) y el mismo menú (`PanelNavigation`). El layout
Vue navega sin recargar entre pantallas migradas y con enlace normal hacia las de Blade.

### Migrar otra pantalla

1. El controlador devuelve `Inertia::render('<Módulo>/Pages/...', $props)` con arreglos planos
   (nada de modelos completos: solo lo que la pantalla pinta).
2. Crear el `.vue` en `resources/js/Modules/<Módulo>/Pages/` envuelto en `<AppLayout>`.
3. Marcar su entrada como `spa` en `PanelNavigation` y borrar la vista Blade.
4. En las pruebas, `assertInertia(...)` y los helpers `prop()`, `assertPageSees()` y
   `assertPageMisses()` de `tests/TestCase.php` reemplazan a `assertSee()`.

### Desarrollo y compilación

```bash
npm install
npm run dev      # Vite con recarga en caliente (junto a php artisan serve)
npm run build    # public/build/ (no se versiona): obligatorio en cada despliegue
```

Las pruebas de PHPUnit no necesitan compilar (`withoutVite()`).
