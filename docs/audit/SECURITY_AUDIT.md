# Auditoría de seguridad

Severidades: Crítico · Alto · Medio · Bajo · Informativo. Nunca se escriben valores secretos en este documento.
El repositorio en GitHub es **público**: este documento describe riesgos sin datos explotables.

## 1. Inventario de secretos (bloque 0)

### 1.1 Historial de git

| Elemento | Dónde | Tipo | Estado |
|---|---|---|---|
| `.env` con `APP_KEY`, `APP_URL`, `SUPERADMIN_EMAIL`, `SUPERADMIN_PASSWORD` | commit inicial `f10f077` (quitado en `b5d4bb6`) | **De desarrollo local** (`APP_ENV=local`, `APP_DEBUG=true`, sin datos de base) | Público en el historial. En local no coincide nada (comprobado). En producción: lo verifica `app:check-production` en cada despliegue (ver 1.4) |
| `configurar_trujillo.php` | versionado en la raíz | Script puntual con el DNI de una persona real usado como contraseña | **Alto**: dato personal en un repositorio público. **Corregido en el código actual** (09/11/2026): el DNI va como argumento. Sigue en el historial (no se reescribe); deja de ser visible al pasar el repo a privado |
| `mobile/android/app/google-services.json` | versionado | Clave de API de Firebase **pública por diseño** | Informativo: restringirla en Google Cloud por paquete Android y huella SHA-1 |
| `_to_delete/google-services.json.vacio` | versionado | Archivo vacío | Sin riesgo; limpiar en bloque 7 |

No se encontraron en el historial: llaves de firma (`.jks`, `.keystore`, `key.properties`), certificados de Apple
(`.p12`, `.mobileprovision`), cuentas de servicio de Firebase ni llaves privadas.

**Por qué el `.env` histórico probablemente no afecta a producción** (se confirma con 1.4):
- La variable `SUPERADMIN_PASSWORD` ya no la usa ningún código. Producción crea el Super Admin con el asistente
  (contraseña escrita por una persona, mínimo 10 caracteres) o con `SEED_SUPERADMIN_PASSWORD`.
- El asistente genera una `APP_KEY` nueva al instalar (`app/Installer/InstallerServiceProvider.php`).
- Solo habría riesgo si alguien copió a mano esos valores al servidor.

### 1.2 Repositorio, APK y frontend (revisión estática)

- `mobile/env/*.json`: solo URLs (producción, staging, túnel); las claves de Firebase vienen vacías.
- `mobile/lib`: sin secretos. El APK solo trae la URL de la API y la clave pública de Firebase.
- Panel web: la clave de Google Maps va en las URLs de las teselas (**Medio**: debe tener restricción por
  dominio HTTP en Google Cloud; el código no lo puede imponer).
- Semillas: las contraseñas de demostración solo se usan fuera de producción (`DatabaseSeeder.php:34`).

### 1.3 GitHub (verificado con la API de GitHub, 09/11/2026)

- Repositorio **público**. Escaneo de secretos y protección al hacer push: **activados**. Dependabot: desactivado.
- GitHub Actions: **0 secretos y 0 variables** cargados. Los flujos esperan `ANDROID_KEYSTORE_*`, `FIREBASE_*`,
  `IOS_CERTIFICATE_*`, `IOS_PROVISIONING_PROFILE_BASE64`, `IOS_TEAM_ID` y variables `API_BASE_URL`,
  `APP_CERT_SHA256`, `IOS_EXPORT_METHOD`. Consecuencia: los APK que compila CI se firman con la llave de
  depuración y salen sin push (Firebase vacío). **No se deben publicar en Play**.

### 1.4 Verificación automática en producción (implementado)

`php artisan app:check-production` (se ejecuta en cada despliegue) compara, **sin mostrar valores**, la `APP_KEY`
del servidor y la contraseña de cada Super Admin con las del `.env` histórico (`git show f10f077:.env`).
Si coincide algo, lo reporta como error. Si el servidor no tiene git, lo avisa. Prueba:
`ProductionReadinessTest::test_detects_secrets_reused_from_the_public_git_history`.

### 1.5 Prevención

- `.gitignore` ampliado: `.env.*` (salvo los ejemplos), `*.p12`, `*.pem`, `*.key`, `*.mobileprovision`,
  `*.keystore`, `*.jks`, `key.properties`, cuentas de servicio.
- Recomendado (requiere ajustar el repositorio): activar Dependabot; poner el repositorio en privado.

