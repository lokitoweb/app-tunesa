# Producción: servidor, MySQL y despliegue automático

Guía para poner TuneSA en **https://group-cyp.com** (VPS Ubuntu con Nginx y MySQL), y para que cada `git push`
a `master` se publique solo. Al final: la app móvil apuntando a ese dominio.

Lo que tienes que reemplazar en los comandos:

| Marcador | Qué es | Ejemplo |
|---|---|---|
| `IP_DEL_SERVIDOR` | IP pública del VPS | `203.0.113.10` |
| `CLAVE_MYSQL` | Contraseña del usuario MySQL `tunesa` | (larga y única) |

Todo lo de los pasos 1 a 9 se hace **una sola vez**. Después, cada push a `master` se despliega solo (paso 10).

---

## 0. Resumen de cómo queda

```
GitHub (master) ──push──▶ despliegue (Opción A: GitHub Actions por SSH · Opción B: cron en el servidor)
                                │
                                ▼
VPS Ubuntu ─ /var/www/tunesa  (clon de git, .env propio)
  ├─ Nginx  ──▶ /var/www/tunesa/public   (HTTPS con Let's Encrypt)
  ├─ PHP 8.3-FPM
  ├─ MySQL 8 (base tunesa)
  └─ cron: schedule:run cada minuto (faltas, recordatorios, cola de push/webhooks)
```

El despliegue lo hace siempre el mismo script, `scripts/deploy.sh`, dentro del servidor:
`git` al commit nuevo → `composer install --no-dev` → `npm ci && npm run build` (solo si cambió el panel) →
modo mantenimiento → `php artisan migrate --force` → `config:cache`, `route:cache`, `view:cache`, `event:cache` →
`queue:restart` → fuera de mantenimiento → `app:check-production`. Si un paso falla vuelve al commit anterior.

Requisitos mínimos: Ubuntu 22.04 o 24.04, 2 GB de RAM (1 GB + swap también sirve), 20 GB de disco.

---

## 1. DNS

En el panel de tu dominio crea:

- Registro **A** `group-cyp.com` → `IP_DEL_SERVIDOR`.
- Registro **CNAME** `www` → `group-cyp.com` (Nginx lo redirige al dominio sin www).
- Solo si cada empresa entrará por subdominio (`empresa.group-cyp.com`): registro **A** `*.group-cyp.com` → la misma IP.

Si usas Cloudflare, deja la nube en **gris** (solo DNS) hasta terminar el paso 8; luego puedes activarla
(ver "Detrás de Cloudflare" al final).

## 2. Paquetes del servidor

Entra por SSH como root (o un usuario con sudo):

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install software-properties-common curl git unzip acl nginx mysql-server certbot python3-certbot-nginx

# PHP 8.3 con las extensiones que pide el proyecto
sudo add-apt-repository -y ppa:ondrej/php   # en Ubuntu 24.04 no hace falta, ya trae 8.3
sudo apt update
sudo apt -y install php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-gd php8.3-zip php8.3-bcmath php8.3-intl php8.3-opcache

# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

# Node 22 (para compilar el panel con npm run build)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt -y install nodejs

php -v && composer -V && node -v && mysql --version
```

Límites de subida (selfies, documentos, Excel). En `/etc/php/8.3/fpm/php.ini`:

```ini
upload_max_filesize = 20M
post_max_size = 25M
memory_limit = 256M
max_execution_time = 60
```

## 3. MySQL

```bash
sudo mysql
```

```sql
CREATE DATABASE tunesa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tunesa'@'localhost' IDENTIFIED BY 'CLAVE_MYSQL';
GRANT ALL PRIVILEGES ON tunesa.* TO 'tunesa'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**Importante (triggers):** una migración crea un trigger que impide modificar las marcaciones ya registradas
(auditoría). MySQL 8 tiene el binlog activo y, sin este ajuste, rechaza crear triggers a un usuario que no es
root (`ERROR 1419 ... SUPER privilege and binary logging is enabled`). Actívalo una vez:

```bash
echo -e "[mysqld]\nlog_bin_trust_function_creators = 1" | sudo tee /etc/mysql/mysql.conf.d/tunesa.cnf
sudo systemctl restart mysql
```

(En MariaDB el archivo va en `/etc/mysql/mariadb.conf.d/`.)

## 4. Usuario de despliegue y código

Un usuario `deploy` es dueño del código; PHP-FPM (`www-data`) solo escribe en `storage/` y `bootstrap/cache/`.

```bash
sudo adduser --disabled-password --gecos "" deploy
sudo usermod -aG www-data deploy
sudo mkdir -p /var/www/tunesa && sudo chown deploy:www-data /var/www/tunesa
sudo -iu deploy
```

