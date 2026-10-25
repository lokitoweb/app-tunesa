<!-- Fuente: Informe_QA_Ronda2_Horarios_Reportes.docx (Jefatura de QA, Grupo Negreiros, v1.0, 25/10/2026). Transcripción fiel; las imágenes del original están transcritas en el Anexo A. -->

Grupo Negreiros · Informe de pruebas de QA · Ronda 2

**Informe de pruebas de QA · Ronda 2**

Horarios rotativos, marcaciones y reportes de asistencia

| **Proyecto** | Plataforma digital de gestión de personal (web y app móvil) |
| --- | --- |
| **Elaborado por** | Jefatura de QA, Grupo Negreiros |
| **Dirigido a** | Gerencia del proyecto y Área de Programación y Desarrollo |
| **Fuente de datos** | Hallazgos reportados en las pruebas y tres reportes de ejemplo (Tunesa expres, Ag. Trujillo, octubre de 2026) |
| **Fecha y versión** | 25 de octubre de 2026 · Versión 1.0 |

# **1. Resumen en un minuto**

| **9** hallazgos registrados | **1** de severidad crítica | **4** de severidad alta | **3** reportes revisados |
| --- | --- | --- | --- |

| **Mensaje clave: casi todo nace de un mismo problema** El sistema asume **un horario fijo por colaborador**, pero la operación **rota los horarios**. De ahí se desprenden la marcación mal evaluada (HQ-02), la programación poco práctica (HQ-05) y varios datos incorrectos en los reportes. Si se corrige el modelo de horarios, se corrigen varios hallazgos en cadena. **Recomendación de QA:** los reportes **no deben usarse todavía para planilla**. Antes hay que resolver HQ-01 (horarios rotativos), RP-01 (horas trabajadas) y HQ-03 (salidas que no se pueden corregir). |
| --- |

# **2. Cómo leer este informe**

Cada hallazgo tiene un **código** (HQ para pruebas del sistema y RP para reportes), un **tipo** y una **severidad**.

| **Tipo** | **Qué significa** |
| --- | --- |
| **Defecto** | Algo no funciona como debería. |
| **Regla de negocio** | Una condición real de la operación que el sistema debe respetar. |
| **Sugerencia** | Una mejora propuesta; el sistema funciona pero puede hacerse mejor. |
| **Por definir** | Falta acordar una regla con RR. HH. antes de decidir qué es correcto. |

**Severidad** (qué tan urgente es):

| **Severidad** | **Criterio** |
| --- | --- |
| **Crítica** | Bloquea el cálculo de la asistencia. Se corrige antes del piloto. |
| **Alta** | Una función importante falla o produce datos incorrectos. Se corrige antes del piloto. |
| **Media** | Afecta la comodidad de uso o un dato secundario. Se corrige en los meses siguientes. |

**Alcance y límites:** los hallazgos HQ salen de las pruebas del sistema. Los hallazgos RP salen de la **revisión de tres reportes de ejemplo** (no se ejecutaron contra datos reales). Los ejemplos con horarios A y B son **ilustrativos**.

# **3. Entender el problema con un ejemplo**

Así se ve una programación con **horarios rotativos** en la Ag. Trujillo durante dos semanas. Cada persona cambia de horario de una semana a otra, y los espacios en blanco son días libres:

|  | **Hoy en el sistema** | **Lo que se necesita** |
| --- | --- | --- |
| **Horario** | Uno solo por colaborador. | Uno por día, o día libre. |
| **Marcación** | No usa el horario del día. | Se compara con el horario programado ese día. |
| **Programación** | Colaborador por colaborador. | Calendario por agencia donde se marcan los días de cada horario. |
| **Reportes** | Tardanzas y horas pueden salir incorrectas. | Todo se calcula contra el horario del día. |

# **4. Hallazgos detallados**

Cada hallazgo sigue el mismo esquema para leerlo rápido: **dónde, qué pasó, por qué importa, qué se recomienda y cómo lo verificamos** en la re-prueba.

| **HQ-01** | **El sistema usa un solo horario, pero la operación rota los horarios** | **Regla de negocio** | **Crítica** |
| --- | --- | --- | --- |

