# Matriz de trazabilidad · Informe de QA Ronda 2

Fuente: `docs/qa/INFORME_QA_RONDA2.md` (v1.0, 25/10/2026; transcripción del Word `Informe_QA_Ronda2_Horarios_Reportes.docx`, imágenes incluidas). Una fila por requisito. Una fila solo pasa a **hecho** con una prueba y su salida real.

Estados: **pendiente** · **en curso** · **hecho** · **bloqueado por regla pendiente** (falta una regla de RR. HH.; ver `docs/qa/documentacion_cambios_qa.html` §4).

Última actualización: 25/10/2026, todas las fases en master. Regresión completa: ver el último commit.

| Fase | Contenido | Merge en master |
|---|---|---|
| 1 | HQ-03: pruebas de los bloqueos + permisos faltantes (migración `2026_11_01_000001`) | `d9f1c82`, `fc9acae` |
| 2 | Caracterización del comportamiento anterior | `352982f` |
| 3 | `ScheduleResolver` + bandera `schedule_v2`; horario por fecha (`employee_schedule_days`, migración `2026_11_01_000002`) y programación por calendario | `d9f1c82`, `f9365e0` |
| 4 | HQ-02: fuera de programación + aviso a la jefatura | `ab346f3` |
| 5–6 | RP-01 a RP-04: fórmula única (`hours_v2`), tolerancia y fórmula impresas, estados (`DayStatus`), reporte final completo, casos CP | `d59d56c` |
| 7 | HQ-04: sede recordada y color por horario | último merge |

Pruebas citadas: `ScheduleResolutionCharacterizationTest` (bandera apagada = comportamiento anterior), `ScheduleResolutionV2Test` (encendida), `ScheduleProgrammingTest`, `MissingExitCorrectionTest`, `QaRonda2CasesTest` (CP-01 a CP-05, CP-08, CP-09, HQ-04), `Reports/AttendanceReportServiceTest`, `Reports/HoursReportExportTest`.

## 1. Hallazgos

| ID | Requisito (cita del informe) | Fase | Archivos principales | Prueba | Estado | Evidencia |
|---|---|---|---|---|---|---|
| HQ-01 | "Asignar horarios por fecha… cada día tiene su propio horario o es día libre." | 3 | `ScheduleResolver`, `EmployeeScheduleDay`, migración `2026_11_01_000002`, las 8 copias | `ScheduleProgrammingTest::test_cp06…`, `ScheduleResolutionV2Test` | hecho (con `schedule_v2`) | Juan A la semana 1 y B la semana 2; Juana B un sábado programado; reporte con 10 días programados por persona. |
| HQ-02 | "Si ese día no tiene horario, marcarla como «fuera de programación» y avisar a la jefatura." | 4 | `AttendanceService::clockIn`, `AttendanceOutsideSchedule` | `ScheduleProgrammingTest::test_hq02…` (2) | hecho (con `schedule_v2`) | `OUTSIDE_SCHEDULE` + aviso al supervisor: "fuera de programación (no tenía horario ese día)". Apagada: igual que antes. |
| HQ-03 | "Permitir que RR. HH. o el supervisor autorizado registre o corrija la salida… con auditoría… «Marcación incompleta»." | 1 | migración `2026_11_01_000001`, `MissingExitCorrectionTest` | `MissingExitCorrectionTest` (8), `QaRonda2CasesTest::test_cp05…` | hecho | Causa: permisos solo en el seeder (Gerencia sin `attendance.edit`, `attendance.authorize_offshift` inexistente). Migración probada en SQLite y MySQL 8 (`tunesa_load`: 6 → 0 → 6 diferencias con up/rollback). |
| HQ-04 | "Filtro por sede (y luego por área o colaborador) que recuerde la última sede elegida, y usar un color por horario." | 7 | `CalendarWebController::rememberBranch`, `AttendanceCalendarService` (`shift_color`), `calendar.blade.php` | `QaRonda2CasesTest::test_hq04…` (2), `test_cp09…` | hecho | Cookie por usuario; "Todas" también se recuerda; sede fuera de alcance se ignora; franja de color y leyenda. Área y búsqueda ya funcionaban (verificado). |
| HQ-05 | "Calendario mensual por agencia donde se «pinta» cada día… copiar semana, repetir un patrón, varios colaboradores a la vez, alertar sin programación." | 3 | `ScheduleWebController`, `ScheduleProgramming`, `Schedule/Index.vue`, rutas `shifts.schedule*` | `ScheduleProgrammingTest` (9) | hecho | 42 días guardados con auditoría; repetir quincena (14) y copiar semana (21); validaciones y permisos; asignación que choca con la vigente bloqueada. |
| RP-01 | "Calcular las horas a partir de las marcaciones reales. Escribir la fórmula en el pie del reporte." | 5 | `AttendanceReportService::calculate`/`rules`, `hours-pdf`, `hours-table` | `QaRonda2CasesTest::test_cp01…`, `HoursReportExportTest` | hecho (con `hours_v2`; break: confirma RR. HH.) | 5:57 en jornada y reporte; 6:57 con refrigerio pagado; fórmula en el pie del PDF y la web. |
| RP-02 | "Definir la tolerancia… aplicarla igual a la tardanza, al estado y al reporte final, y mostrarla en el encabezado." | 5 | `AttendanceReportService::rules`, vistas del reporte | `test_cp01…`, `HoursReportExportTest::test_pdf…` | hecho (minutos y redondeo: confirma RR. HH.) | 08:06 → tardanza 0 y Normal en jornada, reporte y calendario; "Tolerancia de entrada…" en el encabezado. |
| RP-03 | "Separar los estados en Descanso y Vacaciones… agregar «Permiso» y «Marcación incompleta»." + saber cuántos días de vacaciones se usaron. | 5 | `DayStatus`, `lang/es/attendance.php`, calendario, reporte, `AttendanceExport`, `AttendanceResource` | `test_cp03…`, `test_cp04…`, `AttendanceReportServiceTest` | hecho | Descanso, Vacaciones (V), Permiso, Descanso médico, Feriado, Marcación incompleta; días de vacaciones = planilla (`LeaveRequest::daysWithin`). |
| RP-04 | "Agregar T. faltas, T. horas trabajadas y días de descanso/vacaciones, y comprobar que los totales cuadren." | 6 | `HoursReportExporter`, `AttendanceReportService`, `hours-pdf` | `test_cp08…`, `HoursReportExportTest` | hecho | Resumen = suma de días para todos los totales; columnas nuevas en Excel, PDF y web. |

