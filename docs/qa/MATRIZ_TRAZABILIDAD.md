# Matriz de trazabilidad · Informe de QA Ronda 2

Fuente: `docs/qa/INFORME_QA_RONDA2.md` (v1.0, 25/10/2026). Una fila por requisito. Una fila solo pasa a **hecho** con una prueba y su salida real.

Estados: **pendiente** · **en curso** · **hecho** · **bloqueado por regla pendiente** (falta una regla de RR. HH., ver §5.4 del informe y la lista de RR. HH. en `MAPA_FASE0.md` §6).

Fases (orden del plan de QA, sección 7 del informe):

| Fase | Contenido | Rama |
|---|---|---|
| 1 | HQ-03: corrección de salidas y permisos faltantes en la base | `qa-ronda2/fase-1-hq03` |
| 2 | Pruebas de caracterización (comportamiento actual) | `qa-ronda2/fase-2-caracterizacion` (en master, `352982f`) |
| 3 | Resolver único + horario por fecha (HQ-01) + programación por calendario (HQ-05) | `qa-ronda2/fase-3-resolver` (resolver sin migración; la tabla por fecha espera el merge del respaldo de `deploy.sh`) |
| 4 | Marcación con el horario del día (HQ-02) | — |
| 5 | Horas trabajadas, tolerancia, estados y vacaciones (RP-01, RP-02, RP-03, CP-04) | — |
| 6 | Reporte final completo y cuadre (RP-04, CP-08) | — |
| 7 | Filtro de calendario por sede (HQ-04) | — |
| Cierre | CP-01 a CP-09 con datos equivalentes a los del informe | — |

Última actualización: 25/10/2026, fase 1 en curso (espera merge del respaldo de `deploy.sh`) y fase 3 en curso (resolver sin migración).

## 1. Hallazgos

| ID | Requisito (cita del informe) | Fase | Archivos tocados | Prueba | Estado | Evidencia |
|---|---|---|---|---|---|---|
| HQ-01 | "Asignar horarios por fecha… cada día tiene su propio horario o es día libre." | 3 | `Attendance/Services/ScheduleResolver.php`, `DaySchedule.php`, `Console/AttendanceFlag.php` y las 8 copias: `AttendanceService`, `ManualAttendanceEdit`, `ScheduleGate`, `MarkAbsences`, `SendEntryReminders`, `AttendanceCalendarService`, `LiveBoardService`, `AttendanceReportService`, `HR/.../Api/ShiftController` | `ScheduleResolutionCharacterizationTest` (11, bandera apagada) y `ScheduleResolutionV2Test` (13, encendida) | en curso | Rama `qa-ronda2/fase-3-resolver`: 24 passed (115 assertions); regresión completa 628 passed (8873 assertions). Resolver único detrás de `schedule_v2` (`docs/qa/BANDERAS.md`). Falta la excepción por fecha (`employee_schedule_days`, migración). |
| HQ-02 | "Buscar el horario programado para esa fecha y comparar contra él. Si ese día no tiene horario, marcarla como «fuera de programación» y avisar a la jefatura." | 4 | — | `ScheduleResolutionV2Test` | en curso | Con `schedule_v2`: marcar un domingo sin turno ya no mide tardanza contra otro horario (antes: 60 min contra A) y queda `OUTSIDE_SCHEDULE` (decisión 1: mecanismo existente, sin tocar el enum). Falta: persona sin ninguna asignación y aviso a la jefatura (fase 4). |
| HQ-03 | "Permitir que RR. HH. o el supervisor autorizado registre o corrija la salida indicando el motivo, con auditoría… mostrar el estado «Marcación incompleta»." | 1 | `tests/Feature/MissingExitCorrectionTest.php` | `MissingExitCorrectionTest` (7) | en curso | `09fa6e4`: 7 passed (45 assertions). Bloqueos por diseño confirmados. Error encontrado: Gerencia sin `attendance.edit` y `attendance.authorize_offshift` inexistente en una base desplegada solo con migraciones (`tunesa_load`: `diferencias: 6`). La corrección (migración aditiva) espera el merge del respaldo de `deploy.sh`. |
| HQ-04 | "Agregar un filtro por sede (y luego por área o colaborador) que recuerde la última sede elegida, y usar un color por horario." | 7 | — | — | pendiente | Hoy: `CalendarFilters` ya filtra por sede, zona, área, cargo, turno y búsqueda; no recuerda la sede ni usa `shifts.color`. |
| HQ-05 | "Calendario mensual por agencia donde se «pinta» cada día con un horario. Agregar copiar semana, repetir un patrón y programar a varios colaboradores a la vez, además de alertar sobre colaboradores sin programación." | 3 | — | — | pendiente | — |
| RP-01 | "Calcular las horas a partir de las marcaciones reales. Escribir la fórmula en el pie del reporte." | 5 | — | — | bloqueado por regla pendiente | Dos fórmulas hoy (5:57 guardada / 6:57 reporte), sonda en `MAPA_FASE0.md` §4. Unificar no depende de RR. HH.; elegir si se descuenta el break sí. |
| RP-02 | "Definir la tolerancia (en minutos), aplicarla igual a la tardanza, al estado y al reporte final, y mostrarla en el encabezado del reporte." | 5 | — | — | bloqueado por regla pendiente | Con tolerancia 10: 08:06 → tardanza 0, estado `present` (caracterización). |
| RP-03 | "Separar los estados en Descanso y Vacaciones, corregir la ortografía y agregar «Permiso» y «Marcación incompleta»." + "saber cuántos días de vacaciones se usaron". | 5 | — | — | pendiente | "DESCANZO" no existe en el código. Días usados ya se calculan en `PayrollExportService` (`vacation_days`). |
| RP-04 | "Agregar T. faltas, T. horas trabajadas y días de descanso/vacaciones, y comprobar que los totales cuadren con la suma de los reportes diarios." | 6 | — | — | pendiente | — |