### 1.6 Pasar el repositorio a privado (procedimiento preparado; no ejecutado)

Datos verificados el 09/11/2026 con la API de GitHub: repositorio `lokitoweb/app-tunesa` público, 0 forks,
0 deploy keys, 593 corridas de Actions en los últimos 30 días.

**Implicaciones**

| Tema | Efecto al pasar a privado | Qué hacer antes |
|---|---|---|
| Despliegue automático | El cron del servidor hace `git fetch`. Si `origin` es https anónimo, **deja de desplegar** (sin error visible en la web). Hay 0 deploy keys: no consta que el servidor use SSH | En el servidor: `sudo bash scripts/server-setup.sh --dry-run`; el paso 6 dice si lee el repo y si es por SSH. Si avisa https: crear deploy key de solo lectura (`docs/deployment/produccion-servidor.md` §4) y `git remote set-url origin git@github.com:lokitoweb/app-tunesa.git` como `cyp` |
| Minutos de Actions | Público = gratis. Privado (plan Free) incluye **2 000 min/mes**; el uso medido es **~11 500 min/mes** (E2E ~5 600, APK ~3 500, backend ~2 400). Con límite de gasto 0, al agotarse **los workflows dejan de correr** | Antes: limitar disparadores (E2E y APK solo cuando cambian `mobile/` o la API, o a mano; cancelar corridas repetidas con `concurrency`). Requiere aprobación (cambia CI) |
| Secret scanning / push protection | En repos privados sin GitHub Advanced Security se pierde el escaneo automático | Mantener la verificación propia: `app:check-production` y `.gitignore` endurecido (§1.4, §1.5) |
| Exposición previa | Lo que ya fue público (historial, `.env` de `f10f077`, DNI antiguo) **no se des-publica**: pudo copiarse | La rotación (§2) sigue siendo necesaria |
| Enlaces públicos | README, insignias o descargas de APK desde GitHub dejan de verse sin sesión | Revisar si alguien descarga APKs desde GitHub (hoy no hay Pages ni forks) |

**Orden sin cortes**: (1) servidor por SSH y verificado con el paso 6; (2) disparadores de CI ajustados;
(3) Settings → General → Danger Zone → Change visibility → Private (lo hace el propietario);
(4) confirmar que un push llega a producción (`storage/logs/deploy.log`) y que CI corre; (5) rotación (§2).
Reversión: volver a público desde la misma pantalla (no recomendado tras la rotación).

### 1.7 Informe de secretos: solo local vs. posiblemente productivos (09/11/2026, sin valores)

Comparación hecha con huellas (sha256), nunca mostrando valores.

| Secreto | Dónde estuvo expuesto | Clasificación | Motivo |
|---|---|---|---|
| `APP_KEY` del `.env` de desarrollo | `f10f077:.env` y `24c5beb:.env.example` (mismo valor) | **Solo local, salvo prueba en contrario** | El instalador genera una clave nueva; `app:check-production` lo compara en cada despliegue. **Pendiente**: confirmar en `storage/logs/deploy.log` del servidor que no reporta coincidencia |
| `SUPERADMIN_PASSWORD` | mismos dos commits (mismo valor) | **Posiblemente productivo** si alguien la reutilizó a mano | Ningún código la lee hoy, pero una persona pudo usarla al crear el Super Admin. `app:check-production` compara el hash del Super Admin con ella |
| `SUPERADMIN_EMAIL` | mismos commits | Dato, no secreto | Sirve para saber a quién atacar: más motivo para cambiar la contraseña |
| `HASH_SALT`, `BCRYPT_ROUNDS` | `f10f077:.env` | **Solo local, sin uso** | Ningún código lee `HASH_SALT` |
| DNI de una persona (usado como contraseña) | `configurar_trujillo.php` (historial) | **Posiblemente productivo** | Si esa persona no cambió la contraseña inicial, la cuenta sigue con ella. El cambio obligatorio en el primer ingreso lo impide al entrar, pero no si nunca entró |
| Clave de Firebase de Android | `google-services.json` | Pública por diseño | Restringir por paquete y SHA-1 |
| Clave de Google Maps (panel) | En la base (cifrada); viaja al navegador | Productiva, visible a usuarios del panel | Restringir por dominio HTTP en Google Cloud |
| `DB_PASSWORD`, `MAIL_PASSWORD`, `MIGO_API_TOKEN`, `NUBEFACT_TOKEN`, deploy key | Solo en el `.env` / servidor | Productivos, **nunca expuestos** en el repo | No aparecen en ningún commit |
| Secretos por empresa en la base (Maps, Firebase, webhook, Migo, Nubefact, SMTP, Telegram) | Cifrados con `APP_KEY` | Productivos, no expuestos | Dependen de que `APP_KEY` de producción no sea la del historial |

