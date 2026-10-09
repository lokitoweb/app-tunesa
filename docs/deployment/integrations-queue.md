# Cola, reintentos e historial de las integraciones

Los eventos del webhook (`attendance.*`, `leave_request.status_changed`) y del endpoint de
notificaciones (`notification.created`) se envían con el job `App\Jobs\SendTenantWebhook`.
Detalle del formato y la firma en
[architecture/white-label.md](../architecture/white-label.md#integraciones-webhook-y-endpoint-de-notificaciones).

## Lo que necesita el servidor

1. **Cola con worker.** `QUEUE_CONNECTION=database` (por defecto en `.env.example`) y un
   proceso que no se detenga:

   ```bash
   php artisan queue:work --sleep=3 --max-time=3600
   ```

   Con systemd o Supervisor, reinícialo si termina (`--max-time` lo recicla cada hora para
   liberar memoria) y después de cada despliegue: `php artisan queue:restart`.
   No hace falta `--tries`: el job define sus intentos y esperas (`$tries`, `backoff()`) y eso
   tiene prioridad sobre las opciones del worker.

   Sin worker los eventos se quedan en la tabla `jobs` y el historial del panel ("Últimos 20
   envíos") los muestra como **Pendiente** con intento 0: es la señal de que falta el worker.

2. **Scheduler.** `* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1`.
   Además del control de faltas, corre a diario (03:15) `model:prune` para
   `App\Models\WebhookDelivery`: borra el historial de más de 30 días.

3. **Salida a internet** del servidor hacia las URLs de las empresas (HTTPS saliente).

## Reintentos

| Intento | Si falla, espera |
|---|---|
| 1 | 10 s |
| 2 | 1 min |
| 3 | 5 min |
| 4 | 15 min |
| 5 | — (queda `failed` y se escribe un aviso en el log) |

- Falla = respuesta distinta de 2xx, sin respuesta en 10 s (`INTEGRATIONS_TIMEOUT`) o error de
  conexión. Las redirecciones no se siguen.
- Cada intento actualiza la misma fila de `webhook_deliveries`: número de intento, código HTTP,
  milisegundos, respuesta (4 KB), error y `next_retry_at`.
- Con `QUEUE_CONNECTION=sync` (pruebas o servidores sin worker) hay un solo intento, en la misma
  petición; un fallo nunca interrumpe la marcación ni la aprobación que lo originó.
- Los botones "Probar Webhook Ahora" y "Enviar Notificación de Prueba" no usan la cola: envían
  al instante y muestran el resultado.

## Variables de entorno

| Variable | Defecto | Uso |
|---|---|---|
| `INTEGRATIONS_TIMEOUT` | `10` | Segundos máximos de cada envío. |
| `INTEGRATIONS_ALLOW_PRIVATE_URLS` | `false` | Permite enviar a IP privadas, locales o reservadas (`127.0.0.1`, `10.x`, `192.168.x`, `169.254.x`). Déjalo en `false` en producción: el historial muestra la respuesta del destino. |

## Diagnóstico rápido

- Historial vacío tras marcar: la empresa no tiene `webhook_url` guardada (no se hereda de la
  plataforma).
- Todo en **Pendiente** con intento 0: no hay worker (`php artisan queue:work`).
- **Pendiente** con intento ≥ 1 y "Próximo reintento": el destino falló y se reintentará.
- `php artisan queue:failed` lista los jobs que agotaron los 5 intentos.