El repositorio es privado: el servidor necesita una **deploy key** (solo lectura) para clonarlo.

```bash
# (como deploy)
ssh-keygen -t ed25519 -C "deploy@tunesa-servidor" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Copia esa línea en GitHub → repositorio `app-tunesa` → **Settings → Deploy keys → Add deploy key**
(sin marcar "Allow write access"). Luego:

```bash
ssh -T git@github.com            # responde "Hi lokitoweb/app-tunesa! ..." → listo
git clone git@github.com:lokitoweb/app-tunesa.git /var/www/tunesa
cd /var/www/tunesa
```

## 5. `.env` de producción

```bash
cd /var/www/tunesa
cp .env.production.example .env
nano .env
```

La plantilla ya trae `APP_URL=https://group-cyp.com`, `APP_DOMAIN=group-cyp.com` y
`SANCTUM_STATEFUL_DOMAINS`. Completa lo que dice `CAMBIAR`: `DB_PASSWORD=CLAVE_MYSQL`, el correo SMTP, y para el primer arranque
`SEED_SUPERADMIN_EMAIL` / `SEED_SUPERADMIN_PASSWORD` (12+ caracteres).

```bash
sudo chgrp www-data .env && chmod 640 .env   # lo leen deploy y PHP-FPM, nadie más
composer install --no-dev --no-interaction --optimize-autoloader
php artisan key:generate
```

> `APP_KEY` cifra los secretos de "Marca y app". Guárdala en un lugar seguro: si la pierdes o la cambias,
> hay que volver a cargar esos secretos.

## 6. Permisos de `storage` y `bootstrap/cache`

```bash
# (como deploy, dentro de /var/www/tunesa)
sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo setfacl -R  -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
sudo setfacl -dR -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
```

Las ACL por defecto (`-d`) hacen que los archivos nuevos (logs, selfies, caché) sigan siendo escribibles por
los dos usuarios, sin `chmod 777`.

## 7. Primer despliegue y Super Admin

```bash
# (como deploy, dentro de /var/www/tunesa)
bash scripts/deploy.sh --force         # composer, npm build, migraciones, storage:link y cachés
php artisan db:seed --force            # crea SOLO el Super Admin (sin datos demo) con SEED_SUPERADMIN_*
```

Después borra `SEED_SUPERADMIN_PASSWORD` del `.env` y vuelve a cachear la configuración:

```bash
nano .env                              # deja SEED_SUPERADMIN_PASSWORD= vacío
php artisan config:cache
```

Al entrar por primera vez el panel pide cambiar la contraseña. Producción arranca con la base vacía: las
empresas, sedes y trabajadores se crean desde el panel (los datos de prueba de tu SQLite local no se copian).

## 8. Nginx y HTTPS

Crea `/etc/nginx/sites-available/tunesa` (`www.group-cyp.com` redirige al dominio sin www, que es el de `APP_URL`):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name www.group-cyp.com;
    return 301 https://group-cyp.com$request_uri;
}

server {
    listen 80;
    listen [::]:80;
    server_name group-cyp.com *.group-cyp.com;
    root /var/www/tunesa/public;
    index index.php;

    client_max_body_size 25M;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120;
    }

    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/tunesa /etc/nginx/sites-enabled/tunesa
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

# Certificado (dominio principal)
sudo certbot --nginx -d group-cyp.com -d www.group-cyp.com --redirect -m tu-correo@ejemplo.com --agree-tos
```

Certbot agrega el bloque `listen 443 ssl` y la redirección de http a https, y renueva solo.
Si usarás subdominios por empresa, el certificado comodín (`*.group-cyp.com`) necesita validación por DNS:
`sudo certbot certonly --manual --preferred-challenges dns -d group-cyp.com -d '*.group-cyp.com'`
(o el plugin DNS de tu proveedor, p. ej. `python3-certbot-dns-cloudflare`, para que se renueve solo).

Comprueba: `https://group-cyp.com/up` debe responder 200 y `https://group-cyp.com` mostrar el login.

## 9. Tareas programadas, cola y recarga de PHP

**Cron del scheduler** (faltas a la 01:30, recordatorios de entrada, limpieza de tokens y la cola de push/webhooks):

```bash
sudo crontab -u deploy -e
```

```cron
* * * * * cd /var/www/tunesa && php artisan schedule:run >> /dev/null 2>&1
```

Con eso basta para la cola (`QUEUE_WORK_FROM_SCHEDULER=true`). Si prefieres un worker permanente,
crea `/etc/systemd/system/tunesa-queue.service`:

```ini
[Unit]
Description=TuneSA queue worker
After=network.target mysql.service

[Service]
User=deploy
Group=www-data
WorkingDirectory=/var/www/tunesa
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now tunesa-queue
```

