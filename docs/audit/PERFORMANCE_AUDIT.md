# Rendimiento (fase 1)

**Todavía no hay mediciones**: este documento lista sospechas encontradas en el código. Las mejoras se harán
con mediciones antes/después, indicando las condiciones (equipo, red, datos).

## Backend y panel

| # | Sospecha (evidencia en código) | Sev. |
|---|---|---|
| P1 | `TenantSetting::forTenant` hace 2 consultas por llamada y se llama en 66 sitios (incluido cada página del panel) | Medio |
| P2 | Permisos del usuario sin caché entre peticiones (2-3 consultas por petición autenticada) | Medio |
| P3 | Excel y PDF generados dentro de la petición (reportes, planilla, certificados) | Medio |
| P4 | Correos (con PDF adjunto) enviados dentro de la petición; solo el push va a la cola | Medio |
| P5 | Listas sin paginar: tablero en vivo de la API, boletas del usuario, varios listados web de Finanzas | Medio |
| P6 | El reporte de horas recalcula todo el período en cada vista | Medio |
| P7 | Consulta de selfies del calendario sin límite de fechas | Medio |
| P8 | Geocodificación inversa (Nominatim) sincrónica con candado global de hasta 10 s | Medio |

## App móvil

Ver `MOBILE_AUDIT.md` (M3–M5, M7). Arranque liviano con configuración en caché.

## Mediciones pendientes

Arranque en frío y en caliente, inicio de sesión, dashboard, apertura de módulos, respuesta de la API, consultas
SQL por pantalla, memoria y CPU. Requieren servidor local con datos de volumen y un dispositivo o emulador
Android. Sin acceso a dispositivo físico ni a producción en esta sesión: se indicará en cada medición.
