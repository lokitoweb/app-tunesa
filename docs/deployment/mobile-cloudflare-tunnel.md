# Probar la app en un celular con Cloudflare Tunnel

Objetivo: tener el backend Laravel corriendo en tu PC, publicarlo en internet con una URL
`https://` gracias a Cloudflare Tunnel, y compilar un APK que apunte a esa URL para instalarlo en
un celular real. No hace falta abrir puertos del router ni tener un servidor.

```
Celular (APK) ──https──▶ Cloudflare ──túnel──▶ cloudflared (tu PC) ──http──▶ Laravel :8000
```

Por qué https: Android bloquea el tráfico `http://` en los APK release (solo el modo debug lo
permite, ver `mobile/README.md`). El túnel da https sin certificados propios.

## 1. Instalar `cloudflared`

| Sistema | Comando |
|---|---|
| Windows | `winget install --id Cloudflare.cloudflared` (o descargar el `.msi` desde la página de descargas de Cloudflare) |
| macOS | `brew install cloudflared` |
| Linux (Debian/Ubuntu) | descargar `cloudflared-linux-amd64.deb` de las *releases* de `cloudflare/cloudflared` en GitHub y `sudo dpkg -i cloudflared-linux-amd64.deb` |

Comprueba con `cloudflared --version`.

## 2. Levantar Laravel

Si es la primera vez, en la raíz del repo:

```bash
composer install
composer run setup     # .env, APP_KEY, base SQLite, migraciones + datos demo y storage:link
php artisan serve      # http://127.0.0.1:8000
```

Si ya tienes tu propio servidor (Nginx, Apache, Laragon, XAMPP…), usa su puerto en lugar de
`8000` en los pasos siguientes.

## 3. Abrir el túnel

### Opción A: túnel rápido (sin cuenta, para probar ya)

En otra terminal:

```bash
cloudflared tunnel --url http://localhost:8000
```

Entre los mensajes aparece una URL como `https://palabras-al-azar.trycloudflare.com`. Esa es tu
URL pública. **Cambia cada vez que reinicias `cloudflared`**, así que no cierres esa terminal
mientras pruebas.

### Opción B: túnel con nombre (URL fija)

Necesitas una cuenta de Cloudflare y un dominio gestionado por Cloudflare.

```bash
cloudflared tunnel login                                  # abre el navegador para autorizar
cloudflared tunnel create tunesa                          # crea el túnel y su archivo de credenciales
cloudflared tunnel route dns tunesa api.tudominio.com     # crea el registro DNS
```

Crea `~/.cloudflared/config.yml` (en Windows `%USERPROFILE%\.cloudflared\config.yml`):

```yaml
tunnel: tunesa
credentials-file: /ruta/a/.cloudflared/<ID-DEL-TUNEL>.json
ingress:
  - hostname: api.tudominio.com
    service: http://localhost:8000
  - service: http_status:404
```

Y arráncalo con `cloudflared tunnel run tunesa`. La URL `https://api.tudominio.com` no cambia, así
que el APK compilado sigue sirviendo después de reiniciar.

## 4. Configurar `.env` para el túnel

```dotenv
APP_URL=https://palabras-al-azar.trycloudflare.com   # la URL del túnel, sin /api
TRUSTED_PROXIES=127.0.0.1,::1                         # valor por defecto, déjalo así
```

Luego `php artisan config:clear` (o reinicia `php artisan serve`).

- **`APP_URL`**: Laravel la usa cuando no hay una request de la que sacar el dominio, por
  ejemplo el enlace del correo de recuperación de contraseña (`AppServiceProvider` arma
  `APP_URL/reset-password?...`).
- **`TRUSTED_PROXIES`** (`bootstrap/app.php` → `trustProxies`): `cloudflared` corre en tu PC y le
  entrega cada petición a Laravel desde `127.0.0.1` con la cabecera `X-Forwarded-Proto: https`.
  Como `127.0.0.1,::1` ya es de confianza, Laravel sabe que la petición original era https y
  genera enlaces `https://` (logos, selfies firmadas) con el dominio del túnel. Solo usa `'*'`
  si el proxy está en **otra** máquina (PaaS, balanceador).
