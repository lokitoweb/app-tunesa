# Preparación para producción

## Veredicto actual: NO APTO PARA PRODUCCIÓN (con observaciones)

El sistema ya opera en producción y su base es sólida (aislamiento entre empresas, autenticación, subidas,
cola sin conexión cifrada). No se puede declarar apto mientras sigan abiertos:

1. Rotación de secretos del historial público (o confirmación de que producción no los usa) — bloque 0.
2. Diferencias de cálculo entre jornada, reporte de horas y planilla (C1–C6) — bloque 2.
3. Pérdida silenciosa de marcaciones sin conexión tardías (C8) — bloque 5.
4. Requisitos obligatorios de tiendas: firma de producción Android, `PrivacyInfo.xcprivacy` y distribución iOS — bloque 6.

## Estado por área

| Área | Estado |
|---|---|
| Arquitectura | Sólida; duplicaciones a limpiar (bloque 7) |
| Seguridad | En corrección (bloques 0–1) |
| Cálculos de asistencia | Diferencias entre módulos (bloque 2) |
| Base de datos | Sin retención en tablas grandes; restricciones a revisar (bloque 3) |
| Rendimiento | Sin medir (bloque 4) |
| Sincronización | Idempotente con uuid; casos de pérdida a cerrar (bloque 5) |
| Android | Técnicamente listo salvo firma de producción en CI |
| iOS | No publicable todavía |
| Panel web | Funcional; CSP pendiente |