## 2. Plan de rotación (preparado; se ejecuta solo con autorización)

**Qué depende de `APP_KEY`** (verificado en el código):

- Sesiones y cookies del panel → todos vuelven a iniciar sesión (no hay pérdida de datos).
- URLs firmadas de `/secure-files` → los enlaces vigentes (5 min) dejan de servir.
- Columnas cifradas (`encrypted`): `tenant_settings` (webhook, Maps, Firebase, Migo, Nubefact, SMTP, Telegram),
  **`ethics_cases`** (asunto, relato, resolución, **identidad del denunciante**), `ethics_case_messages`
  (mensajes) y **`employee_profiles`** (cuenta bancaria, CCI, CUSPP, cuenta CTS).
  Si la clave cambia sin re-cifrar y sin conservar la anterior, **estos datos quedan ilegibles para siempre**.
- No dependen de ella: contraseñas (bcrypt), tokens de la app (Sanctum, la app sigue conectada).

**Orden recomendado (sin corte de servicio)**

1. **Super Admin** (riesgo posible, costo cero): cambiar su contraseña desde Mi cuenta. Cierra solo sus sesiones.
2. **Cuenta del DNI histórico**: RR. HH. revisa si esa persona ya cambió su contraseña; si no, "Restablecer
   acceso" (vuelve a su DNI con cambio obligatorio, procedimiento habitual) o se le pide entrar y cambiarla.
3. **Respaldo** antes de tocar `APP_KEY`: `mysqldump` completo + copia del `.env` fuera del servidor
   (el respaldo de la base sin la clave anterior no sirve para leer los datos cifrados: guardar ambos juntos).
4. **`APP_KEY`** (obligatoria solo si `app:check-production` reporta coincidencia; si no, higiene opcional):
   - `APP_PREVIOUS_KEYS=<clave actual>` y `APP_KEY=<nueva>` en el `.env`, `php artisan config:cache`.
     Laravel descifra con la anterior y cifra con la nueva: nada se rompe en este paso.
   - Re-cifrar las columnas listadas (leer y volver a guardar cada fila). **Falta un comando** para esto
     (`app:reencrypt`, idempotente y con conteo por tabla); se programa solo si se aprueba la rotación.
   - Verificar: panel, ajustes de la empresa (Maps/SMTP), un caso de ética y una ficha con cuenta bancaria.
   - Recién después, quitar `APP_PREVIOUS_KEYS`. Mantener el respaldo del `.env` anterior al menos 30 días.
5. **Otros** (solo si hubo exposición; hoy no consta): `DB_PASSWORD`, `MAIL_PASSWORD`, tokens de Migo/Nubefact,
   credenciales por empresa (se cambian desde Ajustes, una por una, en el proveedor y luego en la app).
6. Verificación final: `php artisan app:check-production` sin errores; inicio de sesión real en panel y app.

**Reversión**: `APP_KEY=<anterior>` y `APP_PREVIOUS_KEYS=<nueva>` (así se lee tanto lo no re-cifrado como lo ya
re-cifrado); nunca descartar la clave nueva si algo ya se re-cifró con ella.
**No se hace**: reescribir el historial de git ni borrar archivos históricos.

## 3. Contraseña inicial (DNI) y recuperación de acceso

### 3.1 Contraseña inicial = DNI (se conserva)

Verificado y blindado:
- La cuenta nace con `must_change_password` y vence en 72 h (`CredentialProvisioner`).
- **App**: `EnsurePasswordChanged` cubre todas las rutas autenticadas de la API (las del Super Admin no lo
  necesitan: no tienen contraseña temporal).
- **Panel y Mi espacio** (corregido): antes solo se bloqueaban las pantallas (GET) y solo para usuarios con
  dashboard; un envío de formulario o un pedido JSON podía saltarse el cambio. Ahora `EnsureWebPasswordChanged`
  bloquea todo salvo cambiar la contraseña, cerrar sesión y las páginas legales.