- **`storage:link`**: los logos, favicon, splash y avatares están en el disco `public` y se
  sirven desde `/storage/...`. Si `composer run setup` no lo hizo, corre `php artisan storage:link`.
  Las **selfies y documentos no** dependen de esto: están en el disco `private` y se entregan con
  URLs firmadas temporales (`/secure-files/...`).
- **Sanctum / sesión**: la app usa token Bearer, así que `SANCTUM_STATEFUL_DOMAINS` no la afecta.
  Para abrir el **panel** por el túnel funciona tal cual; si activas `SESSION_SECURE_COOKIE=true`,
  entra siempre por la URL https (por `http://localhost` la cookie no viaja y verás errores 419).
- **Subdominios**: con `*.trycloudflare.com` el panel no reconoce empresa por el dominio
  (`Tenant::fromHost`), así que el login muestra la marca de la plataforma. La app no se ve
  afectada porque manda `X-Tenant-Slug`.

Prueba desde el navegador del celular: `https://palabras-al-azar.trycloudflare.com/api/v1/app/config?tenant_slug=empresa-demo`
debe devolver un JSON con `"success": true`.

Opcional: en el panel, **Marca y app → App → URL del servidor (API)** puedes guardar la misma URL
(solo `https://` y sin `/api`). Viaja a la app en `mobile.api_base_url`.

## 5. Compilar el APK apuntando al túnel

La URL se escribe **sin `/api`**: la app lo agrega (`Env.apiUrl` en `mobile/lib/core/config/env.dart`).
Si igual lo pones, la app lo tolera y no lo duplica.

### Opción 1: variable de entorno (recomendada)

```bash
cd mobile
API_BASE_URL=https://palabras-al-azar.trycloudflare.com scripts/build_apk.sh tunnel release
```

En Windows usa Git Bash o WSL para correr el script. El script corre `flutter analyze` y
`flutter test`, compila con `--dart-define-from-file=env/tunnel.json` más
`--dart-define=API_BASE_URL=...`, y deja el APK en
`mobile/build/dist/tunesa-<versión>-tunnel-release.apk`.

### Opción 2: editar `mobile/env/tunnel.json`

Cambia `"API_BASE_URL": "https://CAMBIA-ESTE-SUBDOMINIO.trycloudflare.com"` por tu URL y corre
`scripts/build_apk.sh tunnel`. Si dejas el texto `CAMBIA-ESTE-SUBDOMINIO` sin pasar
`API_BASE_URL`, el script se detiene con un aviso. También puedes fijar `DEFAULT_TENANT` (por
ejemplo `empresa-demo`) para no escribir el código de empresa al entrar.

### Opción 3: GitHub Actions

Actions → **App móvil: APK** → *Run workflow* (`.github/workflows/mobile-apk.yml`):

| Input | Valor |
|---|---|
| `environment` | `tunnel` |
| `api_base_url` | `https://palabras-al-azar.trycloudflare.com` |
| `mode` | `release` |

Al terminar, descarga el artefacto `tunesa-apk-tunnel-release` (se guarda 14 días). Sin los
secrets de firma, el APK sale firmado con la llave debug: sirve para instalar y probar, no para
Play Store. Si no llenas `api_base_url`, se usa la variable de repositorio `API_BASE_URL` o, si
tampoco existe, el valor del JSON.

## 6. Instalar en el celular

- Copia el `.apk` al celular (cable, Drive, correo…) y ábrelo. Android pedirá permitir
  "instalar apps de origen desconocido" para la app con la que lo abriste.
- O con cable y depuración USB: `adb install -r mobile/build/dist/tunesa-<versión>-tunnel-release.apk`.
- Si ya tenías instalada una versión firmada con otra llave, desinstálala primero
  (Android no deja actualizar con una firma distinta).