y pon `QUEUE_WORK_FROM_SCHEDULER=false` en el `.env` (luego `php artisan config:cache`). El deploy
reinicia los workers solo (`queue:restart`).

**Recargar PHP-FPM tras cada deploy** (limpia OPcache). Permite que `deploy` lo haga sin contraseña:

```bash
echo 'deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm' | sudo tee /etc/sudoers.d/tunesa-deploy
sudo chmod 440 /etc/sudoers.d/tunesa-deploy
```

y define `PHP_FPM_SERVICE=php8.3-fpm` en el entorno del deploy (en el crontab de la Opción B o en el comando
de la Opción A, ver abajo). Sin esto el deploy funciona igual, pero PHP puede tardar en ver el código nuevo
si `opcache.validate_timestamps=0`.

## 10. Despliegue automático en cada push a master

Hay dos formas. Las dos corren el mismo `scripts/deploy.sh`; elige una.

### Sobre los minutos de GitHub Actions

Con el repositorio **privado** en el plan gratuito, GitHub da 2.000 minutos al mes de Actions (los runners
macOS cuentan 10 veces, así que el build de iOS se come la cuota rápido). Cuando se acaban o el límite de gasto
está en 0, los jobs fallan en unos segundos sin llegar a ejecutarse: eso es lo que se ve hoy en "Backend: pruebas",
"App móvil: APK" y la prueba e2e. Un deploy por Actions usa ~1 minuto por push, pero **no corre** mientras no haya
minutos. La Opción B no usa Actions y funciona siempre.

### Opción A: GitHub Actions por SSH (`.github/workflows/deploy.yml`)

1. En el servidor, crea una llave para que GitHub entre como `deploy`:

   ```bash
   # (como deploy)
   ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/gha_deploy -N ""
   cat ~/.ssh/gha_deploy.pub >> ~/.ssh/authorized_keys
   cat ~/.ssh/gha_deploy            # privada: va al secret DEPLOY_SSH_KEY
   ssh-keyscan -p 22 IP_DEL_SERVIDOR  # va al secret DEPLOY_KNOWN_HOSTS
   ```

2. En GitHub → **Settings → Secrets and variables → Actions**:
   - *Secrets*: `DEPLOY_HOST` (`IP_DEL_SERVIDOR`), `DEPLOY_USER` (`deploy`), `DEPLOY_PATH` (`/var/www/tunesa`),
     `DEPLOY_SSH_KEY` (la llave privada completa), `DEPLOY_KNOWN_HOSTS` (la salida de `ssh-keyscan`) y,
     si no usas el 22, `DEPLOY_PORT`.
   - *Variables*: `DEPLOY_MODE` = `actions`. Sin esta variable el workflow se salta solo (no gasta minutos).

   - *Variable* opcional `PHP_FPM_SERVICE` = `php8.3-fpm` para recargar PHP-FPM al final (paso 9).

Desde ese momento cada push a `master` que toque el backend o el panel despliega (los cambios solo de `mobile/`
o `docs/` no). También se puede lanzar a mano: **Actions → Deploy: producción → Run workflow**.

### Opción B: el servidor revisa GitHub cada 2 minutos (sin Actions)

El servidor ya puede leer el repositorio con la deploy key del paso 4. Agrega al crontab de `deploy`
(`sudo crontab -u deploy -e`), debajo del scheduler:

```cron
*/2 * * * * cd /var/www/tunesa && PHP_FPM_SERVICE=php8.3-fpm bash scripts/deploy.sh --if-changed >> storage/logs/deploy.log 2>&1
```

`--if-changed` solo hace `git fetch`; si `origin/master` no cambió termina sin tocar nada. Un push queda en
producción en máximo 2 minutos. No usa minutos de GitHub ni abre puertos. Si un día activas la Opción A, quita
esta línea (el script tiene un candado y nunca corre dos despliegues a la vez, pero no tiene sentido duplicarlos).

Historial: `tail -f /var/www/tunesa/storage/logs/deploy.log`.

Si un despliegue falla (por ejemplo, una migración con error), el script vuelve solo al commit anterior, saca la
app de mantenimiento y anota el commit fallido en `storage/framework/deploy.failed`: el cron no lo reintenta cada
2 minutos. Se vuelve a intentar al subir un commit nuevo o al correr `bash scripts/deploy.sh` a mano.

### Volver a una versión anterior

```bash
cd /var/www/tunesa
git log --oneline -10
bash scripts/deploy.sh --ref <commit>
```

Con la Opción B el cron volvería a subir `master` a los 2 minutos: para un rollback que dure, revierte el commit
en GitHub (`git revert`) y haz push. Las migraciones ya aplicadas no se deshacen solas.

