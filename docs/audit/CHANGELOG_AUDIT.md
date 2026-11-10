# Registro de cambios de la auditoría

Cada entrada: qué se cambió, por qué, archivos, pruebas. Se agrega el commit cuando exista.

## 10/11/2026 — C6–C8 corregidos (local, pendiente de publicar)

- C6: entrada después de medianoche dentro del turno nocturno de ayer = jornada de ayer, con tardanza.
- C7: la pausa del horario partido nunca cuenta como trabajada (aunque falten marcas o el refrigerio sea pagado).
- C8: la marcación sin conexión que llega sin jornada abierta (404/409) queda registrada para RR. HH.
- CI: en cada push solo el emulador Android 15; Android 10 los lunes, a mano y en PR.
- Publicados y en CI verde: `5683530` (hours_v2, faltas justificadas, app:reencrypt, pruebas de seguridad).

## 10/11/2026 — Publicado en GitHub y desplegado

- Publicados (solo código, sin `docs/audit`): recuperación con enlace, DNI fuera de `configurar_trujillo.php`,
  paso 6 de `server-setup.sh`, fórmula única de la jornada y optimización de CI (`8611e67`). CI en verde (3/3).
  Producción sirve la versión nueva (verificado en la página pública "¿Olvidaste tu contraseña?").

## 10/11/2026 — CI: menos minutos sin quitar pruebas

- Backend: no corre con cambios solo de app móvil o documentación; un push nuevo cancela el anterior.
- E2E: el contrato API corre con cambios de backend o app; los emuladores (~14 min c/u) solo cuando cambia
  `mobile/`, a mano o los lunes. Caché de Gradle. El `.aab` solo en tags `v*` o corrida manual release.

## 10/11/2026 — Bloque 2: fuente única y `hours_v2` para todas (local, pendiente de publicar)

- `hours_v2` por defecto en todas las empresas (off explícito = vuelta atrás). El reporte muestra la jornada
  guardada (feriados con regla de extras y base de medio tiempo incluidos). `attendance:recalculate` y una
  migración única que recalcula tras el respaldo del deploy. Diferencias documentadas en pruebas (extras solo
  después de la salida oficial; con refrigerio pagado, salir a la hora no es extra; descanso fijo en nocturnos).

## 10/11/2026 — Faltas y tiempos justificados con / sin goce (local, pendiente de publicar)

- Ver `DATABASE_AUDIT.md` §B.2. Backend, base de datos (solo agrega columnas), panel, app, planilla y reporte.

## 10/11/2026 — Secretos: rotación de APP_KEY sin pérdida (local, pendiente de publicar)

- `php artisan app:reencrypt` (con `APP_PREVIOUS_KEYS`): re-cifra denuncias de ética, datos bancarios y
  credenciales por empresa; no muestra valores; si algo no se descifra no lo toca y falla. Probado: rotar →
  re-cifrar → quitar la clave anterior conserva todo.
- `SecurityControlsTest`: el DNI no se puede volver a usar, Argon2id, CORS solo para orígenes permitidos.

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
