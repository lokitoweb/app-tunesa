# Publicar la app en Google Play

Lista para subir **Tunesa** (`com.tunesa.mobile`) sin rechazos. Lo técnico ya está en el
código y el CI lo revisa en cada cambio; abajo queda lo que se hace a mano en
[Play Console](https://play.google.com/console).

## Lo que ya cumple la app (y vigila el CI)

| Requisito de Google Play | Cómo se cumple | Dónde |
|---|---|---|
| Target API 36 (Android 16) desde el 31/08/2026 | Flutter 3.47 compila con `targetSdk` 36 | `scripts/check_play_requirements.py` falla si baja |
| Android App Bundle (.aab) | El CI genera `tunesa-<versión>-prod-release.aab` junto al APK | `.github/workflows/mobile-apk.yml` |
| Páginas de memoria de 16 KB (target 35+) | Cada `.so` de 64 bits revisado (LOAD align ≥ 16 KB) | `check_play_requirements.py` |
| Solo permisos justificados | Internet, red, notificaciones, ubicación precisa (solo en uso), cámara. Se quitaron micrófono y almacenamiento que agregaba el plugin de cámara | `AndroidManifest.xml`, lista en `check_play_requirements.py` |
| Sin ubicación en segundo plano | No se declara `ACCESS_BACKGROUND_LOCATION` | idem |
| Aviso destacado antes de pedir ubicación y cámara | Diálogo "Antes de marcar" en la primera marcación | `punch_disclosure.dart` |
| Política de privacidad pública y en la app | `https://<tu-dominio>/privacidad` · Mi perfil › Privacidad y datos | `LegalController`, `privacy_screen.dart` |
| Eliminar la cuenta desde la app y desde la web | Mi perfil › Privacidad y datos › Eliminar mi cuenta · `https://<tu-dominio>/eliminar-cuenta`. RRHH recibe el aviso | `AccountDeletion` |
| Sin copias de seguridad de datos sensibles, solo HTTPS en release | `allowBackup=false`, `usesCleartextTraffic=false` | `AndroidManifest.xml` |

`<tu-dominio>` es el `APP_URL` del backend en producción (debe ser `https://`).

## Pasos en Play Console (una sola vez)

1. **Crear la app**: nombre *Tunesa*, idioma español (Latinoamérica), App, Gratis.
2. **Firma (Play App Signing)**: acepta que Google guarde la llave de la app. Sube el `.aab`
   firmado con tu llave de subida (`ANDROID_KEYSTORE_BASE64` en los secrets del repo).
   Después copia el **SHA-256 del certificado de firma de la app** (Configuración › Integridad
   de la app) y guárdalo en la variable del repo `APP_CERT_SHA256` junto a la huella de tu llave
   de subida, separadas por coma; si no, la app instalada desde Play se marca como re-firmada.
3. **Contenido de la app** (Política › Contenido de la app):
   - *Política de privacidad*: `https://<tu-dominio>/privacidad`
   - *Acceso a la app*: "Toda o parte de la funcionalidad está restringida" → agrega un usuario de
     prueba (correo y contraseña de un colaborador de una empresa demo en producción) y explica:
     "La app es para colaboradores de empresas clientes. La marcación exige estar dentro de la
     sede; con este usuario la marcación fuera de la sede se registra igual para la revisión."
     Crea ese usuario en una empresa demo con la geocerca en *Registrar como incidencia* y sin
     geocerca estricta en la app (panel › Configuración).
   - *Anuncios*: No contiene anuncios.
   - *Clasificación del contenido*: cuestionario IARC, categoría Utilidad/Productividad, todo "No".
   - *Público objetivo*: 18 años o más. No atrae a niños.
   - *Seguridad de los datos*: usa la tabla de abajo.
   - *Eliminación de datos*: URL `https://<tu-dominio>/eliminar-cuenta`.
   - *Apps gubernamentales / financieras / de salud*: No.
4. **Ficha de Play Store**: descripción corta y larga, icono 512×512, gráfico de 1024×500 y al
   menos 2 capturas de teléfono (las de la app real, sin marcos inventados).
5. **Prueba cerrada**: si la cuenta de desarrollador es **personal** y se creó después de
   noviembre de 2023, Google exige 12 testers activos durante 14 días seguidos en prueba
   cerrada antes de pedir el acceso a producción. Las cuentas de **organización** no lo necesitan.
6. **Producción**: crea la versión, sube el `.aab` del CI (Actions › App móvil: APK › Artifacts,
   o el Release del tag `v*`), escribe las novedades y envía a revisión.

Antes de cada versión sube `version:` en `mobile/pubspec.yaml` (el número tras `+` debe crecer siempre).

## Seguridad de los datos (respuestas)

Datos cifrados en tránsito: **Sí**. Se pueden pedir eliminar: **Sí**. Recolección obligatoria
para usar la app (excepto foto de perfil y documentos, opcionales). Nada se comparte con
terceros para publicidad ni se vende.

| Tipo de dato (Play Console) | Recolectado | Compartido | Para qué |
|---|---|---|---|
| Ubicación precisa | Sí | No | Funcionalidad de la app (validar la sede al marcar), prevención de fraude |
| Nombre, correo, IDs de usuario (documento, código) | Sí | No | Funcionalidad, administración de la cuenta |
| Fotos (selfie, foto de perfil, documentos) | Sí | No | Funcionalidad, prevención de fraude |
| Archivos y documentos (sustentos, certificados) | Sí | No | Funcionalidad |
| Información de salud (certificados de licencia médica) | Sí | No | Funcionalidad (solicitudes) |
| Información financiera: datos de pago (banco, cuenta) | Sí | No | Funcionalidad (pago de planilla) |
| Interacciones en la app (marcaciones, solicitudes) | Sí | No | Funcionalidad |
| ID del dispositivo u otros (instalación, token push) | Sí | No | Funcionalidad, seguridad y prevención de fraude, notificaciones |
| Diagnóstico / registros de fallos | No | — | — |

Notas: el token de notificaciones viaja a Firebase Cloud Messaging como *proveedor de servicio*
(Google no lo usa para otros fines), por eso no cuenta como "compartido". La detección de rostro
(ML Kit) corre en el teléfono y no genera datos biométricos.

## Declaraciones de permisos

No hace falta ninguna: la app no usa ubicación en segundo plano, fotos/videos (usa el selector del
sistema), alarmas exactas, servicios en primer plano ni ver todas las apps. Si el CI marca un
permiso nuevo, revísalo antes de subir la versión.

## iOS (App Store)

La misma política y la misma eliminación de cuenta sirven para App Store Connect (*Privacidad de
la app* y *Eliminación de cuentas*, obligatoria desde 2022). Pasos en `mobile/README.md` › iOS.
