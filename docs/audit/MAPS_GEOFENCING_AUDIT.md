# Mapas y geocercas (fase 1)

## Cálculo (verificado en código)

- Distancia: Haversine con R = 6 371 000 m en el servidor (`Attendance/Services/GeofenceService.php:89-110`)
  y la misma fórmula en la app (`mobile/lib/core/location/location_service.dart:244-249`).
- Dentro si `distancia <= radio`, o en zona de borde si `distancia <= radio + min(precisión, tolerancia)`
  (tolerancia por defecto 15 m). La app usa la misma regla (`models.dart:317`) solo como aviso previo.
- Precisión: si es peor que el máximo (por sede o empresa) se rechaza salvo que `distancia + precisión <= radio`.
- Política por sede: rechazar o marcar como sospechosa. **La decisión final es del servidor.**
- Antifraude: ubicación simulada (reportada por el teléfono), viaje imposible (150 km/h), coordenadas repetidas,
  deriva de reloj, selfie vieja. Limitación: un teléfono manipulado puede mentir; no hay detección infalible.

## Pendientes

| # | Hallazgo | Sev. | Estado |
|---|---|---|---|
| G1 | Sin precisión, se saltaba el control | Medio | **Corregido**: queda como sospechosa (`ACCURACY_MISSING`) |
| G2 | Hora de marcaciones sin conexión controlada por el teléfono | Medio | Pendiente (bloque 5) |
| G3 | Colaboradores "itinerantes" no pasan por la política de geocerca (ni rechazo ni marca) | Medio | A confirmar si es intencional |
| G4 | Clave de Google Maps visible en las URLs de teselas | Medio | Restringir por dominio en Google Cloud (propietario) |
| G5 | Pruebas independientes de distancias con casos límite (borde exacto, antimeridiano, coordenadas inválidas) | — | Pendiente |

## Proveedores

- Web: Leaflet con OpenStreetMap / Esri (gratis) o Google Map Tiles si la empresa elige Google.
- Geocodificación: Nominatim (1 petición/s, caché) o Google Geocoding (con clave, de pago por uso).
- App: no muestra mapas; solo usa GPS.
- No se cambia de proveedor sin comparar costos, licencias y funcionamiento.
