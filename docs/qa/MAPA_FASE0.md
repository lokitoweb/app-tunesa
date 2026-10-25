# Mapa fase 0 · Informe de QA Ronda 2 (horarios rotativos, marcaciones y reportes)

Fuente: `INFORME_QA_RONDA2.md` (v1.0, 25/10/2026). Todo dato técnico de este mapa salió de una lectura o comando real sobre el repositorio (rama `master`, commit `4016615`). Los nombres del informe (Juan Pérez, Fidel Alvarez, Juana Pereda, Yohana Contreras) y los horarios 08:00-15:00 / 14:00-21:00 son datos de ejemplo: solo se usan como fixtures de pruebas.

## 1. Hallazgo → ya existe / falta / a mejorar

| Código | Qué hay hoy (evidencia) | Estado |
|---|---|---|
| HQ-01 Horario por fecha | Turnos `fixed`/`rotating` (`2026_10_09_000002_add_rotation_to_shifts_table.php`), horario por día de la semana (`shifts.day_schedules`, `Shift::forDate`), vigencias `employee_shifts.effective_from/until`. No existe "colaborador + fecha → horario o día libre". | Falta la excepción por fecha |
| HQ-02 Marcación con el horario del día | `AttendanceService::clockIn` (:287) usa `getCurrentShift`, que sin turno ese día devuelve la primera asignación vigente: en un día autorizado fuera de turno la tardanza se mide contra un horario que no rige. La alerta "fuera de horario" existe (`AttendanceAlertNotifier.php:43-46`, colaborador y supervisor). | A mejorar (defecto) |
| HQ-03 Salida sin marcar no se corrige | Corrección manual con motivo y bitácora: `ManualAttendanceEdit` + `AuditLogger` (Calendario › persona › día). Bloqueos posibles en `canEdit`: sin permiso `attendance.edit` (Contabilidad no lo tiene), corregirse a sí mismo, supervisor que no supervisa a la persona, fecha futura. | Existe; causa por confirmar en producción |
| HQ-04 Filtro por sede | Existe (`CalendarFilters.php:37`). No recuerda la última sede ni pinta con `shifts.color`. | A mejorar |
| HQ-05 Programación pintando el calendario | Solo asignación por sede con vigencia (`ShiftWebController::assign`). Sin calendario, copiar semana ni alerta de "sin programación". | Falta |
| RP-01 Horas trabajadas | Dos fórmulas distintas (ver §4). | A mejorar (defecto) |
| RP-02 Tardanza con estado normal | No se reproduce en este sistema (ver §4). | Existe coherente; regla pendiente |
| RP-03 Estados | Licencias `vacation`, `permission`, `medical_leave` (`LeaveRequest.php:30`). El calendario agrupa descanso y feriado ("Descanso o feriado"); el reporte agrupa licencias en "Falta justificada". Etiquetas repetidas en 5 lugares. "DESCANZO" no existe en el código (búsqueda sin resultados). | A mejorar |
| RP-04 Reporte final | Ya trae días programados y trabajados, horas trabajadas, faltas justificadas e injustificadas y documento (`HoursReportExporter.php:28-32`). Faltan cargo, días de descanso y de vacaciones. | A mejorar |

| Caso | Estado de partida |
|---|---|
| CP-01 | Tardanza y estado coherentes; horas trabajadas con dos fórmulas. |
| CP-02 | `attendance:mark-absences` registra la falta (por fijar con prueba de caracterización). |
| CP-03 | Pasa; el calendario mezcla descanso y feriado. |
| CP-04 | No muestra "Vacaciones". Saldo de vacaciones: **excluido de esta ronda** (§7). |
| CP-05 | Corrección y bitácora existen; el reporte deja la jornada abierta como "trabajado" con 0 h. |
| CP-06, CP-07 | Fallan con dos asignaciones simultáneas (§2). |
| CP-08 | Riesgo por las dos fórmulas de horas. |
| CP-09 | Pasa. |

## 2. Las 7 copias de "turno del día"

Todas parten de asignaciones activas y vigentes en la fecha. Difieren en cuál eligen cuando hay más de una:

