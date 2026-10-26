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

### Google Play

`BUILD_AAB=1 scripts/build_apk.sh prod` genera además el `.aab` que pide Google Play, y todo
release pasa por `scripts/check_play_requirements.py` (targetSdk 36, permisos permitidos y
librerías alineadas a 16 KB). Pasos en Play Console y respuestas de *Seguridad de los datos*:
[docs/deployment/play-store.md](../docs/deployment/play-store.md).

### Pruebas de punta a punta

Con el backend sembrado (`php artisan migrate:fresh --seed`) y corriendo:

```bash
flutter test test/e2e --dart-define=E2E=true --dart-define=API_BASE_URL=http://127.0.0.1:8000   # API
flutter test integration_test --dart-define-from-file=env/dev.json                             # emulador
```

El workflow **App ↔ API: pruebas de punta a punta** las corre en cada PR (emuladores Android 10 y 15).

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
flutter build ipa --release --dart-define-from-file=env/prod.json   # App Store / TestFlight
```

El `.ipa` queda en `build/ios/ipa/`; se sube con Xcode (*Organizer*) o con la app
**Transporter** a App Store Connect / TestFlight. Para otro backend usa `env/staging.json`
o `env/prod.json`, o agrega `--dart-define=API_BASE_URL=https://...`.

Permisos declarados en `ios/Runner/Info.plist` (textos en español): cámara (selfie),
ubicación en uso (geocerca) y galería (adjuntar sustentos). iOS solo permite HTTPS; `http`
se admite únicamente en red local (`NSAllowsLocalNetworking`) para desarrollo.

En GitHub Actions, *Run workflow* (con "Compilar también iOS" marcado) compila iOS en `macos-15`
y deja como artefacto `tunesa-ios-<entorno>`:

- `tunesa-ios-<entorno>-unsigned.ipa`: siempre. Sirve para verificar que compila; no se instala
  sin firmar.
- `tunesa-ios-<entorno>-ad-hoc.ipa` (o `app-store-connect`): solo si existen los secrets
  `IOS_CERTIFICATE_P12_BASE64`, `IOS_CERTIFICATE_PASSWORD`, `IOS_PROVISIONING_PROFILE_BASE64` e
  `IOS_TEAM_ID` (certificado *Apple Distribution* y perfil del Bundle ID `pe.tunesa.tunesaMobile`,
  con Push Notifications). La variable `IOS_EXPORT_METHOD` elige `ad-hoc` (por defecto, instalación
  directa en iPhones registrados) o `app-store-connect` (TestFlight / App Store).

Si el job falla con "The job was not acquired by Runner", GitHub no tenía equipos macOS libres:
se vuelve a lanzar, no es un error del código.

Textos de propósito además de los anteriores: `NSLocationAlwaysAndWhenInUseUsageDescription` y
`NSMicrophoneUsageDescription` (los plugins enlazan esas API aunque la app no las usa; sin el texto
App Store rechaza la subida) e `ITSAppUsesNonExemptEncryption = false`.

## Estructura

