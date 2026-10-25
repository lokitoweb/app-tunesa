# Informe de cierre de QA — Plataforma de RR. HH. Grupo Negreiros

**Fecha:** 24 de octubre de 2026
**Rama:** `master`
**Commits:** `c20e4c6` (Fase 1), `660f0a8` (Fase 2) y el commit de cierre que acompaña a este informe

---

## En pocas palabras

Recibimos el informe de QA con 9 hallazgos (1 crítico, 4 altos, 3 medios y una mejora) y los trabajamos en dos fases, uno por uno y con sus pruebas automáticas, antes de pasar al siguiente. Al final hicimos una ronda de estrés con concurrencia real sobre MySQL. Esa ronda valió la pena: encontró **dos problemas de concurrencia que ninguna prueba unitaria había visto**, y los dos quedaron corregidos en este cierre (detalle en la sección "Pruebas de estrés").

También restauramos el logotipo oficial de Grupo Negreiros en la app móvil.

Resumen del estado al cierre:

| Hallazgo | Severidad | Estado |
|---|---|---|
| DEF-01 Turnos y horarios | Crítica | Corregido |
| DEF-02 Boletas en la app | Alta | Corregido |
| DEF-03 Aprobación de vacaciones | Alta | Corregido (+ carrera corregida en el cierre) |
| DEF-04 Control de salida | Alta | Corregido |
| DEF-05 Recuperación de claves | Alta | Corregido |
| DEF-06 Catálogo de puestos | Media | Corregido |
| DEF-07 Intentos de marcación rechazados | Media | Completado |
| DEF-08 Usabilidad de Organización | Media | Mejorado |
| MEJ-01 Constancias de capacitación | Baja | Implementado |

---

## Fase 1 — Hallazgos críticos y altos

### DEF-01 · Turnos y horarios (Crítica)

**Qué pasaba.** Un turno tenía un solo horario para todos sus días. No había forma de decir "lunes a viernes 8:00–17:00 y sábado 8:00–13:00", que es justo el horario del personal administrativo. Resultado: un sábado se calculaba con el horario del lunes. Tardanzas, refrigerio y horas extra salían mal, y la salida de las 13:00 se veía como salida anticipada.

**Qué hicimos.**

1. Cada turno ahora puede tener **horario propio por día de la semana** (entrada, salida, refrigerio y horas del día). Si un día se deja en blanco, usa el horario general.
2. Agregamos un **perfil de personal** al turno: Administrativo, Ventas / tienda u Operaciones / campo. Cada perfil trae un "horario típico" que se carga con un clic y luego se ajusta.
3. El formulario muestra el **total de horas semanales** y avisa si pasa de 48 h.
4. Lo más importante: hicimos que **todos** los cálculos lean el turno a través de un único método, `Shift::forDate($fecha)`, que devuelve el horario de ese día. Así no queda ningún rincón usando el horario equivocado.

**Dónde está.**

- Modelo y lógica central: `app/Modules/HR/Models/Shift.php` → `forDate()`, `PROFILES`, `DAY_FIELDS`
- Migración: `app/Modules/HR/Database/Migrations/2026_10_30_000001_add_profile_and_day_schedules_to_shifts.php`
- Controlador web: `app/Modules/HR/Controllers/Web/ShiftWebController.php`
- Pantallas Vue: `resources/js/Modules/HR/Pages/Shifts/Index.vue` (listado, filtro por perfil y modal) y `Form.vue`
- Componente compartido: `resources/js/Modules/HR/Components/ShiftWeekSchedule.vue`
- Cálculos que ahora usan el horario del día:
  - `app/Modules/Attendance/Services/AttendanceService.php`: tardanza, ventana de horario, refrigerio, horas extra y salida esperada
  - `app/Modules/Attendance/Services/Reports/AttendanceReportService.php`: reporte de horas, extras diurnas y nocturnas, salida anticipada
  - `app/Modules/Attendance/Services/ManualAttendanceEdit.php`: corrección manual
  - `app/Modules/Attendance/Services/LiveBoardService.php`: tablero en vivo
  - `app/Modules/Attendance/Services/AttendanceCalendarService.php`: calendario
  - `app/Modules/Attendance/Services/ScheduleGate.php`: "¿puede marcar hoy?"
  - `app/Modules/Attendance/Console/SendEntryReminders.php`: recordatorios de entrada y salida
  - `app/helpers.php`, `AttendanceResource.php`, `AttendanceOutsideSchedule.php`, `Api/ShiftController.php`
