# App móvil Flutter (fase 1)

## Estado verificado

- Arquitectura por funcionalidad (`lib/features/<feature>/{data,domain,presentation}`), 20 funcionalidades, ~21k líneas.
- Configuración y marca desde el servidor (`GET /v1/app/config`), con caché; sin caché el arranque espera hasta 4 s solo la primera vez.
- Token en almacenamiento seguro; cola sin conexión en SQLCipher con llave en Keychain/Keystore; fotos cifradas (AES-GCM).
- Sincronización sin conexión: lotes de 5, reintentos con espera de 30 s a 30 min, descarte por antigüedad máxima.
- GPS: lecturas frescas (nunca la última conocida), hasta 6 muestras en 20 s, precisión máxima configurable.
- Selfie comprimida (máx. 1280 px, calidad 80) antes de enviar.
- Estados: skeleton en ~37 archivos, vistas de error, vacío y sin conexión.
- Pruebas: 33 archivos (~268 casos), contrato contra la API y emuladores Android 29 y 35.

## Hallazgos (pendientes salvo que se indique)

| # | Hallazgo | Sev. |
|---|---|---|
| M1 | Sin reporte de fallos en producción (ni Crashlytics ni Sentry) | Medio — decisión |
| M2 | Sin Play Integrity / App Attest ni pinning; señales de integridad solo informativas | Medio — decisión |
| M3 | Listas sin construcción perezosa: historial, beneficios, documentos, cumpleaños | Medio |
| M4 | Imágenes de red sin caché en disco; vista previa de selfie sin `cacheWidth`; detector de rostro creado en cada uso | Bajo |
| M5 | Lectura de `/proc/self/maps` en el hilo principal de Android | Bajo |
| M6 | Un solo `applicationId` para todas las marcas (no se pueden publicar marcas como apps distintas) | Info — decisión de negocio |
| M7 | Pantallas con indicador genérico en vez de skeleton: splash, hoja de privacidad, regalos | Bajo |

Las mediciones de rendimiento (arranque, memoria, CPU) requieren un dispositivo o emulador: ver `PERFORMANCE_AUDIT.md`.