| **Dónde** | Turnos y horarios · Asistencia (web y app) |
| --- | --- |
| **Qué pasó** | El sistema está diseñado para que cada colaborador tenga **un solo horario**, pero en la realidad los horarios se aplican de forma **rotativa**: cambian de una semana a otra o de un día a otro. |
| **Por qué importa** | Las tardanzas, faltas, horas extras y reportes se calculan contra el horario. Si el horario es incorrecto, todos los resultados salen mal aunque la marcación sea correcta. |
| **Qué se recomienda** | Asignar horarios **por fecha** y no como un dato fijo del colaborador: cada día tiene su propio horario o es día libre (ver la propuesta de programación en HQ-05). |
| **Cómo lo verificamos** | Un colaborador con horario A el lunes y horario B el martes ve el horario correcto cada día y su tardanza se calcula con el horario de ese día. |
| **Relación** | Informe de análisis inicial: H-05 y H-06. Informe de QA ronda 1: DEF-01. |

| **HQ-02** | **La marcación fuera de horario no considera el horario del día** | **Defecto** | **Alta** |
| --- | --- | --- | --- |

| **Dónde** | Marcación (app y web) |
| --- | --- |
| **Qué pasó** | Cuando un colaborador marca fuera de su horario, el sistema **no toma en cuenta el horario que le corresponde ese día**, y debería hacerlo. |
| **Ejemplo** | Hoy a Juan le toca el horario B (por ejemplo 14:00 a 21:00) y marca a las 13:55. El sistema debería compararlo con el horario de **hoy**; si lo compara con otro horario, lo mostrará como tardón o ausente sin serlo. |
| **Por qué importa** | Se generan tardanzas, faltas o salidas incorrectas. Un colaborador puntual puede aparecer como impuntual. |
| **Qué se recomienda** | Al registrar una marcación, buscar el horario programado para esa fecha y comparar contra él. Si ese día no tiene horario, marcarla como «fuera de programación» y avisar a la jefatura. |
| **Cómo lo verificamos** | Marcaciones antes, durante y después del horario de ese día producen el estado correcto: normal, tardanza o fuera de programación. |
| **Relación** | Depende de la solución de HQ-01. |

| **HQ-03** | **La salida sin marcar de Contreras Agreda Yohana no se puede corregir** | **Defecto** | **Alta** |
| --- | --- | --- | --- |

| **Dónde** | Asistencia · Corrección de marcaciones (web) |
| --- | --- |
| **Qué pasó** | La colaboradora **Contreras Agreda Yohana no marcó su salida** y el sistema **no permite corregirla**. Queda pendiente verificar la causa. |
| **Por qué importa** | La jornada queda incompleta, las horas trabajadas y las horas extras salen mal y la planilla se calcula con datos erróneos. Además, RR. HH. no tiene cómo resolver los olvidos de marcación, que son frecuentes. |
| **Qué se recomienda** | Revisar por qué el registro está bloqueado. Permitir que RR. HH. o el supervisor autorizado registre o corrija la salida indicando el motivo, con auditoría (quién, cuándo y valor anterior). Mientras no se corrija, mostrar el estado **«Marcación incompleta»**. |
| **Cómo lo verificamos** | Se corrige la salida de Yohana, queda el motivo y la auditoría, y las horas se recalculan en los reportes. |
| **Relación** | Informe de QA ronda 1: DEF-04. Informe de análisis inicial: H-02. |

| **HQ-04** | **El calendario se ve entreverado: falta filtrar por sede** | **Sugerencia** | **Media** |
| --- | --- | --- | --- |

| **Dónde** | Monitoreo · Calendario (web) |
| --- | --- |
| **Qué pasó** | Se sugiere que el calendario **se pueda filtrar por sede** para que no se vea entreverado. |
| **Por qué importa** | Con varias sedes y colaboradores en un mismo calendario la información se mezcla y es difícil saber quién trabaja dónde. |
| **Qué se recomienda** | Agregar un filtro por sede (y luego por área o colaborador) que recuerde la última sede elegida, y usar un color por horario. |
| **Cómo lo verificamos** | Al elegir una sede solo aparecen sus colaboradores y horarios. |

