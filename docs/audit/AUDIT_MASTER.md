# Auditoría de producción — Marcaciones (documento maestro)

Punto de entrada para continuar la auditoría en cualquier sesión. Cada bloque enlaza a su documento.
Nada se marca como hecho si no está implementado, probado y registrado en `CHANGELOG_AUDIT.md`.

## Reglas acordadas con el propietario (09/11/2026)

- **Contraseña inicial = DNI** del trabajador, con **cambio obligatorio** en el primer acceso. Es política de
  incorporación y **no se cambia**. Sí se asegura que no se pueda saltar el cambio y que el DNI deje de servir.
- **Recuperación de acceso** (olvidé mi contraseña) es distinta de la contraseña inicial y debe ser segura:
  cualquier cambio se presenta como diseño y se aprueba antes de implementarlo (ver `SECURITY_AUDIT.md`).
- **Secretos del historial de git**: se rotan antes de cerrar la puesta en producción. Por ahora no se cambian
  credenciales productivas ni se reescribe el historial. No se muestran valores en ningún informe.
- **Autorizado**: inspección, cambios de código controlados, pruebas, documentación, compilaciones de prueba.
- **10/11/2026**: el sistema solo tiene datos de prueba. Autorizado activar `hours_v2`, corregir cálculos y reglas
  aprobadas, optimizar CI, preparar el repositorio privado y rotar en el entorno de pruebas (con respaldo). Siguen
  prohibidos: borrar sin respaldo, migraciones destructivas, exponer secretos o datos personales, publicar en tiendas.
- **No autorizado sin permiso expreso**: tocar datos de producción, ejecutar migraciones en producción, borrar o
  truncar, cambiar credenciales productivas, reescribir git, cambiar reglas de negocio, desplegar, operaciones
  irreversibles. **Ojo**: cada push a `master` despliega y ejecuta migraciones en producción
  (`scripts/deploy.sh`), así que un commit con migraciones solo se sube con autorización.

## Estado por bloque

| Bloque | Documento | Estado |
|---|---|---|
| 0. Secretos y accesos | `SECURITY_AUDIT.md` §1–3 | Inventario e informe hechos; `app:reencrypt` implementado y probado (rotación sin pérdida). Rotación en producción y repo privado: necesitan acceso al servidor |
| 1. Seguridad integral | `SECURITY_AUDIT.md` §4 | En curso: primeras correcciones hechas (ver changelog); recuperación de acceso con enlace implementada |
| 2. Integridad de cálculos | `DATABASE_AUDIT.md` §B | Implementado y probado en local: fuente única (jornada guardada), `hours_v2` para todas, faltas/tiempos justificados con/sin goce. Pendiente de publicar (incluye migraciones) |
| 3. Base de datos | `DATABASE_AUDIT.md` §A | Pendiente (migraciones se presentan antes de subir) |
| 4. Rendimiento | `PERFORMANCE_AUDIT.md` | Pendiente (sin mediciones todavía) |
| 5. API y sincronización | `API_AUDIT.md` | Pendiente |
| 6. Android / iOS / tiendas | `MOBILE_AUDIT.md`, `STORE_COMPLIANCE.md` | Pendiente. Decisiones: iPad, proveedor de reporte de fallos, cuentas de Apple/Google |
| 7. Calidad de arquitectura | `ARCHITECTURE.md` | Pendiente. Decisión: Nubefact |
| Mapas y geocercas | `MAPS_GEOFENCING_AUDIT.md` | Revisado (fase 1); correcciones en bloques 1–2 |

## Decisiones que necesita el propietario

1. ~~Aprobar el diseño de recuperación de acceso~~ — aprobado e implementado (09/11/2026).
2. ~~Faltas justificadas con / sin goce~~ — decidido el 10/11/2026 (RR. HH. elige al aprobar, sin valor por defecto;
   tardanzas justificadas en columna aparte) e implementado (`DATABASE_AUDIT.md` §B.2).
3. **Repositorio privado y rotación en producción**: autorizado el 10/11/2026. Falta la deploy key en el servidor
   (paso 6 de `scripts/server-setup.sh`), que requiere acceso SSH del propietario (`SECURITY_AUDIT.md` §1.6).
4. **iPad**: mantener o retirar el soporte declarado (`STORE_COMPLIANCE.md`).
5. **Reporte de fallos** (Crashlytics / Sentry / ninguno) — sin instalar nada hasta aprobarlo.
6. **Nubefact**: implementar, dejar como configuración o retirar.
7. **Cuentas y secretos de CI**: GitHub Actions no tiene ningún secreto ni variable cargados (firma Android,
   Firebase, Apple). Sin ellos, los APK de CI se firman con la llave de depuración y salen sin push.

## Orden de intervención y dependencias

1. Bloque 0 y 1 (seguridad) — no dependen de otros; la rotación de secretos cierra al final, antes del veredicto.
2. Bloque 2 (cálculos) — antes del 3: las restricciones de base de datos dependen de la regla final.
3. Bloque 3 (base de datos) — migraciones revisadas una a una, con respaldo y reversión.
4. Bloques 4 y 5 (rendimiento, sincronización) — con mediciones antes/después.
5. Bloque 6 (tiendas) — necesita las cuentas y secretos del propietario.
6. Bloque 7 (limpieza) — al final, cuando las pruebas cubran lo que se toca.

## Documentos

`ARCHITECTURE.md` · `SECURITY_AUDIT.md` · `PERFORMANCE_AUDIT.md` · `DATABASE_AUDIT.md` · `MOBILE_AUDIT.md` ·
`API_AUDIT.md` · `MAPS_GEOFENCING_AUDIT.md` · `STORE_COMPLIANCE.md` · `TEST_RESULTS.md` ·
`PRODUCTION_READINESS.md` · `CHANGELOG_AUDIT.md`
