# TuneSA — App móvil (Flutter)

App del colaborador para Android e iOS: login por empresa, inicio con el turno del día,
marcación con GPS estricto + selfie frontal, historial y justificaciones, solicitudes
(vacaciones, permisos, licencias) con scroll infinito, visor de boletas en PDF y
notificaciones in-app. Supervisores y RRHH ven además **Mi equipo** en Inicio: bandeja de
aprobaciones (aprobar / rechazar con motivo) y la asistencia del equipo en vivo.

## Requisitos

- Flutter 3.47.x (Dart 3.13) · Java 17 · Android SDK (o Xcode para iOS)
- Backend Laravel corriendo (`php artisan serve` en la raíz del repo)

## Entornos y URL del backend

Toda la configuración está en `env/<entorno>.json` y entra en tiempo de compilación:

| Clave | Uso |
|---|---|
| `API_BASE_URL` | Dominio del backend, sin `/api` (ej. `https://api.tunesa.app`) |
| `DEFAULT_TENANT` | Código de empresa fijo para una app de marca blanca. Vacío = el colaborador lo escribe |
| `MAX_GPS_ACCURACY` | Precisión GPS máxima aceptada en metros |
| `ENV_NAME` | `dev`, `staging` o `prod` |

Para cambiar de dominio basta con editar `API_BASE_URL` en el JSON, o sobrescribirlo al compilar:
`API_BASE_URL=https://api.cliente.pe scripts/build_apk.sh prod`.

```bash
flutter pub get
flutter run --dart-define-from-file=env/dev.json   # emulador Android → backend local en 10.0.2.2:8000
```

En un teléfono físico usa la IP de tu PC en `env/dev.json` (ej. `http://192.168.1.20:8000`)
y arranca el backend con `php artisan serve --host 0.0.0.0`.

## Compilar el APK

```bash
scripts/build_apk.sh                 # release contra env/prod.json
scripts/build_apk.sh staging         # release contra env/staging.json
scripts/build_apk.sh dev debug       # debug contra el backend local (permite http)
```

El script corre `flutter analyze` y `flutter test` antes de compilar y deja el APK en
`build/dist/tunesa-<versión>-<entorno>-<modo>.apk`. El tráfico `http://` solo se permite en debug.

### Desde GitHub Actions

El workflow `.github/workflows/mobile-apk.yml` compila el APK en cada PR que toca `mobile/`
y lo publica como artefacto (pestaña Actions → la ejecución → *Artifacts*). Para elegir
entorno y modo: Actions → **App móvil: APK** → *Run workflow*. Al crear un tag `v*` el APK
se adjunta al GitHub Release.

### Firma de release

Un release de **prod** exige `android/key.properties`: sin él `scripts/build_apk.sh prod` falla.
Para `staging`, `tunnel` y `dev` (o con `ALLOW_DEBUG_SIGNING=1`, que usa el CI en PRs) el APK se
firma con la llave debug y se muestra un aviso: sirve para instalar y probar, no para Play Store.

El release usa R8 (`isMinifyEnabled` + `isShrinkResources`) con las reglas de
`android/app/proguard-rules.pro`; el `namespace` y el `applicationId` son `com.tunesa.mobile`.

1. Crear la llave (una sola vez, guárdala en un lugar seguro):
   `keytool -genkey -v -keystore upload-keystore.jks -keyalg RSA -keysize 2048 -validity 10000 -alias upload`
2. Local: copiar la llave a `android/app/upload-keystore.jks` y crear `android/key.properties`
   (ambos están en `.gitignore`):
   ```
   storeFile=upload-keystore.jks
   storePassword=...
   keyAlias=upload
   keyPassword=...
   ```
3. CI: crear los secrets `ANDROID_KEYSTORE_BASE64` (`base64 -w0 upload-keystore.jks`),
   `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS` y `ANDROID_KEY_PASSWORD`.

## iOS

Requisitos: una **Mac con Xcode** (y CocoaPods: `sudo gem install cocoapods` o `brew install cocoapods`),
una **cuenta de Apple Developer** (de pago para TestFlight / App Store) y el **equipo de firma**
(*Signing Team*) configurado en Xcode: abrir `ios/Runner.xcworkspace` → target *Runner* →
*Signing & Capabilities* → elegir el *Team* y, si hace falta, cambiar el *Bundle Identifier*
(hoy `pe.tunesa.tunesaMobile`). El mínimo es iOS 15.5 (`ios/Podfile`; lo exige ML Kit para la detección de rostro).

```bash
flutter pub get
cd ios && pod install && cd ..                      # la primera vez o al cambiar plugins
flutter run --dart-define-from-file=env/dev.json    # simulador → backend en localhost
flutter build ipa --release --dart-define-from-file=env/tunnel.json
```

El `.ipa` queda en `build/ios/ipa/`; se sube con Xcode (*Organizer*) o con la app
**Transporter** a App Store Connect / TestFlight. Para otro backend usa `env/staging.json`
o `env/prod.json`, o agrega `--dart-define=API_BASE_URL=https://...`.

Permisos declarados en `ios/Runner/Info.plist` (textos en español): cámara (selfie),
ubicación en uso (geocerca) y galería (adjuntar sustentos). iOS solo permite HTTPS; `http`
se admite únicamente en red local (`NSAllowsLocalNetworking`) para desarrollo.