## 11. Verificación final

```bash
cd /var/www/tunesa
php artisan app:check-production     # APP_DEBUG, HTTPS, cookie segura, cola, correo y base de datos
php artisan about --only=environment,cache,drivers
curl -I https://group-cyp.com/up
```

- Entra al panel como Super Admin, cambia la contraseña y crea la empresa.
- En **Configuración → Marca y app** de la empresa, "URL base de la API" puede quedar vacía (se usa `APP_URL`).
- Marca una asistencia desde la app (ver sección 13) y revisa que aparezca en el panel.

Respaldo diario de la base (recomendado):

```cron
30 3 * * * mysqldump --single-transaction -u tunesa -p'CLAVE_MYSQL' tunesa | gzip > /home/deploy/backups/tunesa-$(date +\%F).sql.gz
```

(crea antes `/home/deploy/backups` y borra los viejos de vez en cuando; mejor aún, guarda la clave en
`~/.my.cnf` con `chmod 600` en lugar de escribirla en el crontab).

## 12. Si usas un panel de hosting (cPanel, Hostinger, Plesk)

- **Raíz del dominio**: apúntala a la carpeta `public/` del proyecto (no a la raíz).
- **PHP**: elige 8.3 con las extensiones del paso 2 en el selector de PHP.
- **MySQL**: crea base y usuario con el asistente del panel y usa esos datos en el `.env`. Muchos hostings
  compartidos **no** permiten crear triggers (no puedes cambiar `log_bin_trust_function_creators`): si
  `migrate` falla con el error 1419, pide al soporte que lo active o usa un VPS.
- **Sin SSH**: sube el proyecto con `vendor/` y `public/build` ya incluidos y abre `https://group-cyp.com/install`:
  el asistente pide los datos de MySQL, crea las tablas y el Super Admin. En ese caso no hay deploy automático.
- **Con SSH** (Terminal del panel): sigue los pasos 4–7 y la Opción B con el "Cron Jobs" del panel. Si el
  hosting no tiene Node, compila en tu PC (`npm ci && npm run build`), sube `public/build` y usa `SKIP_NPM=1`
  en la línea del cron.

## 13. App móvil: apuntar al dominio de producción

La app no necesita recompilarse para cambiar de servidor, pero conviene que el APK que distribuyas ya traiga
el dominio final.

**APK nuevo con el dominio de producción** (recomendado para Play Store o para repartir el APK):

1. `mobile/env/prod.json` ya tiene `"API_BASE_URL": "https://group-cyp.com"`.
2. Compila: `cd mobile && scripts/build_apk.sh prod release`.
   En GitHub se compila solo en cada push a `master` que toque `mobile/` (entorno `prod`, release), o a mano en
   **Actions → App móvil: APK → Run workflow**. Un tag `v1.0.0` adjunta el APK y el `.aab` al Release.
   Para un APK de producción de verdad hacen falta los secrets de firma `ANDROID_KEYSTORE_BASE64`,
   `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS` y `ANDROID_KEY_PASSWORD`; sin ellos sale firmado con la llave
   debug (sirve para probar, no para Play Store) y un tag `v*` falla a propósito.
   Todo esto necesita minutos de Actions; el build de iOS (macOS) cuenta 10 veces.
3. iOS: el `.ipa` usa el mismo `env/prod.json` (Run workflow con "Compilar iOS" marcado, o `flutter build ipa --dart-define-from-file=env/prod.json` en una Mac).

**Apps ya instaladas (sin reinstalar)**:

- En el login: **"¿Otra empresa o servidor? Conectar"** → escribir `group-cyp.com` (o el código de la empresa).
  La app prueba `https://group-cyp.com/api/v1/app/config`, toma la marca y queda apuntando ahí.
- Si el servidor viejo (el túnel de Cloudflare) sigue encendido: en ese panel, **Marca y app → URL base de la
  API** = `https://group-cyp.com`. Las apps que abran contra el túnel reciben el dominio nuevo, comprueban que
  responde y se cambian solas. Cuando cierres el túnel, ya no podrán enterarse: avisa con tiempo.

Un APK de `release` exige `https://`; no apuntes una build de producción a una URL `http://`.

## Detrás de Cloudflare (nube naranja)

1. SSL/TLS en modo **Full (strict)** (el servidor ya tiene certificado de Let's Encrypt).
2. En el `.env`: `TRUSTED_PROXIES=*` (Laravel ve la IP real del usuario y el `https` original) y
   `php artisan config:cache`.
3. Para el comodín `*.group-cyp.com` con nube naranja necesitas el certificado de borde de Cloudflare
   (incluido en el plan gratis para un nivel de subdominio).
