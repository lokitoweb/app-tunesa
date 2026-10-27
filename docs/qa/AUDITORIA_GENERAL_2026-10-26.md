# Auditoría general · 26/10/2026

Cinco revisiones en paralelo (seguridad y rutas, base de datos y rendimiento, duplicados y
organización de módulos, diseño, contrato API ↔ app Android/iOS). Cada hallazgo se verificó en el
código antes de corregirlo; los que resultaron falsos positivos se descartaron con su prueba.

## Corregido en este cambio

| Área | Qué | Prueba |
|---|---|---|
| Despliegue | `scripts/deploy.sh` corre `RolesAndPermissionsSeeder` tras migrar (el instalador ya lo hacía). Ya no hace falta correrlo a mano ni `optimize:clear`. Verificado en base temporal: el seeder no quita ningún permiso y una segunda corrida es idéntica. | script de verificación + suite |
| Colas | Geocodificación en su propia cola `geocoding` por defecto, detrás de los push. El scheduler la procesa con el worker del scheduler y también con un worker permanente de systemd, sin tocar el servicio. `GEOCODING_QUEUE=` (vacío) vuelve a una sola cola. | `GeocodingQueueTest`, `schedule:list` en los dos modos |
| Tesorería | Dos pagos simultáneos (doble clic o dos tesoreros) podían pagar dos veces una cuota: ahora se revisan saldo, estado y autorización con la cuota bloqueada. | `TreasuryFlowTest::test_two_simultaneous_payments…` |
| Seguridad | Cambiar la contraseña cierra las sesiones del panel en otros navegadores; restablecer el acceso (RR. HH.) cierra todas. | `EndWebSessionsTest` |
| Seguridad | Webhooks: el filtro SSRF ahora revisa IPv6, IPv6 que envuelve IPv4 (`::ffff:127.0.0.1`, hallado por la prueba), y fija la IP validada en la conexión (sin DNS rebinding). En producción, un dominio que no resuelve se rechaza. | `WebhookSsrfTest` |
| Seguridad | Calendario por persona: un supervisor ya no averigua nombre ni rol de alguien fuera de su equipo; la búsqueda por nombre ya no carga toda la tabla de usuarios. | `AttendanceCalendarTest` |
| Rendimiento | PDF de asistencia: tope de 92 días (igual que la API) y fechas validadas. | `AttendancePdfRangeTest` |
| Rendimiento | `GET /api/supervisor/team` sin N+1 (~3 consultas por integrante); la app recibe los mismos campos de perfil. | `DashboardAndSupervisorApiTest` (consultas constantes) |
| Rendimiento | Recordatorios de entrada (cada minuto) y faltas: la persona del día programado viene con la fila, sin una consulta por persona. | suite de asistencia |
| Coherencia | El tablero de la app contaba solo `employee`; ahora usa los mismos roles que el panel (`User::CLOCKING_ROLES`). | suite |
| Diseño | Diálogo de confirmación: sin variante es azul (antes rojo con tacho); el botón repite el ícono del aviso; botón amarillo legible; colores del tema en modo oscuro. "Autorizar pago" y "Regenerar secreto" con su variante correcta. Verificado en el navegador (eliminar = rojo/tacho, guardar = azul/disquete). | navegador |
| Diseño | Horarios: texto e aviso del modal con colores del tema (modo oscuro). | build |
| App | La tarjeta "Tu horario de hoy" usa el día de la empresa (no el del teléfono) cerca de medianoche o en otra zona horaria. Un error de negocio al marcar (p. ej. ya no está programado) refresca Inicio. | `shift_card_test.dart` |
| Limpieza | Borrados sin uso: `AntiGravityLogin.vue`, `notifications-bell.blade.php`, `ethics/show.blade.php` y una rama de Ethics que pedía una vista inexistente (daba 500). | grep sin referencias |
| Docs | Guía de producción: párrafo roto corregido, cola y seeder documentados. `.env.example` advierte que es de desarrollo. | — |

Suite: 671 pruebas de PHP y 247 de Flutter en verde; `flutter analyze` sin problemas.
Carga (k6, 40 colaboradores abriendo la app, copia de la base): 235 peticiones, 0 errores, 0 respuestas 500.
La latencia alta de esa prueba es del servidor de desarrollo de Windows (una petición a la vez + SQLite), no del código: con el código anterior y las mismas condiciones fue peor (191 peticiones).

## Descartados (falsos positivos, verificados)

- **XSS en "Cruce con planilla"**: todos los textos de cada fila pasan por `Panel.esc` (`payroll/index.blade.php:422`). Probado en el navegador con un nombre de banco `<img src=x onerror=…>`: se muestra como texto y no se ejecuta.
- **Cerrar sesión por GET**: un GET con sesión activa solo redirige al inicio (`AuthController::logout`); se cierra solo con POST y CSRF. El aviso de sesión vencida usa ese GET a propósito.

## Pendiente (priorizado)

### Requiere datos o decisión del negocio
1. **Push en iOS**: faltan `FIREBASE_IOS_APP_ID` / `FIREBASE_IOS_BUNDLE_ID` (o `GoogleService-Info.plist`) y la llave APNs en Firebase. Sin eso, el push en iPhone queda apagado. Lo tiene que cargar quien administra Firebase.
2. **Clave de Firebase Android** (`google-services.json`): restringirla en Google Cloud al paquete y SHA-1 de la app.
3. **Boletas**: la pantalla filtra solo `employee` y la exportación de planilla incluye `supervisor`. Decidir qué roles reciben boleta antes de unificar.
4. **Horas en la API**: el historial usa horas a pagar y las estadísticas horas trabajadas. Decidir cuál es la métrica oficial.

### Mejoras técnicas (sin cambio para el usuario, bajo riesgo)
5. Correlativos de caja chica y lote de costos fijos con `DocumentSequence` (hoy `MAX()+1` puede chocar con dos usuarios a la vez; caja chica lo frena el índice único con un error, costos fijos no tiene índice).
6. Alta de colaborador: reglas de validación distintas entre app y panel (FormRequest compartido).
7. Subida de avatar implementada tres veces con reglas distintas (un solo método).
8. Lista de roles copiada en 4 vistas con etiquetas distintas (usar `Labels`).
9. Formato de dinero repetido en 10 pantallas de Finanzas (un `money()` común).
10. Filtros por mes con `whereYear/whereMonth` (no usan índice): cambiar a rango de fechas.
11. `/api/schedule` no indica los días autorizados por RR. HH. fuera de horario (la marcación sí los respeta).

### Diseño (pantalla por pantalla)
12. Botón "Limpiar" en los filtros de Finanzas; una sola clase de filtro en `panel.css`.
13. Fechas con el selector moderno (`ModernDatePicker`) en lugar de `input type=date` nativos.
14. Avisos locales de Feriados y Programación → el aviso global del panel.
15. Pantallas Vue que el menú abre con recarga completa (`spa` en `PanelNavigation`).