## 2. Casos de prueba (5.5)

| ID | Escenario → resultado esperado | Prueba | Estado | Resultado real |
|---|---|---|---|---|
| CP-01 | 08:06–15:03 → tardanza/estado coherentes; horas según fórmula (6:57 antes del break). | `QaRonda2CasesTest::test_cp01…` | hecho | Tardanza 0, Normal; 5:57 (6:57 − 60); 6:57 con refrigerio pagado. |
| CP-02 | Sin marcaciones y con horario → Faltó, 00:00 y 1 falta en el final. | `test_cp02…` | hecho | Faltó, 0:00, 1 falta. |
| CP-03 | Día libre → Descanso; no es falta. | `test_cp03…` | hecho | Descanso; 0 faltas; 1 día de descanso. |
| CP-04 | Vacaciones aprobadas → Vacaciones; no es falta; descuenta del saldo. | `test_cp04…` | bloqueado por regla pendiente (solo el saldo) | Vacaciones, 0 faltas, 4 días usados = planilla. Saldo: espera la pregunta 9 de RR. HH. |
| CP-05 | Entrada sin salida → Marcación incompleta; RR. HH. corrige con motivo y auditoría. | `test_cp05…`, `MissingExitCorrectionTest` | hecho | Marcación incompleta; corrección auditada; 6:02 recalculadas. |
| CP-06 | Horarios A y B en días distintos → cada día con su horario. | `ScheduleProgrammingTest::test_cp06…` | hecho (con `schedule_v2`) | A/B por día en gate, calendario, reporte y faltas. |
| CP-07 | Marcación antes del horario del día → se compara con ese horario. | `ScheduleProgrammingTest::test_cp07…` | hecho (con `schedule_v2`) | B 14:00, marca 13:55: tardanza 0, Normal, sin fuera de horario. |
| CP-08 | Cierre de octubre → total final = suma de diarios. | `test_cp08…` | hecho | Todos los totales cuadran; Juan: 22 prog., 2 trab., 20 faltas, 9 descansos, 25 min. |
| CP-09 | Varias sedes → al filtrar, solo sus colaboradores. | `test_cp09…`, `test_hq04…` | hecho | Trujillo: 4; Lima: 1; sede recordada. |

## 3. Reporte de asistencias por día (5.1) · detalle diario del reporte de horas

