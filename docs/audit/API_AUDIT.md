# API y sincronización (fase 1)

- 155 rutas `/api`. Respuesta uniforme `{success, data|message, code}` en 34 de 35 controladores; errores
  normalizados en `bootstrap/app.php`.
- Versionado mezclado: solo 7 rutas con `/v1`; alias duplicados (`app/config` y `v1/app/config`, `sync-batch`
  con y sin `v1`, dos logins, perfil por PUT y POST). Consumidores en `mobile/lib/core/network/api_endpoints.dart`.
- 60 endpoints `api/admin/*`, `api/superadmin/*` sin cliente (solo pruebas).
- Paginación solo en 6 controladores; varios listados con tope fijo y algunos sin tope.

## Sincronización sin conexión

- La app envía lotes de 5 con `client_uuid`; el servidor deduplica por `(tenant_id, client_uuid)` cuando viene el uuid.
- Pendientes: `client_uuid` opcional (D2), hora de la marcación controlada por el teléfono (S12), marcación
  que llega con el día ya cerrado se pierde sin rastro (C8).

Estado: pendiente (bloque 5).