- Pruebas: `tests/Feature/ShiftWeekScheduleTest.php`

> **Nota de lógica de turnos.** Los turnos rotativos (4×2, 6×1…) no usan horario por día: su ciclo no depende del día de la semana. `forDate()` devuelve una copia del turno que nunca se guarda, así que el turno original no cambia.

### DEF-02 · Boletas de pago en la app (Alta)

**Qué pasaba.** Cuando el PDF no estaba disponible, el servidor respondía con un mensaje claro. Pero la app pedía la descarga como bytes y no sabía leer ese mensaje, así que mostraba un genérico "No se encontró el recurso". Si llegaba un archivo dañado, el visor mostraba un error técnico. Y no había forma de descargar la boleta.

**Qué hicimos.**

- El manejo de errores de red ahora decodifica el JSON aunque llegue como bytes. Esto arregla **todas** las descargas de PDF de la app, no solo las boletas.
- Antes de abrir el PDF verificamos que de verdad sea un PDF (empieza con `%PDF`). Si no lo es, el mensaje dice: "La boleta no se pudo abrir porque el archivo está dañado o vacío. Pide a RR. HH. que la vuelva a publicar."
- Botón **Descargar o compartir**: abre el menú del teléfono para guardar en Archivos o Drive o enviar por WhatsApp.

**Dónde está.**

- `mobile/lib/core/network/api_exception.dart`
- `mobile/lib/features/payslips/data/payslip_repository.dart` → `isPdf()`
- `mobile/lib/features/payslips/presentation/payslip_viewer_screen.dart`
- `mobile/lib/core/widgets/pdf_bytes_view.dart` (mensaje de PDF dañado, compartido)
- Backend (sin cambios, ya respondía bien): `app/Modules/Payroll/Controllers/Api/PayslipController.php` → `download()`
- Pruebas: `mobile/test/core_test.dart`

### DEF-03 · Flujo de aprobación de vacaciones (Alta)

**Qué pasaba.** Las solicitudes de la app sí llegaban a la base de datos, pero no existía la figura de **jefe inmediato**: solo el supervisor de la sede. Si el jefe de alguien no supervisaba su sede, nunca veía la solicitud. Además, la regla de quién podía aprobar estaba copiada en tres lugares: el panel, el contador del menú y la app.

**Qué hicimos.**

1. Nuevo campo **Jefe de área** en Organización. El jefe de área es el jefe inmediato de su personal.
2. Una sola regla, `LeaveRequest::approvableBy($usuario)`, usada por el panel, el contador y la app:
   - RR. HH. ve todas las solicitudes de la empresa.
   - El jefe de área ve las de su área.
   - El supervisor ve las de sus sedes.
3. La bandeja de **Aprobaciones** y la app muestran "**Revisa:** Rosa Pérez (jefe de área) o RR. HH.", para que el colaborador sepa quién tiene su solicitud.
4. El aviso de "nueva solicitud" llega también al jefe de área. La respuesta (aprobada o rechazada) avisa al colaborador, venga del panel o de la app.

**Dónde está.**

- `app/Modules/HR/Models/LeaveRequest.php` → `scopeApprovableBy()` y `reviewersLabel()`
- `app/Modules/Core/Models/Department.php` → relación `head()`
- Migración: `app/Modules/Core/Database/Migrations/2026_10_30_000002_add_head_to_departments.php`
- Panel: `app/Modules/HR/Controllers/Web/ApprovalWebController.php` y `resources/views/approvals/index.blade.php`
- API: `app/Modules/HR/Controllers/Api/LeaveRequestController.php`
- Contador del menú: `app/Modules/Core/Support/PanelNavigation.php`
- Avisos: `app/Modules/Core/Services/NotificationDispatcher.php` → `leaveApprovers()`
- App: `mobile/lib/features/requests/domain/models.dart` y `presentation/requests_screen.dart`
- Pruebas: `tests/Feature/LeaveApprovalFlowTest.php`

