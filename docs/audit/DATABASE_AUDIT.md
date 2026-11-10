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

Objetivo del bloque 2: una sola fuente de cálculo para jornada, reporte y planilla, con pruebas de casos
normales, límites y excepcionales.