| # | Lugar | Elección | Sin turno ese día | Otros |
|---|---|---|---|---|
| 1 | `AttendanceService::getCurrentShift` (:924) | Primera que trabaja ese día (orden de BD, sin `orderBy`) | Respaldo: primera vigente | Usado por clockIn, corrección manual y AbsenceService |
| 2 | `ScheduleGate::statusFor` (:32) | Primera que trabaja ese día | Ninguna (`rest_day` / `unscheduled`) | `withoutGlobalScopes` |
| 3 | `MarkAbsences` (:73) | Las que trabajan, una por persona | Ninguna | Activos, dentro de ingreso/cese |
| 4 | `SendEntryReminders` (:90) | Las que trabajan y caen en la ventana horaria, una por persona | Ninguna | `forDate` antes de filtrar; solo con token FCM |
| 5 | `AttendanceCalendarService::cell` (:325) | Vigencia más reciente, sin mirar si trabaja; luego `worksOn` | "Descanso" | |
| 6 | `LiveBoardService` (:89, :165) | Vigencia más reciente | | Prefiere el turno de la marcación |
| 7 | `AttendanceReportService::assignmentOn` (:300) | Vigencia más reciente | | Prefiere el turno de la marcación |

Conflicto: con A (lun-vie) y B (sáb) a la vez (posible con `replace_current=false`), el sábado las copias 1-4 eligen B y las 5-7 eligen A ("Descanso" aunque marcó).

### Resolver único (decidido)

1. Excepción por fecha.
2. Asignación que sí trabaja ese día; empate → vigencia más reciente, luego id.
3. Si ninguna trabaja → día libre (sin respaldo a "primera vigente").

Decisiones tomadas (25/10/2026):

- **Respaldo de `getCurrentShift` (opción B):** se quita para los cálculos (tardanza, break, horas base). La sede de la asignación vigente se conserva para corrección manual y faltas. Quien marque un día sin turno queda visible como **"fuera de programación"** (HQ-02), no solo sin tardanza.
- **Solapes (1b):** se bloquea al crear una asignación nueva que trabaje algún día que ya cubre otra vigente. Las existentes no se modifican; se listan en solo lectura (local: 4 asignaciones activas, 0 solapes).
- **Pruebas de caracterización:** `tests/Feature/ScheduleResolutionCharacterizationTest.php` fija el comportamiento actual de las 7 copias, del respaldo y de las dos fórmulas.

### Flujos que dependen del respaldo de `getCurrentShift`

| Flujo | Usa del respaldo | Efecto de quitarlo (día sin turno) |
|---|---|---|
| `AttendanceService::clockIn` (:287) | `shift_id`, tardanza, horas base | Solo alcanzable con `punch_requires_schedule=false` o autorización fuera de turno. Sin respaldo: `shift_id` NULL, tardanza 0, jornada base 8 h/4 h (`baseDailyHours`), break de empresa. `isOutsideSchedule` no cambia. |
| `ManualAttendanceEdit::save` (:66) | turno (tardanza, break, horas base) y `branch_id` | Mismo efecto en tardanza y horas; la sede cae a la sede principal (ya existe ese respaldo en :128). |
| `AbsenceService::monthFor` (:80) | `branch_id`, `shift_id` de la falta | Solo se llama en celdas "falta" (día programado), donde ya hay asignación que trabaja: sin cambio, salvo el caso del conflicto de §2. |

El impacto exacto se fijará con pruebas de caracterización antes de cambiarlo.

## 3. Excepción por fecha (propuesta aprobada)

Tabla `employee_schedule_days`: `id`, `tenant_id` (BelongsToTenant), `user_id`, `work_date`, `shift_id` nullable (NULL = día libre explícito), `branch_id` (obligatorio con turno), `source` (`calendar`/`manual`), `notes`, `created_by`, `updated_by`, timestamps. Único `(tenant_id, user_id, work_date)`; índice `(tenant_id, branch_id, work_date)`. Toda escritura pasa por `AuditLogger`. Turno nocturno: `work_date` = fecha de inicio (convención actual de `attendances.work_date`).

- Sin fila → asignación vigente (rotativo, fijo, `day_schedules`, que no se modifican).
- Sin fila y con asignación que no trabaja ese día → Descanso.
- Sin fila y sin asignación → sin programación, con alerta.
- Guardar el calendario sobre un rango explícito (periodo + agencia + colaboradores): los días en blanco del rango se graban con `shift_id` NULL. Fuera del rango: sin programar.
- Feriado con turno programado: se mantiene el comportamiento actual (no hay falta; si marca, "Feriado trabajado").