En GitHub Actions, *Run workflow* compila además iOS **sin firma** en `macos-latest`
(`flutter build ios --release --no-codesign`) y deja `Runner.app` comprimido como artefacto,
útil para verificar que compila; no se puede instalar sin firmar.

## Estructura

```
lib/
  core/        config (Env, AppConfig remota, versión mínima), network (Dio + errores,
               dominio en caliente), storage (token seguro),
               location (GPS estricto + Haversine), router, theme, widgets
  features/
    auth/        empresa (marca blanca), login y "olvidé mi contraseña"
    home/        inicio con turno del día, botones de marcación, campana y Mi equipo
    notifications/ bandeja de notificaciones (marcar leída / todas, abre la pantalla)
    team/        supervisor / RRHH: aprobaciones y asistencia del equipo en vivo
    attendance/  marcación GPS + selfie (+ rostro ML Kit), cola sin conexión, historial,
                 justificaciones
    app_update/  pantalla "Actualiza la app" y aviso de nueva versión
    server/      diálogo oculto "Conexión del servidor"
    splash/      primer arranque mientras llega la configuración
    requests/    vacaciones, permisos y licencias médicas
    payslips/    lista y visor PDF
    profile/     Mi perfil: Mi Legajo, Datos de Pago y Previsión, Dotación,
                 contacto de emergencia y tema claro/oscuro
```

Las secciones de Mi perfil (y la pestaña de Boletas) se muestran u ocultan según los
switches que la empresa configura en el panel (`config` de `GET /api/employee/profile`).

Estado con **Riverpod**; navegación con **go_router** (redirige según la sesión).

## Anti-fraude en la marcación

- Lectura GPS fresca de máxima precisión (nunca la última posición cacheada).
- Se rechaza la ubicación simulada (`isMocked`, apps de GPS falso) y la de baja precisión.
- Validación previa de geocerca contra las sucursales asignadas; el backend vuelve a
  validar mock, precisión y geocerca con Haversine (`ATTENDANCE_REJECT_MOCK_LOCATION`,
  `ATTENDANCE_MAX_GPS_ACCURACY`).
- Selfie solo con la cámara frontal y en el momento; no hay opción de galería.
- Selfie, detección de rostro (ML Kit en el teléfono, se envía `face_detected`), precisión GPS
  máxima y marcación sin conexión se activan por empresa desde `/v1/app/config` (ver abajo).

## Configuración dinámica (`GET /api/v1/app/config`)

Al abrir la app (y al volver a ella) se descarga la configuración pública de la empresa
elegida (`?tenant_slug=`; sin empresa, la de la plataforma). Es la única fuente de verdad para:

- **Marca blanca**: colores `primary/secondary/accent` (tema claro y oscuro), `navbar_color`
  para AppBar y barra inferior (el texto se elige por luminancia) y logo claro/oscuro con caché
  en disco. Cualquier color o URL en null usa el valor por defecto.
- **Reglas de marcación**: `selfie_required`, `face_detection_required`,
  `max_gps_accuracy_meters` (respaldo `MAX_GPS_ACCURACY`), `offline_mode_enabled`, `offline_max_hours`.
- **Versión**: si la app es menor que `min_supported_version` con `force_update` (o cualquier API
  responde 426 `APP_UPDATE_REQUIRED`) se muestra la pantalla bloqueante "Actualiza la app" con el
  contacto de la empresa; si solo hay una versión más nueva, un aviso descartable. Toda solicitud
  envía `X-App-Version`.

La última configuración buena se guarda en el teléfono: la app abre con la marca correcta al
instante y funciona sin red. Solo el primer arranque sin caché muestra el splash (máx. 4 s).

## Conexión del servidor (oculto, para testers)

Mantener presionado el logo en la pantalla de código de empresa o en el login abre
**Conexión del servidor**: escribir una URL (ej. `https://xxxx.trycloudflare.com`), *Probar*
(`GET /up` o `/api/v1/app/config` debe responder 200), *Guardar* o *Restablecer* al dominio
compilado. Exige `https://` (`http://` solo en builds `ENV_NAME=dev`). El cambio aplica sin
reiniciar. Prioridad: override guardado > `API_BASE_URL` compilado. Si el servidor anuncia otro
`mobile.api_base_url`, la app cambia sola tras comprobar que responde; con un override y varios
fallos de red seguidos vuelve al dominio compilado (solo si este sí responde).

## Marcación sin conexión

Solo si la empresa activa `offline_mode_enabled`. Sin red, la marcación (tipo, GPS, precisión,
hora ISO con desfase, datos del equipo) se guarda cifrada en el almacenamiento seguro y la selfie
se copia a los documentos de la app; se avisa "Marcación guardada sin conexión, se enviará al
recuperar señal" y la pantalla de marcación muestra cuántas faltan enviar. Se envían solas al
recuperar señal, al volver a la app o deslizando para actualizar en Inicio, con
`offline_recorded_at`. Las que superan `offline_max_hours` se descartan con aviso; un error de
negocio (422 `OFFLINE_NOT_ALLOWED`, `OFFLINE_TOO_OLD`, geocerca…) la quita y muestra el motivo; un
error de red la conserva. Sin el modo activo, sin red no se marca.