| **HQ-05** | **Programar la asistencia eligiendo agencia, horario y días** | **Sugerencia** | **Alta** |
| --- | --- | --- | --- |

| **Dónde** | Turnos y horarios · Programación (web) |
| --- | --- |
| **Qué pasó** | Se sugiere que la programación siga cuatro pasos: **elegir la agencia, elegir el horario y marcar los días** en que se asistirá con ese horario; luego **elegir otro horario y marcar sus días**. Los días que queden **en blanco** son días en que no se labora. |
| **Por qué importa** | Es la forma más natural de programar horarios rotativos: se ve el mes completo, se evita asignar horarios colaborador por colaborador y los días libres se entienden sin configuración adicional. |
| **Qué se recomienda** | Calendario mensual por agencia donde se «pinta» cada día con un horario. Agregar copiar semana, repetir un patrón y programar a varios colaboradores a la vez, además de alertar sobre colaboradores sin programación. |
| **Cómo lo verificamos** | Se programa un mes completo de un colaborador con dos horarios y días en blanco, y la app y los reportes respetan lo programado. |
| **Relación** | Informe de análisis inicial: H-05 y H-06. |

**Los cuatro pasos de la programación propuesta:**

| **RP-01** | **Las horas trabajadas no coinciden con las marcaciones** | **Defecto** | **Alta** |
| --- | --- | --- | --- |

| **Dónde** | Reportes por día y por colaborador |
| --- | --- |
| **Qué pasó** | El 01/10/2026 Juan Pérez marcó de **08:06 a 15:03** (6 h 57 min) y su horario es de 08:00 a 15:00 (7 h), pero **«Horas trabajadas» muestra 08:00**. |
| **Por qué importa** | Las horas trabajadas alimentan la planilla; un dato incorrecto afecta el pago. El valor no coincide ni con las marcaciones ni con el horario. |
| **Qué se recomienda** | Acordar la fórmula con RR. HH. (¿se descuenta el break?, ¿se redondea?) y calcular las horas a partir de las marcaciones reales. Escribir la fórmula en el pie del reporte. |
| **Cómo lo verificamos** | Para Juan Pérez del 01/10 el reporte muestra el valor que resulta de la fórmula acordada (por ejemplo 6:57 antes de descontar el break). |

| **RP-02** | **Tardanza de 6 minutos con estado «NORMAL»** | **Por definir** | **Media** |
| --- | --- | --- | --- |

| **Dónde** | Reportes por día y por colaborador |
| --- | --- |
| **Qué pasó** | Juan Pérez tiene **00:06 de tardanza** pero su estado es **NORMAL**. No queda claro si existe una tolerancia. |
| **Por qué importa** | Si hay tolerancia, la tardanza debería ser 00:00; si no la hay, el estado debería ser «Tardanza». Hoy el reporte da señales contradictorias. |
| **Qué se recomienda** | Definir la tolerancia (en minutos), aplicarla igual a la tardanza, al estado y al reporte final, y mostrarla en el encabezado del reporte. |
| **Cómo lo verificamos** | Con la tolerancia definida, la tardanza y el estado coinciden en los tres reportes. |

| **RP-03** | **El estado «Descanso / Vacaciones» mezcla dos situaciones** | **Sugerencia** | **Media** |
| --- | --- | --- | --- |

| **Dónde** | Reportes por día y por colaborador |
| --- | --- |
| **Qué pasó** | El reporte usa un solo estado, **«DESCANZO / VACACIONES»**, para Juana Pereda y para el 03/10 de Juan Pérez. Además, «DESCANZO» tiene un error ortográfico. |
| **Por qué importa** | El descanso (día libre del horario) y las vacaciones (solicitud aprobada) se controlan distinto. Mezclarlos impide saber cuántos días de vacaciones se usaron. |
| **Qué se recomienda** | Separar los estados en **Descanso** y **Vacaciones**, corregir la ortografía y agregar «Permiso» y «Marcación incompleta». |
| **Cómo lo verificamos** | Un día libre muestra «Descanso» y un día con vacaciones aprobadas muestra «Vacaciones». |