```
lib/
  core/        config (Env, AppConfig remota, versión mínima), network (Dio + errores,
               dominio en caliente), storage (token seguro, llaves locales), push (FCM),
               location (GPS estricto + Haversine), router, theme, widgets
  features/
    auth/        empresa (marca blanca), login y "olvidé mi contraseña"
    home/        inicio con turno del día, botones de marcación, campana y Mi equipo
    notifications/ bandeja de notificaciones (marcar leída / todas, abre la pantalla)
    team/        supervisor / RRHH: aprobaciones y asistencia del equipo en vivo
    attendance/  marcación GPS + selfie (+ rostro ML Kit, WebP), cola sin conexión cifrada
                 (SQLCipher + lote), no aceptadas, dirección legible, historial, justificaciones
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

- Todo se toma **en el momento de la marcación**: al disparar la selfie se pide una lectura GPS
  fresca de máxima precisión (nunca la última cacheada) y se guarda la hora UTC del teléfono
  (`device_time`); sin selfie (descansos), al confirmar. Una selfie de más de 5 min se repite.
- Cada captura lleva un `client_uuid` (UUID v4) que se reusa en todos los reintentos: si el
  servidor ya la tenía responde `duplicate: true` y la app lo toma como éxito.
- Ubicación simulada: Android `Position.isMocked`; iOS 15+ además un canal nativo
  (`tunesa/location_integrity` en `ios/Runner/AppDelegate.swift`) lee
  `CLLocation.sourceInformation.isSimulatedBySoftware` / `isProducedByAccessory` con una lectura
  puntual (si falla, `false`). Según `mock_location_policy`: `reject` bloquea antes de enviar con
  la explicación; `flag` envía con `is_mocked=1` y avisa "Tu marcación quedará en revisión".
- Precisión GPS máxima y geocerca previa contra las sucursales asignadas; el backend vuelve a
  validar todo (mock, precisión, geocerca con Haversine, desfase de hora).
- Selfie solo con la cámara frontal y en el momento (sin galería), detección de rostro con ML Kit
  antes de habilitar el envío (`face_detected`) y compresión **WebP calidad 80, lado mayor ≤ 1280 px**
  (`flutter_image_compress`; si el equipo no codifica WebP, JPEG 80). En los descansos la selfie
  es opcional.
- Si la hora del teléfono difiere de `server_time` más que `max_time_drift_seconds`, la pantalla
  de marcación pide activar la hora automática (no bloquea).
- La pantalla muestra la **dirección legible** de la posición (`GET /api/geocode/reverse`, con
  espera de 600 ms y caché por ~50 m); es informativa y nunca bloquea la marcación.

## Protección de la app

- **Código ofuscado:** los release salen con `--obfuscate --split-debug-info` (APK y iOS). R8 ya
  minimiza y ofusca la parte Java/Kotlin. Los símbolos quedan en `build/debug-info/<versión>` (y como
  artefacto `tunesa-symbols-*` en Actions): guárdalos para leer trazas con `flutter symbolize`.
- **Sin copias de seguridad:** `allowBackup=false` y `data_extraction_rules.xml`, así la sesión, la cola
  offline cifrada y las selfies no se pueden sacar con `adb backup` ni restaurar en otro teléfono.
- **Teléfono manipulado:** el canal nativo `tunesa/device_integrity` (`MainActivity.kt`, `AppDelegate.swift`)
  detecta root/jailbreak, emulador, depurador en release, Frida/Xposed/Substrate y, si la build trae
  `APP_CERT_SHA256`, un APK re-firmado por otra llave. La app manda las señales en `X-Device-Integrity`;
  el servidor acepta la marcación pero la deja como sospechosa (`UNSAFE_DEVICE`) y RRHH ve el detalle
  en la evidencia. No bloquea: un falso positivo no deja a nadie sin marcar.
- `APP_CERT_SHA256`: huella SHA-256 del certificado de firma (`keytool -list -v -keystore upload-keystore.jks`).
  Si publicas en Google Play con *App Signing*, agrega también la huella de la llave de Google Play
  (Play Console › Integridad de la app), separadas por coma. En Actions: variable de repositorio `APP_CERT_SHA256`.

## Configuración dinámica (`GET /api/v1/app/config`)

Al abrir la app (y al volver a ella) se descarga la configuración pública de la empresa
elegida (`?tenant_slug=`; sin empresa, la de la plataforma). Es la única fuente de verdad para:

- **Marca blanca**: colores `primary/secondary/accent` (tema claro y oscuro), `navbar_color`
  para AppBar y barra inferior (el texto se elige por luminancia) y logo claro/oscuro con caché
  en disco. Cualquier color o URL en null usa el valor por defecto.
- **Reglas de marcación**: `selfie_required`, `face_detection_required`,
  `max_gps_accuracy_meters` (respaldo `MAX_GPS_ACCURACY`), `offline_mode_enabled`, `offline_max_hours`,
  `mock_location_policy` (`reject` | `flag`; por defecto `reject`), `max_time_drift_seconds`,
  `geocoding_provider` (informativo) y `fcm_enabled` (si es `false` no se registra el token push).
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

Solo si la empresa activa `offline_mode_enabled`. Sin red (o si el envío falla por red/tiempo
agotado) las cuatro marcaciones (entrada, inicio y fin de descanso, salida) se guardan en una
**base SQLite cifrada con SQLCipher** (`sqflite_sqlcipher`, archivo `tunesa_offline.db`); la llave
es aleatoria (32 bytes, `Random.secure`) y vive solo en el Keychain / Keystore
(`flutter_secure_storage`). La selfie comprimida se guarda **cifrada con AES-256-GCM**
(`cryptography`, otra llave aleatoria) en `<documentos de la app>/offline_punches/`. La cola
anterior (JSON en el almacenamiento seguro + selfie en claro) se migra sola al primer arranque.

- Se envía al recuperar señal, al volver a la app, cada 60 s mientras la app está abierta y hay
  pendientes, y con "Enviar ahora" / deslizar para actualizar en Inicio, en lotes de 20 por
  `POST /api/v1/attendances/sync-batch` (`punches[i][campo]`, con `offline_recorded_at`). Si el
  backend no tiene el lote (otro 4xx) se envían una por una a las rutas de siempre.
- `created` / `duplicate` → se quita y se borra la foto. `rejected` → se quita y aparece en
  **Marcaciones no aceptadas** (`/attendance/rejected`, con el motivo; el colaborador las descarta).
- Red caída, 5xx, 401, 408, 425, 426 o 429 → se conserva y se reintenta con espera exponencial
  (30 s, 1 min, 2 min… hasta 30 min); las acciones manuales ignoran la espera.
- Las que superan `offline_max_hours` se descartan con aviso y quedan en "no aceptadas".
- La pantalla de marcación muestra cuántas faltan enviar y la última sincronización. Solo se
  envían las del usuario con sesión iniciada.

Sin el modo activo, sin red no se marca.

## Notificaciones push (FCM)

Firebase se inicializa **desde Dart con opciones de `--dart-define`** y Android **no** usa el
plugin `com.google.gms.google-services`. `android/app/google-services.json` (proyecto Firebase
`app-tunesa`, app `com.tunesa.mobile`) está versionado: `scripts/build_apk.sh` (local y CI) toma
de ahí los `FIREBASE_*` de Android que sigan vacíos. No hay `GoogleService-Info.plist`.
Claves (vacías en `env/*.json`):

| Clave | Uso |
|---|---|
| `FIREBASE_API_KEY`, `FIREBASE_MESSAGING_SENDER_ID`, `FIREBASE_PROJECT_ID` | Comunes |
| `FIREBASE_APP_ID` | App Android (`1:…:android:…`) |
| `FIREBASE_IOS_APP_ID`, `FIREBASE_IOS_BUNDLE_ID` | App iOS |

Si falta alguna obligatoria para la plataforma, el push queda **apagado sin errores**. Con sesión
iniciada (y `fcm_enabled` de la empresa) la app pide permiso de notificaciones (Android 13+
`POST_NOTIFICATIONS`, iOS), registra el token con `POST /api/auth/refresh-fcm` `{fcm_token}` al
entrar y cada vez que rota, y lo borra al cerrar sesión. En primer plano Android muestra el aviso
con `flutter_local_notifications` (canal `tunesa_default`); iOS usa las opciones de presentación de
FCM. Al tocar un push se abre la pantalla de `data.screen` (o según `type`, como la bandeja in-app).

- Local: `flutter run --dart-define-from-file=env/dev.json --dart-define=FIREBASE_API_KEY=...` (o
  completar el JSON sin subirlo) o exportar las variables antes de `scripts/build_apk.sh`.
- CI: secrets o variables de repositorio con los mismos nombres; solo los no vacíos se pasan como
  `--dart-define`. Sin ellos el workflow compila igual.
- **iOS** (en Xcode, target *Runner* → *Signing & Capabilities*): agregar **Push Notifications** y
  **Background Modes → Remote notifications** (`UIBackgroundModes` ya está en `Info.plist`), y subir
  la **llave APNs (.p8)** en Firebase → Configuración del proyecto → Cloud Messaging. Sin APNs, iOS
  no entrega token y el push queda inactivo.
- Android usa desugaring (`coreLibraryDesugaring`), requerido por `flutter_local_notifications`.

> Nota de dependencias: `pubspec.yaml` fuerza `dbus ^0.8.0` (`dependency_overrides`) porque
> `connectivity_plus` 7.3 y `flutter_local_notifications_linux` piden versiones distintas; dbus
> solo se usa en Linux de escritorio, que esta app no compila.
