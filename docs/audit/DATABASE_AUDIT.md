# Base de datos y reglas de asistencia (fase 1)

Regla: ninguna migración se sube sin autorización (cada push a master las ejecuta en producción). Cada una
llevará: motivo, cambios, riesgo, compatibilidad con datos, respaldo, reversión y pruebas.

## A. Esquema e higiene (pendiente, bloque 3)

| # | Hallazgo | Sev. | Propuesta |
|---|---|---|---|
| D1 | `attendances` única por `(user_id, branch_id, work_date)`: la base admite dos jornadas de la misma persona y día en sedes distintas (solo lo impide el lock de la app) | Medio | Revisar datos existentes antes de proponer una restricción; no forzar si hay casos legítimos |
| D2 | `attendance_punches.client_uuid` es opcional: marcaciones sin uuid no son idempotentes | Medio | Exigirlo en la API (la app siempre lo manda) |
| D3 | Únicas que no contemplan `deleted_at` (attendances, payslips) | Bajo | Latente: hoy nada borra jornadas |
| D4 | `PurgeAttendancePhotos` y la poda de intentos filtran sin `tenant_id`: recorren la tabla completa | Medio | Índice adecuado o consulta por empresa |
| D5 | Sin retención para `audit_logs`, `notifications`, `failed_jobs` | Alto | Política de retención (decisión de plazos) + índices por fecha |
| D6 | Migraciones que crean triggers en MySQL sin comprobar permisos (10_14, 10_21) | Medio | Envolver como la de 11_10 (`canCreateTriggers`) |
| D7 | Migraciones con fecha futura y prefijos repetidos | Bajo | Solo orden; documentado |
| D8 | Índice `(tenant_id, work_date)` redundante; attendances tiene ~11 índices | Bajo | Medir antes de quitar |
| D9 | Consulta de selfies del calendario sin límite de fechas (`AttendanceCalendarService.php:263-269`) | Medio | Acotar al período |

## B. Integridad de cálculos (pendiente, bloque 2 — prioridad funcional)

Dónde se calcula hoy:
- Al marcar: `AttendanceService` (tardanza, refrigerio, horas, extras con reglas de feriados/días especiales, estado).
- Corrección manual: `ManualAttendanceEdit` reutiliza esos métodos, pero reescribe el estado a mano.
- Calendario y planilla: leen lo guardado.
- **Reporte de horas: recalcula todo por su cuenta** (`AttendanceReportService`).

| # | Hallazgo (verificado en código) | Sev. |
|---|---|---|
| C1 | El reporte de horas **no aplica** las reglas de horas extra de feriados y días especiales (`overtime_rule`) | Alto |
| C2 | Sin turno, el reporte usa 8 h de base; lo guardado usa 4 h para medio tiempo | Alto |
| C3 | Sin `hours_v2`, el reporte descuenta solo el refrigerio marcado; lo guardado descuenta el mayor con el fijo | Alto |
| C4 | El reporte ignora la aprobación de horas extra; la planilla usa solo las aprobadas | Alto |
| C5 | La planilla cuenta toda fila `absent` como falta, aunque la justificación esté aprobada (`PayrollExportService.php:95`); el reporte las cuenta como justificadas | Alto — **decisión del propietario** |
| C6 | Turno nocturno con entrada después de medianoche: se archiva al día siguiente, sin tardanza, y el día real queda como falta | Alto |
| C7 | Turno partido: si faltan las marcas del medio, la pausa entre tramos cuenta como trabajada | Medio |
| C8 | Marcación sin conexión que llega cuando el día ya cerró: 404 y se pierde sin rastro | Medio |
| C9 | Con `schedule_v2` apagado, la marcación y el calendario resuelven horarios con criterios distintos | Medio |
| C10 | Aprobar la justificación de una **tardanza o marca olvidada** cambia el día a `excused`: la planilla lo cuenta a la vez como día trabajado y como "falta justificada" (`Attendance::reviewJustification`); el reporte lo cuenta solo como trabajado | Alto — **decisión del propietario** |
| C11 | "Faltas justificadas" no significa lo mismo: la planilla cuenta filas `excused` (sin licencias); el reporte cuenta licencias aprobadas + faltas con justificación aprobada (sin días trabajados) | Alto |