- Datos demo (`DatabaseSeeder`, solo en local): empresa `empresa-demo`, colaboradora
  `ana.torres@empresa-demo.com`. Las contraseñas las imprime el seeder al terminar.

## Solución de problemas

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| La app no conecta / "sin conexión" | `cloudflared` cerrado, o túnel rápido reiniciado (URL nueva) | Revisa la terminal del túnel. Con túnel rápido, cada reinicio da otra URL: recompila el APK o usa la URL fija (opción B). Ver también "Conexión del servidor" abajo. |
| `401 UNAUTHENTICATED` | Token vencido (`MOBILE_TOKEN_DAYS`), revocado (cambio de contraseña, "cerrar todas las sesiones", más de `MAX_DEVICES_PER_USER` dispositivos) o base de datos re-sembrada | Volver a iniciar sesión. |
| `419` en el panel | Sesión/CSRF vencida o cookie que no viaja (`SESSION_SECURE_COOKIE=true` entrando por http, o alternando entre `localhost` y la URL del túnel) | Recarga y entra siempre por la misma URL. No afecta a la app. |
| `426 APP_UPDATE_REQUIRED` | La versión de la app es menor que la "Versión mínima" de Marca y app con "Forzar actualización" activo | Instala un APK más nuevo (sube `version:` en `mobile/pubspec.yaml`) o baja la versión mínima / desactiva forzar en el panel. |
| `404 TENANT_NOT_FOUND` | Código de empresa mal escrito o empresa suspendida | Usa el slug exacto (`empresa-demo`). |
| `403 TENANT_SCOPE_VIOLATION` | El usuario no pertenece a la empresa elegida | Elige la empresa correcta. |
| Logos o selfies no cargan; enlaces `http://` | Laravel no confió en el proxy y generó URLs http (Android bloquea http en release) | Deja `TRUSTED_PROXIES=127.0.0.1,::1` (o el IP de tu proxy), `APP_URL` con https, `php artisan config:clear`. Para logos, `php artisan storage:link`. |
| Selfie da 403 al abrirla | URL firmada vencida (`SIGNED_URL_MINUTES`, 5 min) o generada con otro esquema/dominio | Vuelve a pedir el detalle; revisa `TRUSTED_PROXIES`. |
| La app no pide GPS o cámara | Permisos negados en Android | Ajustes → Apps → TuneSA → Permisos: Ubicación (precisa) y Cámara. Activa el GPS del teléfono. |
| `422 GPS_ACCURACY_TOO_LOW` | Precisión peor que el máximo (100 m por defecto) | Salir a un lugar abierto; o subir "Precisión GPS máxima" en Marca y app → App. |
| `422 MOCK_LOCATION_DETECTED` | App de GPS falso activa | Desactivarla (`ATTENDANCE_REJECT_MOCK_LOCATION=false` solo para pruebas). |
| `422 GEOFENCE_VIOLATION` "Estás fuera del área permitida…" | Estás lejos de la sucursal y la política es "Rechazar" | Para probar desde casa: edita la sucursal en el panel (ubicación/radio) o cambia la política a "Registrar como incidencia" en Marca y app → Reglas. |
| `422` "No tienes ninguna sucursal asignada" | El colaborador no tiene sucursal vigente | Asígnale una sucursal en el panel. (Llega con código `GEOFENCE_VIOLATION`, ver nota en la API.) |
| `422 VALIDATION_ERROR` con `photo` | La empresa exige selfie y no se envió | Tomar la selfie, o desactivar "Selfie obligatoria". |

<!-- verificar tras unir la app -->
### Conexión del servidor (pendiente de verificar tras unir la app)

> La app que se está desarrollando en paralelo incluye un diálogo oculto **"Conexión del
> servidor"**: se abre manteniendo presionado el logo en la pantalla de inicio y permite cambiar
> la URL del backend sin recompilar. Sirve justo para el túnel rápido, cuya URL cambia en cada
> reinicio: abres el diálogo, pegas la URL nueva (`https://…trycloudflare.com`, sin `/api`) y
> listo. Este comportamiento todavía no está en esta rama; revisar cuando se una.