| **RP-04** | **El reporte final no muestra faltas ni horas trabajadas** | **Sugerencia** | **Media** |
| --- | --- | --- | --- |

| **Dónde** | Reporte final de asistencias |
| --- | --- |
| **Qué pasó** | El reporte final trae colaborador, cargo, DNI, agencia, **total de tardanzas** y **total de horas extras**, pero no trae faltas, horas trabajadas ni días de descanso o vacaciones. |
| **Por qué importa** | Para la planilla se necesita el cierre completo del periodo. Sin esas columnas hay que revisar los reportes diarios y sumar a mano. |
| **Qué se recomienda** | Agregar T. faltas, T. horas trabajadas y días de descanso/vacaciones, y comprobar que los totales cuadren con la suma de los reportes diarios. |
| **Cómo lo verificamos** | El total del reporte final es igual a la suma de los reportes por día del periodo. |

# **5. Reportes de asistencia: qué deben mostrar**

Se revisaron tres reportes de ejemplo de la empresa **Tunesa expres**. Cada uno responde a una pregunta distinta. Las celdas **amarillas** marcan los datos que QA observó.

## **5.1 Reporte de asistencias por día**

¿Para qué sirve? Para ver cómo le fue a cada persona de una agencia en una fecha. Empresa: Tunesa expres · Agencia: Ag. Trujillo · Fecha: 01/10/2026

| **Colaborador** | **Cargo** | **Entrada** | **Marcado** | **Salida** | **Marcado** | **H. extras** | **Tardanza** | **Estado** | **H. trabaj.** |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| JUAN PEREZ | COUNTER | 08:00 | 08:06 | 15:00 | 15:03 | 0:00 | 00:06 | **NORMAL** | **08:00** |
| FIDEL ALVAREZ | COUNTER | 08:00 |  | 15:00 |  | 0:00 | 00:00 | **FALTÓ** | 00:00 |
| JUANA PEREDA | COUNTER | **DESCANZO / VACACIONES** |

- **Horas trabajadas = 08:00** no coincide con las marcaciones (6 h 57 min) ni con el horario de 7 h (RP-01).

- **Tardanza 00:06 con estado NORMAL** es contradictorio si no se definió una tolerancia (RP-02).

- **«DESCANZO / VACACIONES»** mezcla dos situaciones y tiene un error ortográfico (RP-03).

- Fidel Alvarez figura como **FALTÓ** con 00:00 horas: el cálculo es coherente.

## **5.2 Reporte de asistencias por colaborador**

¿Para qué sirve? Para ver el historial de una persona día por día. Agencia: Ag. Trujillo · Colaborador: JUAN PEREZ

| **Fecha** | **Entrada** | **Marcado** | **Salida** | **Marcado** | **H. extras** | **Tardanza** | **Estado** | **H. trabaj.** |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 01/10/2026 | 08:00 | 08:06 | 15:00 | 15:03 | 0:00 | 00:06 | **NORMAL** | **08:00** |
| 02/10/2026 | 08:00 |  | 15:00 |  | 0:00 | 00:00 | **FALTÓ** | 00:00 |
| 03/10/2026 | **DESCANZO / VACACIONES** |

- Tiene las mismas observaciones que el reporte por día (RP-01, RP-02 y RP-03).

- El **03/10/2026 cae sábado**: el estado muestra «Descanso / Vacaciones» sin indicar cuál de los dos aplica.

## **5.3 Reporte final de asistencias**

¿Para qué sirve? Para cerrar el periodo y alimentar la planilla. Empresa: Tunesa expres · Periodo: octubre de 2026

Columnas del reporte actual en azul; **columnas sugeridas por QA en naranja**. Los valores son un ejemplo con los tres primeros días de octubre de Juan Pérez.

| **COLABORADOR** | **CARGO** | **DNI** | **AGENCIA** | **T. TARDANZAS** | **T. HORAS EXTRAS** | **T. FALTAS** | **T. HORAS TRABAJ.** | **DESCANSO / VACAC.** |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| JUAN PEREZ | COUNTER | — | Ag. Trujillo | 00:06 | 0:00 | 1 | por calcular | 1 |