> **Nota.** La aprobación es de un solo paso: aprueba el jefe inmediato **o** RR. HH. Si más adelante quieren doble aprobación (primero el jefe y después RR. HH.), se agrega sobre la misma regla.
>
> **Ojo técnico.** En `users` hay una columna de texto `department` que tapa la relación `department()`. Por eso `reviewersLabel()` lee la relación de forma explícita.

### DEF-04 · Control de marcación de salida (Alta)

**Qué encontramos.** La salida ya tenía las mismas reglas que la entrada:

- geocerca por GPS, en el teléfono y en el servidor;
- detección de ubicación simulada;
- precisión mínima del GPS;
- selfie obligatoria.

Faltaban dos cosas: **validar el horario mínimo** y **alertar de una jornada incompleta**.

**Qué hicimos.**

- El servidor devuelve la **hora de salida esperada** del turno de ese día (`expected_clock_out` en `/api/attendance/today`). La calcula una sola función, que también usa el recordatorio de salida.
- Antes de la selfie, la app muestra una tarjeta roja "**Jornada incompleta**" y pide confirmar con un diálogo en dos casos:
  - si faltan minutos para la hora de salida ("te faltan 1 h 20 min");
  - si la entrada fue hace menos de 15 minutos (probable toque accidental).

  "No, seguir trabajando" vuelve a Inicio sin marcar.
- La comparación usa la hora del servidor, no la del teléfono, así que cambiar la hora del celular no engaña al aviso.

**Dónde está.**

- `app/Modules/Attendance/Services/AttendanceService.php` → `expectedClockOut()`
- `app/Modules/Attendance/Controllers/Api/AttendanceController.php` → `today()`
- `mobile/lib/features/attendance/domain/models.dart` → `TodayStatus.clockOutWarning()`
- `mobile/lib/features/attendance/presentation/clock_screen.dart` (tarjeta y diálogo)
- Pruebas: `tests/Feature/ClockOutControlTest.php` y `mobile/test/punch_board_test.dart`

> **Decisión consciente.** El servidor **no bloquea** la salida anticipada: una emergencia o un permiso tiene que poder registrarse, y bloquearla haría perder marcaciones hechas sin conexión. La app avisa y pide confirmar. La jornada queda como "medio día" y el supervisor recibe el aviso de marcación fuera de horario.

### DEF-05 · Recuperación y restablecimiento de claves (Alta)

**Qué encontramos.** El cambio obligatorio de la contraseña temporal en el primer ingreso ya funcionaba, en la app y en la API. Restablecer la clave desde el panel también existía, pero escondido dentro de la ficha del colaborador. Y en la app, "¿Olvidaste tu contraseña?" solo decía "pide a RR. HH.".

**Qué hicimos.**

- **Panel:** botón con llave (**Restablecer clave**) en cada fila de la lista de Colaboradores. Genera una contraseña temporal que se muestra una sola vez y cierra las sesiones de la app.
- **App:** "¿Olvidaste tu contraseña?" ahora pide el correo y **avisa a RR. HH.** con una notificación que lleva directo a la ficha del colaborador.
  - La respuesta es siempre la misma, exista o no el correo, para no revelar qué cuentas están registradas.
  - Se manda un aviso por cuenta y por hora.
- La app nunca cambia una clave sin RR. HH.: se mantiene la política de la empresa y de las tiendas de apps.

**Dónde está.**

- API: `app/Modules/Core/Controllers/Api/AuthController.php` → `requestPasswordHelp()` (`POST /api/v1/auth/password-help`)
- Aviso: `app/Modules/Core/Notifications/PasswordHelpRequested.php`
- Lista web: `resources/views/employees/partials/table.blade.php`
- App: `mobile/lib/features/auth/presentation/login_screen.dart` (`_ForgotPasswordDialog`) y `data/auth_repository.dart`
- Pruebas: `tests/Feature/PasswordHelpTest.php` y `mobile/test/widget_test.dart`

