# Documentación de TuneSA

Índice de la documentación técnica del repositorio. Todo lo que está aquí se escribió
leyendo el código (rutas, controladores, modelos, migraciones, la app Flutter y los
workflows). Si algo no coincide con el código, manda el código: avisa y se corrige el doc.

## Arquitectura

| Documento | Qué explica |
|---|---|
| [architecture/overview.md](architecture/overview.md) | Componentes del sistema, multi-tenant, roles y permisos, módulos y cómo viajan los datos de la app al panel. |
| [architecture/modules.md](architecture/modules.md) | Monolito modular: módulos, estructura de carpetas, activación por empresa y frontend Vue 3 + Inertia con un chunk por módulo. |
| [architecture/organization.md](architecture/organization.md) | Jerarquía Zona → Sede → Área, alcance de los usuarios por territorio (global / zona / sede) y marcación itinerante. |
| [architecture/white-label.md](architecture/white-label.md) | Marca blanca y configuración de la app por empresa (`tenant_settings`): herencia, pestañas del panel, colores en el panel y cómo la consume la app. |
| [architecture/attendance-integrity.md](architecture/attendance-integrity.md) | Integridad de la marcación: bitácora inmutable `attendance_punches`, idempotencia, sincronización en lote, banderas de sospecha, geocodificación y push FCM (puesta en marcha). |

## API

| Documento | Qué explica |
|---|---|
| [api/mobile-endpoints.md](api/mobile-endpoints.md) | Contrato de la API que usa la app móvil: cabeceras, errores, login, `GET /api/v1/app/config`, marcación, solicitudes, boletas, legajo y notificaciones. |

## Despliegue

| Documento | Qué explica |
|---|---|
| [deployment/mobile-cloudflare-tunnel.md](deployment/mobile-cloudflare-tunnel.md) | Probar la app en un celular real con el backend en tu PC expuesto por Cloudflare Tunnel, paso a paso, con solución de problemas. |
| [deployment/integrations-queue.md](deployment/integrations-queue.md) | Worker de la cola, reintentos e historial de envíos del webhook y del endpoint de notificaciones; variables `INTEGRATIONS_*`. |

## Otros documentos del repositorio

- [`README.md`](../README.md) de la raíz: arranque rápido, pruebas, producción y notificaciones.
- [`mobile/README.md`](../mobile/README.md): entornos de la app, compilación del APK, firma e iOS.

## Convenciones

- Las rutas de archivos son relativas a la raíz del repositorio.
- La API vive bajo `/api` **sin** prefijo de versión. La única excepción es
  `GET /api/v1/app/config` (alias de `GET /api/app/config`), ver `app/Modules/*/Routes/api.php`.
- Las secciones "Comportamiento de la app" y "Conexión del servidor" están verificadas
  contra el código Flutter de `mobile/lib` y citan los archivos de donde sale cada dato.