- El reporte actual muestra solo tardanzas y horas extras (RP-04); para planilla faltan **faltas, horas trabajadas y días de descanso o vacaciones**.

- El DNI debe aparecer completo (8 dígitos) en cada fila.

## **5.4 Reglas de cálculo que hay que acordar**

Para que los tres reportes den los mismos números, QA propone fijar estas reglas con RR. HH. (son **propuestas**, no el comportamiento actual):

| **Concepto** | **Propuesta de regla** | **Por confirmar con RR. HH.** |
| --- | --- | --- |
| **Horario del día** | El programado para esa fecha, o día libre. | ¿Quién programa y con cuánta anticipación? |
| **Tolerancia** | Minutos de gracia en la entrada; dentro de ellos no hay tardanza. | ¿Cuántos minutos? |
| **Tardanza** | Minutos entre la hora de entrada del horario y la marcación, fuera de tolerancia. | ¿Se redondea? |
| **Horas trabajadas** | Hora de salida marcada menos hora de entrada marcada, menos el break si corresponde. | ¿Se descuenta el break? |
| **Horas extras** | Horas marcadas después de la salida del horario. | ¿Requieren aprobación previa? |
| **Estados** | Normal, Tardanza, Faltó, Marcación incompleta, Descanso, Vacaciones y Permiso. | ¿Faltan estados? |

## **5.5 Casos de prueba para validar los reportes**

Con estos casos QA comprobará, en la re-prueba, que los reportes son correctos:

| **Caso** | **Escenario** | **Resultado esperado** |
| --- | --- | --- |
| **CP-01** | Juan Pérez, 01/10: entrada 08:06 y salida 15:03. | Tardanza y estado coherentes con la tolerancia; horas trabajadas según la fórmula acordada (6:57 antes del break). |
| **CP-02** | Fidel Alvarez, 01/10: sin marcaciones y con horario. | Estado Faltó, 00:00 horas y 1 falta en el reporte final. |
| **CP-03** | Día libre del horario. | Estado Descanso; no cuenta como falta. |
| **CP-04** | Vacaciones aprobadas. | Estado Vacaciones; no cuenta como falta y descuenta del saldo. |
| **CP-05** | Yohana Contreras Agreda, entrada sin salida. | Estado Marcación incompleta; RR. HH. corrige con motivo y queda auditado. |
| **CP-06** | Colaborador con horarios A y B en días distintos. | Cada día se evalúa con su horario. |
| **CP-07** | Marcación antes del horario del día. | Se compara con el horario de ese día y no con otro. |
| **CP-08** | Cierre de octubre de 2026. | Cada total del reporte final es igual a la suma de los reportes diarios. |
| **CP-09** | Calendario con varias sedes. | Al filtrar por una sede solo se ven sus colaboradores. |

# **6. Relación con los informes anteriores**

Esta ronda confirma temas que ya se habían detectado. Que se repitan indica que son problemas de fondo:

| **Hallazgo actual** | **Informes anteriores** | **Qué confirma** |
| --- | --- | --- |
| **HQ-01 y HQ-05** | Análisis inicial: H-05 y H-06. QA ronda 1: DEF-01. | Los horarios rotativos siguen sin resolverse; es el hallazgo más repetido. |
| **HQ-02** | Análisis inicial: H-05. | La marcación debe evaluarse contra el horario del día. |
| **HQ-03** | Análisis inicial: H-02. QA ronda 1: DEF-04. | Hace falta controlar y corregir las marcaciones de salida. |
| **RP-03** | QA ronda 1: DEF-03. | Descanso y vacaciones deben distinguirse en todo el sistema. |

# **7. Plan de corrección y re-prueba**

Propuesta alineada con el cronograma y las reuniones de los viernes a las 3:00 p. m. (sujeta a la estimación de Desarrollo):

