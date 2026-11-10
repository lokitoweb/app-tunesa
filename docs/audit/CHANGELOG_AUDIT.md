# Registro de cambios de la auditoría

Cada entrada: qué se cambió, por qué, archivos, pruebas. Se agrega el commit cuando exista.

## 09/11/2026 — Bloque 2: fórmula única de la jornada cerrada

- `AttendanceService::closedDayHours` y `closedDayStatus`: la salida marcada en la app y la corrección manual de
  RR. HH. usan la misma fórmula (antes copiada en `ManualAttendanceEdit`). Sin cambio de resultados ni de reglas.
- Inventario de diferencias entre reporte, planilla y calendario (C1–C11) y propuesta de faltas justificadas
  con/sin goce en `DATABASE_AUDIT.md` §B (pendiente de aprobación; no cambia la planilla).
- Pruebas: 193 de asistencia, reportes y planilla pasan; suite completa al cierre de la tanda.

## 09/11/2026 — Datos personales fuera de `configurar_trujillo.php` (autorizado)

- El DNI de la persona real (usado también como contraseña) ya no está en el código: el script lo recibe como primer
  argumento (`php configurar_trujillo.php <DNI> [sede] [lat lng]`) y sin él se detiene antes de tocar la base.
  La lógica no cambia: contraseña inicial = DNI con cambio obligatorio en el primer ingreso.
- Dependencias revisadas: ningún otro archivo lo usa. La dirección es del local de la empresa (no es dato personal).
- El historial de Git no se reescribe; el dato sigue en commits anteriores (ver `SECURITY_AUDIT.md` §1).

## 09/11/2026 — Recuperación de acceso con enlace de un solo uso (S2, aprobado)

- "¿Olvidaste tu contraseña?" ya **no cambia la contraseña** al pedirlo: envía un enlace de un solo uso que vence en
  30 minutos (token guardado con sha256 en `password_reset_tokens`). La contraseña cambia recién al confirmarla en
  `/restablecer-clave/{token}`; se borra el token, se cierran las sesiones y queda en auditoría.
- Un pedido nuevo invalida el enlace anterior; un correo por persona cada 15 min, más límite por IP en las rutas.
- Respuesta siempre neutra. Sin correo real (o si falla el envío) no se crea enlace y se avisa a RR. HH.
- La contraseña inicial por DNI, su cambio obligatorio y "Restablecer acceso" de RR. HH. **no cambian**.
- Archivos: `PasswordRecovery`, `PasswordResetLinkMail` (antes `TemporaryPasswordMail`), `Web/AuthController`,
  `Api/AuthController`, `Routes/web.php`, `auth/reset-password.blade.php`, `auth/forgot-password.blade.php`,
  `Labels`, `mobile/.../login_screen.dart` (texto del diálogo).
- Pruebas: `PasswordRecoveryTest` reescrita (6), `LoginByEmailTest`, `PasswordHelpTest`, `PasswordResetWebTest`;
  suite completa 798/798 en local. Página verificada en el navegador local.

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
