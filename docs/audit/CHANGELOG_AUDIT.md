# Registro de cambios de la auditoría

Cada entrada: qué se cambió, por qué, archivos, pruebas. Se agrega el commit cuando exista.

## 09/11/2026 — Bloques 0 y 1 (primera tanda)

- **Secretos del historial**: `app:check-production` compara sin mostrar valores la `APP_KEY` y las contraseñas de
  Super Admin con el `.env` del commit `f10f077` (`CheckProductionReadiness::leakedSecrets`). Corre en cada despliegue.
- **`.gitignore`**: impide versionar `.env.*`, certificados, llaves de firma y cuentas de servicio.
- **Contraseña temporal en el panel y Mi espacio**: ya no se puede operar con ella (envíos y JSON incluidos)
  hasta cambiarla (`EnsureWebPasswordChanged`). La política del DNI no cambia.
- **Instalador fail-closed**: `scripts/deploy.sh` crea `storage/app/installed.lock` tras migrar; `app:check-production`
  avisa si el asistente sigue habilitado sin el lock.
- **Login**: tiempo de respuesta igual exista o no el correo (`LoginGuard`).
- **403 por rol**: ya no devuelve los roles requeridos ni el propio (`CheckRole`).
- **Logs**: recordatorios y recuperación registran el id, no el correo.
- **CORS**: solo `APP_URL` y `CORS_ALLOWED_ORIGINS` (antes `*`).
- **Marcación sin precisión GPS**: se registra y queda como sospechosa `ACCURACY_MISSING`.
- Pruebas: 3 nuevas; suite completa 797/797 en local.