## 2. Casos de prueba (5.5)

| ID | Escenario → resultado esperado | Fase | Archivos tocados | Prueba | Estado | Evidencia |
|---|---|---|---|---|---|---|
| CP-01 | Entrada 08:06, salida 15:03 → "tardanza y estado coherentes con la tolerancia; horas trabajadas según la fórmula acordada (6:57 antes del break)". | 5 | — | — | bloqueado por regla pendiente | Hoy: tardanza 0, `present`, `worked_hours` 5.95 vs reporte 417 min. |
| CP-02 | Sin marcaciones y con horario → "Estado Faltó, 00:00 horas y 1 falta en el reporte final". | 5 / 6 | — | — | pendiente | Hoy `mark-absences` crea la falta (caracterización). |
| CP-03 | Día libre → "Estado Descanso; no cuenta como falta". | 5 | — | — | pendiente | — |
| CP-04 | Vacaciones aprobadas → "Estado Vacaciones; no cuenta como falta y descuenta del saldo". | 5 | — | — | bloqueado por regla pendiente | Estado, falta y días usados: se implementan en la fase 5. Saldo: espera la pregunta 9 de RR. HH. (por periodo vacacional o histórico), que define el esquema; no se crea `vacation_entitlement_days` todavía. |
| CP-05 | Entrada sin salida → "Estado Marcación incompleta; RR. HH. corrige con motivo y queda auditado". | 1 | `tests/Feature/MissingExitCorrectionTest.php` | `MissingExitCorrectionTest` | en curso | Corrección y auditoría pasan para RR. HH. y supervisor (`09fa6e4`). Falta: permisos en la base y la etiqueta en los reportes. |
| CP-06 | Horarios A y B en días distintos → "Cada día se evalúa con su horario". | 3 | ver HQ-01 | `ScheduleResolutionV2Test` | en curso | Con `schedule_v2`, A (lun-vie) y B (sáb) a la vez: calendario, reporte, mapa en vivo, marcación, faltas, recordatorio y app eligen A el lunes y B el sábado (reporte: 6 días programados en vez de 1). Pasa a hecho cuando la bandera se encienda en la empresa piloto. |
| CP-07 | Marcación antes del horario del día → "Se compara con el horario de ese día y no con otro". | 4 | — | — | pendiente | — |
| CP-08 | Cierre de octubre → "Cada total del reporte final es igual a la suma de los reportes diarios". | 6 | — | — | pendiente | — |
| CP-09 | Varias sedes → "Al filtrar por una sede solo se ven sus colaboradores". | 7 | — | — | pendiente | Filtro existe; sin prueba de esta ronda todavía. |

## 3. Reporte de asistencias por día (5.1)

Hoy lo más cercano es el detalle de "Reporte de horas y faltas" (`HoursReportExporter::DETAIL_HEADERS`) y "Marcaciones" (`AttendanceExport`). Cuál se usa como 5.1 está en la lista de decisiones.