---

## Fase 2 — Hallazgos medios y mejora

### DEF-06 · Catálogo dinámico de puestos

Junto al selector de cargo, tanto en el alta (modal) como en la ficha del colaborador, ahora hay **"+ Nuevo cargo"**:

- Se escribe el nombre, se crea en el catálogo con el área elegida y queda seleccionado, sin salir del formulario.
- Si ya existe un cargo con ese nombre (aunque cambien mayúsculas o espacios), se reutiliza y no se duplica.
- Si alguien tenía su cargo en texto libre de antes, se conserva hasta que elijan uno del catálogo.

- `app/Modules/HR/Controllers/Web/EmployeeController.php` → `storePosition()` (`POST /employees/positions`)
- `resources/views/employees/_new-position.blade.php` (componente reutilizable), `_form.blade.php` y `partials/modal-create.blade.php`
- Pruebas: `tests/Feature/InlinePositionCatalogTest.php`

### DEF-07 · Registro de intentos de marcación rechazados

La base ya existía y estaba completa: tabla `attendance_punch_attempts`, pantalla en **Monitoreo › Intentos rechazados** con colaborador, hora exacta, coordenadas, distancia, radio permitido, precisión, filtros por fecha, sede, motivo y colaborador, y un **mapa** con la geocerca de cada sede y el punto de cada intento.

Completamos lo que pedía QA:

- **"Excedió por X m"**: los metros que la persona estaba fuera del radio.
- Filtro por **tipo de marcación** (entrada, salida o descansos).

- `app/Modules/Attendance/Controllers/Web/PunchAttemptWebController.php`
- `resources/views/attendance/attempts.blade.php`
- Pruebas: `tests/Feature/PunchAttemptLogTest.php`

> **Nota sobre mapas y geocercas.** El círculo de cada sede usa su radio más un margen de borde (`geofence_tolerance_meters`, 15 m por defecto) que compensa el error del GPS en la orilla. Un intento se rechaza si la persona está fuera del radio considerando la precisión que reporta el teléfono. El mapa dibuja ambas cosas para que RR. HH. vea si fue un error de borde o si de verdad estaba lejos.

### DEF-08 · Usabilidad del módulo Organización

- Textos en lenguaje sencillo: "Sedes y personal", "Zonas, áreas y cargos", "Personal activo", "Plazas previstas".
- Una **guía visible de 4 pasos**: Zona → Sede → Área → Cargo. Cada paso dice quién responde (el supervisor de la sede, el jefe de área) y tiene una ayuda al pasar el mouse.
- Aviso de **áreas sin jefe**, con un botón "Asignar jefe".
- En la lista de áreas se ve "Jefe: Fulano". En la ficha de la sede, una línea explica qué hace el supervisor.

- `resources/js/Modules/Core/Pages/Organization/Index.vue`
- `app/Modules/Core/Controllers/Web/OrganizationWebController.php`

### MEJ-01 · Constancias de capacitación

La constancia en PDF ahora lleva:

- el **logo de la empresa**: el que se subió en "Marca y app" o, si no hay, el institucional;
- la **firma escaneada del gerente**, con su nombre y cargo.

La firma se toma de **Finanzas › Firmantes**: el apoderado activo con firma cuyo cargo dice "gerente" o, si no hay, el primero activo con firma. Sin firmantes, sale una línea con el nombre de la empresa.

- `app/Modules/Training/Services/TrainingCertificate.php`
- `resources/views/training/certificate.blade.php`
- Pruebas: `tests/Feature/TrainingCertificateBrandingTest.php`

---

## Cierre — Logotipo oficial en la app móvil

El logo que aparecía ("Tunesa Expres") no estaba en el código de la app: era el logo que el servidor de producción entrega como marca de la empresa, y la app lo descargaba.