| **Acción** | **Responsable** | **Fecha** | **Vinculado a** |
| --- | --- | --- | --- |
| Acordar con RR. HH. las reglas de la sección 5.4 (tolerancia, horas, estados) | QA y RR. HH. | **Vie 30 oct** | Reglas |
| Corregir HQ-03 (corrección de salidas) | Desarrollo | **Vie 6 nov** | Reunión de avance |
| Horarios rotativos y programación (HQ-01 y HQ-05) | Desarrollo | **Vie 13 nov** | Hito 1 |
| Marcación según el horario del día (HQ-02) | Desarrollo | **Vie 13 nov** | Hito 1 |
| Horas trabajadas, tolerancia y estados (RP-01, RP-02, RP-03) | Desarrollo | **Vie 20 nov** | Después del Hito 1 |
| Filtro de calendario por sede (HQ-04) | Desarrollo | **Vie 27 nov** | Mes 2 |
| Reporte final completo (RP-04) y validación con datos reales | Desarrollo y QA | **18 ene al 12 feb** | Mes 4: reportes |
| Re-prueba con los casos CP-01 a CP-09 | QA | **Tras cada entrega** | — |

## **Criterios para dar la conformidad**

- Cero hallazgos **críticos** y **altos** abiertos.

- Los casos **CP-01 a CP-09** aprobados.

- El reporte final **cuadra** con la suma de los reportes diarios del periodo.

- Reglas de cálculo **firmadas** por RR. HH.

# **8. Conclusión**

El principal riesgo está en el **modelo de horarios**: mientras el sistema trabaje con un solo horario fijo, las marcaciones, las tardanzas y los reportes no serán confiables. La propuesta de **programar eligiendo agencia, horario y días** es una solución práctica y fácil de entender para el usuario.

Se recomienda a Desarrollo **estimar HQ-01, HQ-03 y HQ-05 de inmediato**, y a RR. HH. **definir las reglas de cálculo** antes del viernes 30 de octubre. Con eso QA podrá dar su conformidad después de la regresión previa al **Hito 1 del 13 de noviembre**.

# **Anexo. Glosario**

| **Término** | **Significado** |
| --- | --- |
| **Horario o turno** | Hora de entrada y salida que le corresponde a un colaborador. |
| **Horario rotativo** | Horarios que cambian por semana o por día en lugar de ser siempre el mismo. |
| **Día libre o descanso** | Día en que el horario no exige trabajar; no es una falta. |
| **Tardanza** | Minutos que pasan entre la hora de entrada del horario y la marcación real. |
| **Tolerancia** | Minutos de gracia en los que llegar no cuenta como tardanza. |
| **Horas extras** | Horas trabajadas después de la salida del horario. |
| **Marcación incompleta** | Jornada con entrada pero sin salida (o al revés). |
| **Geocerca** | Zona alrededor de la sede dentro de la cual se permite marcar. |
| **Regresión** | Repetir las pruebas para comprobar que lo corregido no dañó otras funciones. |


---

# Anexo A. Transcripción de las imágenes del informe

## A.1 Gráfico "Hallazgos por severidad (9 en total)"

| Severidad | Cantidad |
| --- | --- |
| Crítica | 1 |
| Alta | 4 |
| Media | 4 |

## A.2 Ejemplo ilustrativo · Ag. Trujillo · dos semanas (sección 3)

Horario A (ej. 08:00–15:00) · Horario B (otro horario) · "—" = en blanco: no labora.

| Colaborador | Lun 5 | Mar 6 | Mié 7 | Jue 8 | Vie 9 | Sáb 10 | Dom 11 | Lun 12 | Mar 13 | Mié 14 | Jue 15 | Vie 16 | Sáb 17 | Dom 18 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Juan Pérez | A | A | A | A | A | — | — | B | B | B | B | B | — | — |
| Fidel Alvarez | B | B | B | B | B | — | — | A | A | A | A | A | — | — |
| Juana Pereda | A | A | A | — | — | B | B | A | A | — | — | B | B | B |

## A.3 Los cuatro pasos de la programación propuesta (HQ-05)

1. Elegir la agencia.
2. Elegir el horario.
3. Marcar los días que asiste con ese horario.
4. Repetir con otro horario y dejar en blanco los días sin labor.