| ID | Columna o contenido | Fase | Archivos tocados | Prueba | Estado | Evidencia |
|---|---|---|---|---|---|---|
| R1-encabezado | Empresa, agencia y fecha | 6 | — | — | pendiente | — |
| R1-tolerancia | Tolerancia en el encabezado (RP-02) | 5 | — | — | bloqueado por regla pendiente | — |
| R1-colaborador | Colaborador | 6 | — | — | pendiente | Existe en el detalle. |
| R1-cargo | Cargo | 6 | — | — | pendiente | `users.position`; está en `AttendanceExport`, no en el detalle de horas. |
| R1-entrada-prog | Entrada (horario del día) | 6 | — | — | pendiente | El detalle trae el turno, no la hora programada aparte. |
| R1-entrada-marc | Marcado (entrada) | 6 | — | — | pendiente | Existe ("Entrada"). |
| R1-salida-prog | Salida (horario del día) | 6 | — | — | pendiente | — |
| R1-salida-marc | Marcado (salida) | 6 | — | — | pendiente | Existe ("Salida"). |
| R1-extras | H. extras | 6 | — | — | pendiente | Hoy separadas en diurnas y nocturnas. |
| R1-tardanza | Tardanza | 5 | — | — | bloqueado por regla pendiente | — |
| R1-estado | Estado (Normal, Tardanza, Faltó, Marcación incompleta, Descanso, Vacaciones, Permiso, Descanso médico) | 5 | — | — | pendiente | — |
| R1-horas | H. trabaj. | 5 | — | — | bloqueado por regla pendiente | — |
| R1-pie | Fórmula de horas en el pie (RP-01) | 5 | — | — | bloqueado por regla pendiente | — |

## 4. Reporte de asistencias por colaborador (5.2)

| ID | Columna o contenido | Fase | Archivos tocados | Prueba | Estado | Evidencia |
|---|---|---|---|---|---|---|
| R2-encabezado | Agencia y colaborador | 6 | — | — | pendiente | — |
| R2-fecha | Fecha | 6 | — | — | pendiente | Existe en el detalle. |
| R2-entrada-prog | Entrada (horario del día) | 6 | — | — | pendiente | Defecto previo encontrado: las filas de falta del reporte de horas salen sin turno (`emptyDay() + [...]` conserva `'shift' => null`), en los dos modos. Fijado en `ScheduleResolutionV2Test`. |
| R2-entrada-marc | Marcado (entrada) | 6 | — | — | pendiente | — |
| R2-salida-prog | Salida (horario del día) | 6 | — | — | pendiente | — |
| R2-salida-marc | Marcado (salida) | 6 | — | — | pendiente | — |
| R2-extras | H. extras | 6 | — | — | pendiente | — |
| R2-tardanza | Tardanza | 5 | — | — | bloqueado por regla pendiente | — |
| R2-estado | Estado; un sábado libre dice cuál aplica (Descanso o Vacaciones) | 5 | — | — | pendiente | — |
| R2-horas | H. trabaj. | 5 | — | — | bloqueado por regla pendiente | — |

## 5. Reporte final de asistencias (5.3)

Hoy lo más cercano es el resumen de "Reporte de horas y faltas" (`HoursReportExporter::SUMMARY_HEADERS`).

| ID | Columna o contenido | Fase | Archivos tocados | Prueba | Estado | Evidencia |
|---|---|---|---|---|---|---|
| R3-encabezado | Empresa y periodo | 6 | — | — | pendiente | — |
| R3-colaborador | COLABORADOR | 6 | — | — | pendiente | Existe. |
| R3-cargo | CARGO | 6 | — | — | pendiente | No está en el resumen. |
| R3-dni | DNI "completo (8 dígitos) en cada fila" | 6 | — | — | pendiente | Decisión 10: se muestra tal cual, con su tipo. `users.document_number` es `VARCHAR(20)` y el Excel lo escribe como texto (`HoursReportExporter::row`): conserva ceros. Conteo: SQLite local 9 con 8 dígitos y 14 vacíos; `tunesa_load` 60 con 8 dígitos y 9 vacíos; producción: consulta 8 de `consultas_produccion.md`. |
| R3-agencia | AGENCIA | 6 | — | — | pendiente | Existe ("Sucursal"). |
| R3-tardanzas | T. TARDANZAS | 6 | — | — | pendiente | Existe en minutos. |
| R3-extras | T. HORAS EXTRAS | 6 | — | — | pendiente | Hoy separadas en diurnas y nocturnas. |
| R3-faltas | T. FALTAS | 6 | — | — | pendiente | Existe (injustificadas y justificadas). |
| R3-horas | T. HORAS TRABAJ. | 6 | — | — | bloqueado por regla pendiente | Existe; depende de la fórmula única. |
| R3-desc-vac | DESCANSO / VACAC. | 6 | — | — | pendiente | Decisión 4: dos columnas, Descanso y Vacaciones (más saldo cuando exista). No existe hoy. |
| R3-vac-usadas | Días de vacaciones usados por colaborador (RP-03, CP-04) | 5 | — | — | pendiente | Cálculo existente reutilizable: `PayrollExportService` (`vacation_days`). |
| R3-vac-saldo | Saldo = derecho − usados (CP-04) | 5 | — | — | bloqueado por regla pendiente | Ningún campo guarda el derecho (búsqueda de vacation/entitle/saldo). |