Objetivo del bloque 2: una sola fuente de cálculo para jornada, reporte y planilla, con pruebas de casos
normales, límites y excepcionales.

### B.1 Avance (09/11/2026)

| Fórmula | Antes | Ahora | Estado |
|---|---|---|---|
| Horas de la jornada cerrada (total − descanso, refrigerio pagado, jornada base, horas extra) | Copiada en la salida de la app (`AttendanceService`) y en la corrección manual (`ManualAttendanceEdit`) | Una sola: `AttendanceService::closedDayHours` | **Implementado y probado** (sin cambio de resultados) |
| Estado de la jornada (tardanza / media jornada < 60 % / presente) | `determineFinalStatus` + copia en `ManualAttendanceEdit` | Una sola: `AttendanceService::closedDayStatus` | **Implementado y probado** |
| Reporte de horas vs. lo guardado (C1–C4) | El reporte recalcula | — | **Pendiente**: alinear cambia cifras del reporte. Ya existe la bandera por empresa `hours_v2` (`php artisan attendance:flag`) que corrige C3 y parte de C4; activarla es decisión del propietario |
| Faltas justificadas (C5, C10, C11) | Tres criterios | — | **Pendiente de aprobación** (propuesta B.2) |

Planilla, calendario, dashboard e integraciones leen lo guardado en `attendances`; con la fórmula única, lo
guardado sale igual por la app o por la corrección manual.

### B.2 Faltas justificadas: situación actual y propuesta (no implementada; cambia la planilla)

**Hoy** (verificado en el código):
- **No suman horas trabajadas**: la falta tiene 0 h y aprobar la justificación no agrega horas. "Horas a pagar"
  de la planilla = horas trabajadas reales + extras aprobadas. Esto ya cumple "no cuentan como horas trabajadas".
- Dos caminos: **licencia** aprobada (vacaciones, permiso, descanso médico: `LeaveRequest`) o **justificación**
  de un día (falta, tardanza, marca olvidada) aprobada → estado `excused`.
- **No existe "con goce / sin goce"** en ningún lado. La app no calcula sueldos: la planilla exportada entrega
  días y horas, y quien la procesa decide qué paga.

**Propuesta** (sin inventar reglas: el goce lo decide RR. HH. o la ley, la app solo lo registra y lo separa):

| Tipo | Horas trabajadas | Cuenta como falta injustificada | En planilla | Quién decide el goce |
|---|---|---|---|---|
| Falta justificada **con goce** (ej. licencia con goce, descanso médico) | 0 | No | Columna "Días justificados con goce" | Vacaciones y descanso médico: con goce fijo. Permiso y justificación: RR. HH. al aprobar |
| Falta justificada **sin goce** (ej. motivo personal aceptado) | 0 | No (no es sanción) | Columna "Días justificados sin goce" | RR. HH. al aprobar |
| Falta injustificada | 0 | Sí | "Faltas injustificadas" (sin cambio) | — |
| Tardanza o marca olvidada justificada (C10) | Las reales | No | Sigue como día trabajado; "Minutos de tardanza justificados" aparte | RR. HH. al aprobar |

Cómo se haría: un campo opcional "con goce" (sí/no) al aprobar una justificación o un permiso (columna nueva,
nula, sin tocar datos existentes); la planilla y el reporte cuentan con **la misma clasificación por día**
(la del reporte), así ambos dan lo mismo (C11). Lo ya aprobado antes queda "sin clasificar" hasta que RR. HH.
lo revise; no se modifica automáticamente.

Decisiones que se necesitan: (1) ¿se aprueba la tabla?; (2) valor por defecto al aprobar un permiso o una
justificación (¿sin goce?); (3) ¿la tardanza justificada deja de contarse en "Tardanzas" de la planilla?