- La nueva contraseña no puede ser el DNI (exige letras, mayúsculas y números) y el DNI deja de servir.
- Hash: argon2id (`config/hashing.php`). Prueba: `CredentialPolicyTest::test_dni_initial_password_cannot_be_skipped_and_stops_working_after_the_change`.

### 3.2 Recuperación de acceso — problema (resuelto, ver 3.3)

Antes "Olvidé mi contraseña" (`PasswordRecovery`) **cambia la contraseña en el momento** a una temporal, la envía
por correo y cierra todas las sesiones. Cualquiera que sepa el correo de otra persona puede dejarla sin acceso
cada 15 minutos (denegación de servicio). No permite tomar la cuenta (la temporal llega al correo de la víctima).

### 3.3 Diseño aprobado e implementado (09/11/2026)

1. El pedido no cambia nada: se genera un **enlace de un solo uso** (token aleatorio, guardado con hash) que
   vence en **30 minutos**, y se envía al correo de la cuenta. La contraseña actual sigue funcionando.
2. Al abrir el enlace, la persona escribe su nueva contraseña (misma política). Recién ahí se cambia, se cierran
   sus sesiones y se invalida el enlace.
3. Un nuevo pedido invalida el enlace anterior. Límite por correo y por IP (además del actual por IP).
4. Respuesta siempre neutra (no revela si el correo existe), como hoy.
5. "Restablecer acceso" de RRHH (vuelve al DNI) **no cambia**.

Consecuencias: cambia el texto del correo y del mensaje en la app (lo manda el servidor; no requiere nueva
versión de la app); la persona termina el cambio en el navegador del teléfono. Si no hay correo configurado,
se mantiene el aviso a RRHH.

## 4. Hallazgos y correcciones (bloque 1)

| # | Hallazgo | Sev. | Estado |
|---|---|---|---|
| S1 | Sesión con contraseña temporal podía operar el panel por POST/JSON | Alto | **Corregido** |
| S2 | Recuperación cambia la contraseña sin confirmación (DoS dirigido) | Medio | **Corregido**: enlace de un solo uso, 30 min (§3.3) |
| S3 | Instalador podía reabrirse si la base falla y no hay `installed.lock` | Medio | **Corregido**: el despliegue crea el lock tras migrar; `app:check-production` avisa si falta |
| S4 | Login más rápido cuando el correo no existe (enumeración por tiempo) | Bajo | **Corregido**: hash de relleno |
| S5 | Respuesta 403 revelaba los roles requeridos y el propio | Bajo | **Corregido** |
| S6 | Correos de personas en los logs | Bajo | **Corregido**: ahora se registra el id |
| S7 | CORS `*` (valor por defecto) | Info | **Corregido**: solo `APP_URL` y `CORS_ALLOWED_ORIGINS` |
| S8 | Marcación sin precisión GPS saltaba el control de precisión | Medio | **Corregido**: se registra y queda como sospechosa (`ACCURACY_MISSING`) |
| S9 | API de revisión de documentos y asignación de sede/turno sin `canManage` (el panel sí lo exige) | Bajo | Pendiente |
| S10 | Panel y Mi espacio permiten cambiar la contraseña aunque la empresa lo prohíba (la API sí lo respeta) | Bajo | Pendiente |
| S11 | Sin CSP en el panel | Bajo | Pendiente (primero en modo informe) |
| S12 | Hora de marcación sin conexión controlada por el teléfono; deriva solo se marca | Medio | Pendiente (bloque 5) |
| S13 | Fotos de perfil y selfies antiguas en disco público (nombres aleatorios) | Bajo | Pendiente |
| S14 | `TRUSTED_PROXIES='*'` permitiría falsear la IP del límite de intentos | Info | A revisar en el servidor |
| S15 | Sin Play Integrity / App Attest; señales de integridad solo informativas | Medio | Bloque 6 (decisión) |

## 5. Verificado como correcto (fase 1)

argon2id con rehash · tokens con vencimiento y revocación · aislamiento entre empresas (todos los
`withoutGlobalScopes` revisados) · sin `$request->all()` hacia modelos · SQL con bindings · sin XSS (`{!! !!}` solo
en Markdown sin HTML) · sin exec/unserialize · subidas con MIME real (finfo), PDF sin JS, documentos en disco
privado servidos con CSP sandbox · CSRF sin excepciones · cookies seguras · webhooks con anti-SSRF y HMAC ·
errores 5xx sin detalles · app: token en almacenamiento seguro, cola offline cifrada, sin backups, HTTPS obligatorio.