## 6. Reglas de cálculo (5.4)

| ID | Concepto: propuesta de QA | Pregunta a RR. HH. | Fase | Estado | Evidencia |
|---|---|---|---|---|---|
| 5.4-horario | "El programado para esa fecha, o día libre." | ¿Quién programa y con cuánta anticipación? | 3 | bloqueado por regla pendiente | — |
| 5.4-tolerancia | "Minutos de gracia en la entrada; dentro de ellos no hay tardanza." | ¿Cuántos minutos? | 5 | bloqueado por regla pendiente | Hoy: `grace_period_minutes` del turno, `default_grace_minutes` = 10. |
| 5.4-tardanza | "Minutos entre la hora de entrada del horario y la marcación, fuera de tolerancia." | ¿Se redondea? | 5 | bloqueado por regla pendiente | Hoy, pasada la tolerancia, se cuenta desde la hora de entrada. |
| 5.4-horas | "Salida marcada menos entrada marcada, menos el break si corresponde." | ¿Se descuenta el break? | 5 | bloqueado por regla pendiente | — |
| 5.4-extras | "Horas marcadas después de la salida del horario." | ¿Requieren aprobación previa? | 5 | bloqueado por regla pendiente | Hoy: horas trabajadas − jornada base (`AttendanceService.php:457`), aprobación posterior. |
| 5.4-estados | "Normal, Tardanza, Faltó, Marcación incompleta, Descanso, Vacaciones y Permiso." | ¿Faltan estados? | 5 | bloqueado por regla pendiente | Acordado: + Descanso médico. |

## 7. Criterios de conformidad (sección 7)

| ID | Criterio | Fase | Estado | Evidencia |
|---|---|---|---|---|
| C7-1 | "Cero hallazgos críticos y altos abiertos." | Cierre | pendiente | Abiertos: HQ-01 (crítico), HQ-02, HQ-03, HQ-05, RP-01 (altos). |
| C7-2 | "Los casos CP-01 a CP-09 aprobados." | Cierre | pendiente | — |
| C7-3 | "El reporte final cuadra con la suma de los reportes diarios del periodo." | 6 | pendiente | — |
| C7-4 | "Reglas de cálculo firmadas por RR. HH." | — | bloqueado por regla pendiente | Fecha prevista en el informe: vie 30 oct. |

## 8. Decisiones tomadas y pendientes de confirmación

Decisiones del 25/10/2026 sobre las ambigüedades del informe. Las marcadas con QA o RR. HH. se aplican de forma provisional y esperan su confirmación.

| # | Tema | Decisión | Confirma |
|---|---|---|---|
| 1 | "Fuera de programación" (HQ-02 vs 5.4) | Se usa el mecanismo existente `OUTSIDE_SCHEDULE`, sin tocar el enum de estados. | QA |
| 2 | Marcar un día sin horario | Gana la configuración de la empresa (`punch_requires_schedule`, de fábrica: sí). No se cambia el valor de fábrica. | RR. HH. |
| 3 | Estados que 5.4 no lista | Se mantienen Media jornada (`half_day`), Feriado (`holiday`) y Falta justificada (`excused`). | QA |
| 4 | Descanso y vacaciones en el reporte final | Dos columnas, más la de saldo cuando exista. | — |
| 5 | Horas extras | Bloqueada por regla pendiente (pregunta 10 de RR. HH.). | RR. HH. |
| 6 | Formato de horas | H:MM en los reportes 5.1 a 5.3. Las exportaciones decimales que consume planilla no se tocan sin listar antes sus consumidores. | — |
| 7 | Nombres de los reportes | Los títulos del informe no existen en el código. Provisional: 5.1 y 5.2 = detalle de "Reporte de horas y faltas"; 5.3 = su resumen. | QA |
| 8 | HQ-04, área y colaborador | Filtro por sede nuevo (recuerda la selección, color por horario). Área y colaborador: solo verificar que funcionan. | — |
| 9 | HQ-05, "repetir un patrón" | "Copiar semana" y "repetir las semanas seleccionadas hasta una fecha". | QA |
| 10 | Documento | Se muestra tal cual, con su tipo; sin modificar datos. | — |

Consumidores de horas decimales hoy (decisión 6), según el código:
- `HoursReportExporter`, usado por el reporte web de horas (Excel y PDF) y por `reports:send-monthly` (Excel mensual por correo).
- `PayrollExportService` ("Cruce con planilla", web y `GET /api/admin/payroll/export`): `worked_hours`, `overtime_hours`, `payable_hours`.
- `AttendanceResource` y `AttendanceController` (API de la app): `worked_hours`; la app móvil lo lee en `mobile/lib/features/attendance/domain/models.dart`.