Ahora el logo oficial (`LOGO GRUPO NEGREIROS.png`) va **incluido dentro de la app**. Se ve igual en el arranque, el login, el encabezado de Inicio y la pantalla de actualización, con o sin sesión y aunque no haya internet.

- `Image.asset` con `BoxFit.contain`: mantiene su proporción (1657×517) en cualquier ancho de pantalla, Android o iOS.
- Probado en un teléfono angosto (320 px) sin desbordes.

Archivos:

- Imagen: `mobile/assets/branding/logo-grupo-negreiros.png` (declarada en `mobile/pubspec.yaml`)
- Widget: `mobile/lib/core/widgets/brand_logo.dart`
- Login: `mobile/lib/features/auth/presentation/login_screen.dart`
- Encabezado de Inicio: `mobile/lib/features/home/presentation/home_screen.dart`
- Prueba: `mobile/test/widget_test.dart` → "el login muestra el logo oficial de Grupo Negreiros sin deformarlo"

---

## Pruebas de estrés

### Cómo las hicimos

Las pruebas unitarias no detectan problemas de concurrencia: en ellas, cada petición pasa sola. Por eso montamos un entorno de carga real:

- **Base de datos:** MySQL (MariaDB 10.4 de XAMPP) en una base desechable `tunesa_load`, sembrada con los datos demo y el volumen de `tests/load/seed-load.php`:
  - 2 400 cuotas, 600 costos fijos y 1 000 gastos de caja chica;
  - 40 colaboradores con token;
  - con `tests/load/seed-qa-closure.php`, un turno con sábado corto y una boleta PDF para cada uno.
- **Servidores:** 4 procesos PHP con OPcache, en paralelo (puertos 8101–8104).
- **Herramienta:** k6, con tres escenarios:
  - `tests/load/clock-in-race.js`: 80 marcaciones de entrada simultáneas, 2 por persona;
  - `tests/load/qa-closure.js` → `leave_race`: 80 solicitudes de vacaciones simultáneas, 2 por persona con las mismas fechas;
  - `tests/load/qa-closure.js` → `payslips`: 40 usuarios listando y descargando boletas durante un minuto.

### Lo que encontramos (y arreglamos)

**1. Deadlock en la caché del calendario (marcaciones).**
En la primera corrida, 2 de 80 marcaciones respondieron error 500: `Deadlock found when trying to get lock`.

- **Causa:** cada marcación incrementaba la "versión" del calendario en la tabla `cache`, dentro de la transacción de la marcación. Con varias marcaciones a la vez se bloqueaban entre sí.
- **Además, era trabajo inútil:** el calendario solo guarda en caché **meses ya cerrados**, así que tocar la caché por una marcación del mes en curso no servía de nada.
- **Corrección** (en `AttendanceCalendarService::bump()`):
  - los cambios del mes en curso no tocan la caché;
  - los de meses cerrados se aplican **después del commit**, en transacciones cortas.
- Prueba nueva: `tests/Feature/AttendanceCalendarTest.php` → "punches of the current month do not write the cache table".

**2. Vacaciones duplicadas con envíos simultáneos.**
Cuando el mismo colaborador enviaba la misma solicitud dos veces a la vez (doble toque o reintento de red), a veces quedaban **dos solicitudes con las mismas fechas** (41 para 40 personas).

- **Causa:** "revisar si se cruza" e "insertar" no eran atómicos.
- **Corrección** (en `LeaveRequestController::store()`): igual que la marcación, se bloquea la fila del usuario dentro de una transacción, así que dos envíos de la misma persona se atienden uno detrás del otro.

**3. Defecto en la propia prueba de carrera.**
El script de carrera que ya existía mandaba los dos intentos de una misma persona **al mismo servidor**. Como cada servidor atiende una petición a la vez, la carrera nunca era real. Lo corregimos en `clock-in-race.js` y en `qa-closure.js`: el segundo intento va a otro servidor. Gracias a eso apareció el problema 2.

### Resultados finales (con las correcciones)