## 4. Horas trabajadas y tardanza (RP-01, RP-02)

Las etiquetas "NORMAL", "FALTÓ" y "DESCANZO / VACACIONES" del informe son ejemplos, no salida de este sistema.

Sonda con datos de CP-01 (turno 08:00-15:00 con defaults de migración: tolerancia 10, break 60, `daily_hours` 8.00; entrada 08:06, salida 15:03):

```
ATTENDANCE status=present late=0 worked_hours=5.95 overtime=0.00 break_minutes=0
REPORT calculate={"worked_minutes":417,"break_minutes":0,...,"late_minutes":0}
```

| Fórmula | Dónde | Break que descuenta | CP-01 |
|---|---|---|---|
| Guardada en `attendances.worked_hours` | `AttendanceService::clockOut`, `ManualAttendanceEdit::save` → `breakToDeduct` | Mayor entre break marcado y break fijo del turno (o `lunch_minutes` sin turno) si la jornada pasa de 300 min; nada con `lunch_paid` | 5:57 |
| Recalculada por el reporte | `AttendanceReportService::calculate` → `subtractBreak` | Solo el break marcado | 6:57 |

Se unificará a una sola. Cuál descuenta es la pregunta "¿se descuenta el break?" de RR. HH.

RP-02: con tolerancia 10, 08:06 da tardanza 0 y estado `present`. Pasada la tolerancia, la tardanza se cuenta desde la hora de entrada (08:11 → 11 min); no se cambia (regla de RR. HH.).

## 5. Estados

Columna `attendances.status` (enum de BD, no se toca): present, late, absent, excused, half_day, holiday, day_off, incomplete. Descanso, Vacaciones, Permiso, Descanso médico y Sin programación se derivan (programación + licencias) en un solo mapper, con etiquetas en un solo archivo de traducciones, usado por calendario y todos los reportes.

## 6. Lista para RR. HH. (30/oct)

1. Tolerancia en minutos (hoy: por turno `grace_period_minutes`, por empresa `default_grace_minutes`=10).
2. ¿Se redondea la tardanza? ¿Se cuenta desde la entrada o desde el fin de la tolerancia?
3. ¿Se descuenta el break fijo aunque no se marque? (decide la fórmula única de §4; hoy `lunch_minutes`=45, break del turno por defecto 60).
4. ¿Las horas extras requieren aprobación previa? (hoy: aprobación posterior, `overtime_status`).
5. Lista final de estados, incluido **Descanso médico** como estado propio.
6. ¿Quién programa y con cuánta anticipación?
7. Salida anticipada (el informe no la define).
8. Feriado con turno programado: hoy no genera falta y, si marca, "Feriado trabajado". ¿Se confirma?
9. Vacaciones: ¿cuántos días de derecho corresponden y cómo se generan o acumulan (por año de servicio, por régimen)? ¿Los días usados se cuentan calendario o hábiles? ¿El saldo es por periodo vacacional o histórico?
10. Horas extras: el informe las define como "horas marcadas después de la salida del horario"; hoy son horas trabajadas menos la jornada base (incluye llegar antes). ¿Cuál vale?

## 7. Exclusiones de esta ronda

Ninguna. La exclusión del saldo de vacaciones quedó revocada el 25/10/2026: se implementan días usados y saldo (derecho − usados), y la regla de generación del derecho queda como pregunta 9 para RR. HH.

## 8. Plan por fases

| Fase | Contenido | Hallazgos / casos |
|---|---|---|
| 1 | HQ-03: causa con el registro real; corrección de "Marcación incompleta". | HQ-03 · CP-05 |
| 2 | Pruebas de caracterización de las 7 copias, del respaldo y de las dos fórmulas. | — |
| 3 | `ScheduleResolver` + `employee_schedule_days`; reemplazo de las 7 copias. | HQ-01, HQ-02 · CP-06, CP-07 |
| 4 | Fórmula única de horas (parámetro pendiente de RR. HH.) y mapper de estados. | RP-01, RP-02, RP-03 · CP-01 a CP-04 |
| 5 | Calendario de programación; filtro de sede recordado y colores por horario. | HQ-05, HQ-04 · CP-09 |
| 6 | Reporte final completo; `documentacion_cambios_qa.html`. | RP-04 · CP-08 |
