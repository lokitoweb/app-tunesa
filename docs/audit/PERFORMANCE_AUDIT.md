# Rendimiento (fase 1)

**Todavía no hay mediciones**: este documento lista sospechas encontradas en el código. Las mejoras se harán
con mediciones antes/después, indicando las condiciones (equipo, red, datos).

## Backend y panel

| # | Sospecha (evidencia en código) | Sev. |
|---|---|---|
| P1 | `TenantSetting::forTenant` hace 2 consultas por llamada y se llama en 66 sitios (incluido cada página del panel) | Medio |
| P2 | Permisos calculados por usuario: en listas, 2 consultas **por fila** (medido: /employees 54 → 65 consultas de 3 a 15 personas). **Corregido**: memoria por empresa y rol dentro del request (se vacía en cada request, job y cambio de permisos); /employees 46 → 45 | Medio |
| P3 | Excel y PDF generados dentro de la petición (reportes, planilla, certificados) | Medio |
| P4 | Correos (con PDF adjunto) enviados dentro de la petición; solo el push va a la cola | Medio |
| P5 | Listas sin paginar: tablero en vivo de la API, boletas del usuario, varios listados web de Finanzas | Medio |
| P6 | El reporte de horas recalcula todo el período en cada vista | Medio |
| P7 | Consulta de selfies del calendario sin límite de fechas | Medio |
| P8 | Geocodificación inversa (Nominatim) sincrónica con candado global de hasta 10 s | Medio |

## App móvil

Ver `MOBILE_AUDIT.md` (M3–M5, M7). Arranque liviano con configuración en caché.

## Mediciones (10/11/2026)

Consultas SQL por pantalla, SQLite de pruebas, 3 y 15 personas con dos semanas de jornadas (`tests/Feature/QueryBudgetTest.php`,
que falla si una pantalla suma más de 10 consultas al crecer el personal):

| Pantalla | 3 personas | 15 personas |
|---|---|---|
| Asistencia (mes) | 33 | 32 |
| Calendario de asistencia | 40 | 39 |
| Mapa en vivo | 29 | 26 |
| Planillas (mes) | 32 | 31 |
| Colaboradores | 46 | 45 (antes 54 → 65) |

## Mediciones pendientes

Arranque en frío y en caliente, inicio de sesión, dashboard, apertura de módulos, respuesta de la API, consultas
SQL por pantalla, memoria y CPU. Requieren servidor local con datos de volumen y un dispositivo o emulador
Android. Sin acceso a dispositivo físico ni a producción en esta sesión: se indicará en cada medición.