| Escenario | Resultado |
|---|---|
| 80 marcaciones simultáneas (2 por persona) | **0 errores 5xx**. Exactamente 40 aceptadas y 40 rechazadas como duplicadas (409). En la base: **40 jornadas para 40 personas**. |
| 80 solicitudes de vacaciones simultáneas (2 por persona) | **0 errores 5xx**. En 5 corridas seguidas: 40 creadas y 40 rechazadas por fechas cruzadas (409). En la base: **40 solicitudes para 40 personas**, siempre. |
| Boletas: 40 usuarios durante 1 minuto (580 descargas) | **100 % de respuestas correctas**, todas PDF válido. p95 = **0,82 s**, mediana 0,20 s. |
| Memoria de los procesos PHP | Estable: de 39–43 MB antes a 39–43 MB después de ~850 peticiones por proceso (+0,1 a +0,3 MB). **Sin fugas.** |

**Cómo leer estos números:**

- **Latencia de las marcaciones.** La latencia alta de la carrera de marcaciones (p95 ≈ 10 s) es de **cola**, no del sistema: 80 peticiones llegan al mismo tiempo a solo 4 procesos de un hilo, y cada marcación con selfie tarda aproximadamente 1 s en procesarse (foto, GPS, geocerca). En producción, PHP-FPM tiene más procesos y la cola se reparte.
- **Límite de peticiones.** Por usuario sigue en 60 por minuto. En una corrida de calentamiento, los usuarios simulados que pedían más rápido que un teléfono real recibieron 429. Es el límite haciendo su trabajo, no un error.
- **Motor de base de datos.** Probamos con MariaDB 10.4. Producción usa MySQL 8; el bloqueo de filas (`SELECT … FOR UPDATE`) se comporta igual en ambos.

### Para repetirlas

```bash
# 1) Copia de la base (el nombre DEBE contener "load"; los scripts se niegan a correr si no)
DB_CONNECTION=mysql DB_DATABASE=tunesa_load php artisan migrate:fresh --seed --force
DB_CONNECTION=mysql DB_DATABASE=tunesa_load php tests/load/seed-load.php empresa-demo load-users.json
DB_CONNECTION=mysql DB_DATABASE=tunesa_load php tests/load/seed-qa-closure.php empresa-demo load-users.json

# 2) Servidores (desde public/, con OPcache), uno por puerto: 8101..8104
php -d zend_extension=opcache -d opcache.enable_cli=1 -S 127.0.0.1:8101 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php

# 3) Escenarios
k6 run -e USERS=load-users.json -e SELFIE=selfie.jpg -e BASES=http://127.0.0.1:8101,http://127.0.0.1:8102,http://127.0.0.1:8103,http://127.0.0.1:8104 tests/load/clock-in-race.js
k6 run -e USERS=load-users.json -e BASES=http://127.0.0.1:8101,http://127.0.0.1:8102,http://127.0.0.1:8103,http://127.0.0.1:8104 tests/load/qa-closure.js
```

---

## Validación de despliegue

- **Pruebas automáticas locales:**
  - Backend: **589** pruebas en verde.
  - App: **226** pruebas en verde y `flutter analyze` sin observaciones.
  - Panel: Vue compila sin errores.
- **GitHub Actions:**
  - Fase 2: "Backend: pruebas" (SQLite y MySQL 8) y "App ↔ API: pruebas de punta a punta" (contrato de la API y recorrido en emuladores Android 10 y 15) en verde.
  - Fase 1: el APK se generó en verde. Una prueba nueva de turnos falló solo en MySQL porque MySQL ordena las llaves de los JSON de otra forma; se corrigió la prueba, no los datos.
- **Al desplegar en producción:**
  1. `php artisan migrate`: dos migraciones nuevas (horario por día de los turnos y jefe de área).
  2. Asignar el **jefe de cada área** en Organización (la pantalla avisa cuáles faltan).
  3. Cargar la **firma del gerente** en Finanzas › Firmantes para las constancias.
  4. Instalar el **APK nuevo** (logo oficial, boletas, salida, "olvidé mi contraseña").

— Equipo de desarrollo
