# TuneSA — App móvil (Flutter)

App del colaborador para Android e iOS: login por empresa, inicio con el turno del día,
marcación con GPS estricto + selfie frontal, historial y justificaciones, solicitudes
(vacaciones, permisos, licencias) y visor de boletas en PDF.

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

Sin llave, el APK release se firma con la llave debug: sirve para instalar y probar, no para Play Store.

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

## Estructura

```
lib/
  core/        config (Env), network (Dio + errores), storage (token seguro),
               location (GPS estricto + Haversine), router, theme, widgets
  features/
    auth/        empresa (marca blanca) y login
    home/        inicio con turno del día y botones de marcación
    attendance/  marcación GPS + selfie, historial, justificaciones
    requests/    vacaciones, permisos y licencias médicas
    payslips/    lista y visor PDF
```

Estado con **Riverpod**; navegación con **go_router** (redirige según la sesión).

## Anti-fraude en la marcación

- Lectura GPS fresca de máxima precisión (nunca la última posición cacheada).
- Se rechaza la ubicación simulada (`isMocked`, apps de GPS falso) y la de baja precisión.
- Validación previa de geocerca contra las sucursales asignadas; el backend vuelve a
  validar mock, precisión y geocerca con Haversine (`ATTENDANCE_REJECT_MOCK_LOCATION`,
  `ATTENDANCE_MAX_GPS_ACCURACY`).
- Selfie solo con la cámara frontal y en el momento; no hay opción de galería.
- Sin conexión no se marca: no existe cola offline, porque una marcación diferida no se
  puede verificar contra la hora y la ubicación reales.