| ID | Columna o contenido | Estado | Evidencia |
|---|---|---|---|
| R1-encabezado | Empresa, agencia y fecha | hecho | Filtros del reporte (empresa, sucursal, rango) y encabezado del PDF. |
| R1-tolerancia | Tolerancia en el encabezado | hecho | `rules()['tolerance']` en web y PDF (`HoursReportExportTest::test_pdf…`). |
| R1-colaborador / R1-cargo | Colaborador, Cargo | hecho | Columnas "Colaborador", "Cargo" del detalle (`HoursReportExportTest`). |
| R1-entrada-prog / R1-salida-prog | Entrada y Salida del horario | hecho | `scheduled_in/out` (`test_cp01…`: 08:00/15:00). |
| R1-entrada-marc / R1-salida-marc | Marcado entrada y salida | hecho | `clock_in/out` (08:06/15:03). |
| R1-extras / R1-tardanza / R1-horas | H. extras, Tardanza, H. trabaj. en H:MM | hecho | Celdas `[h]:mm` en Excel; `hm()` en web/PDF. |
| R1-estado | Estado con su etiqueta | hecho | `day_status_label` (Normal, Faltó, Descanso, Vacaciones…). |
| R1-pie | Fórmula en el pie | hecho | `rules()['formula']` en web y PDF. |

## 4. Reporte por colaborador (5.2) · mismo detalle diario filtrado por colaborador

| ID | Columna o contenido | Estado | Evidencia |
|---|---|---|---|
| R2-* | Fecha, entrada/salida programada y marcada, H. extras, tardanza, estado, horas | hecho | Mismas columnas que 5.1; un día por fila, incluidos descanso, feriado y licencias (`AttendanceReportServiceTest`). |
| R2-estado | Un sábado libre dice cuál aplica (Descanso o Vacaciones) | hecho | `test_cp03…` (Descanso), `test_cp04…` (Vacaciones). |

## 5. Reporte final (5.3) · resumen del reporte de horas

| ID | Columna | Estado | Evidencia |
|---|---|---|---|
| R3-colaborador, cargo, agencia | Colaborador, Cargo, Agencia | hecho | Resumen Excel/PDF. |
| R3-dni | DNI completo | hecho | Tipo + número tal cual, como texto (conserva ceros: `01234567` en `QaRonda2CasesTest`). Consulta 8 de producción para contar los que no tienen 8 dígitos. |
| R3-tardanzas, extras, faltas, horas | T. tardanzas, T. horas extras, T. faltas, T. horas trabajadas | hecho | H:MM; cuadre `test_cp08…`. |
| R3-desc-vac | Descanso y Vacaciones (dos columnas, decisión 4) | hecho | `rest_days`, `vacation_days` (+ feriado, permiso, descanso médico). |
| R3-vac-usadas | Días de vacaciones usados | hecho | `test_cp04…`: 4 = planilla. |
| R3-vac-saldo | Saldo = derecho − usados | bloqueado por regla pendiente | Pregunta 9 de RR. HH.; no se creó el campo de derecho. |

## 6. Reglas de cálculo (5.4)

| ID | Concepto | Estado | Valor hoy (configurable) |
|---|---|---|---|
| 5.4-horario | Horario del día | hecho (quién programa: RR. HH.) | Programación por calendario; quien tenga `shifts.assign`. |
| 5.4-tolerancia | Tolerancia | bloqueado por regla pendiente (confirmar) | Por turno; 10 min por defecto de la empresa. |
| 5.4-tardanza | Tardanza, ¿redondeo? | bloqueado por regla pendiente (confirmar) | Sin redondeo; pasada la tolerancia, desde la hora de entrada. |
| 5.4-horas | ¿Se descuenta el break? | bloqueado por regla pendiente (confirmar) | Mayor entre marcado y fijo del turno; nada con refrigerio pagado (`lunch_paid`). |
| 5.4-extras | Horas extra, ¿aprobación previa? | bloqueado por regla pendiente | Trabajadas − jornada base; aprobación posterior. |
| 5.4-estados | Lista de estados | hecho (confirmar con QA) | Normal, Tardanza, Faltó, Marcación incompleta, Descanso, Vacaciones, Permiso, Descanso médico, Feriado, Media jornada, Falta justificada. |

## 7. Criterios de conformidad (sección 7)

| ID | Criterio | Estado | Evidencia |
|---|---|---|---|
| C7-1 | Cero hallazgos críticos y altos abiertos | hecho en código; vigente al encender las banderas | HQ-01, HQ-02, HQ-03, HQ-05, RP-01 hechos. |
| C7-2 | CP-01 a CP-09 aprobados | 8 de 9 completos | CP-04: saldo bloqueado por regla pendiente. |
| C7-3 | El final cuadra con la suma de diarios | hecho | `test_cp08…`. |
| C7-4 | Reglas firmadas por RR. HH. | bloqueado por regla pendiente | Lista en la documentación §4. |

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
