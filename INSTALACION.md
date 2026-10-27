# Instalación en un servidor nuevo

El sistema se instala vacío (sin empresas ni datos de prueba) y toma solo el dominio con el que se
abre el instalador. No hay que copiar ni editar archivos de otro servidor.

## Lo que necesitas

- Un VPS con Ubuntu 22.04 o 24.04 (2 GB de RAM) y acceso root por SSH.
- Un dominio apuntando a la IP del servidor (registro A, y `www` si lo usarás).
- Acceso de lectura al repositorio (deploy key de GitHub).

## Pasos

**1. Programas del servidor** (como root):

```bash
apt update && apt -y install nginx mysql-server git unzip acl certbot python3-certbot-nginx \
  php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-gd php8.2-zip php8.2-bcmath php8.2-intl
curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt -y install nodejs
```

Si `php8.2` no aparece: `add-apt-repository -y ppa:ondrej/php && apt update` y repite.

**2. Base de datos vacía** (cambia `CLAVE`):

```bash
mysql -e "CREATE DATABASE erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'erp'@'localhost' IDENTIFIED BY 'CLAVE'; GRANT ALL ON erp.* TO 'erp'@'localhost';"
printf '[mysqld]\nlog_bin_trust_function_creators = 1\n' > /etc/mysql/mysql.conf.d/erp.cnf && systemctl restart mysql
```

La segunda línea es obligatoria: una tabla usa un trigger y MySQL lo rechaza sin ese ajuste.

**3. Código** (cambia `midominio.com` por tu dominio; `erp` es el usuario dueño del sistema):

```bash
adduser --disabled-password --gecos "" erp && mkdir -p /var/www/midominio.com && chown erp: /var/www/midominio.com
sudo -u erp -H bash -c 'git clone git@github.com:lokitoweb/app-tunesa.git /var/www/midominio.com/app && cd /var/www/midominio.com/app && composer install --no-dev --optimize-autoloader && npm ci && npm run build'
```

**4. Nginx y HTTPS:** el bloque de configuración está en
[docs/deployment/produccion-servidor.md](docs/deployment/produccion-servidor.md#8-nginx-y-https)
(raíz: `/var/www/midominio.com/app/public`). Luego `certbot --nginx -d midominio.com -d www.midominio.com --redirect`.

**5. Instalador:** abre `https://midominio.com/install` (con el dominio real y `https`, no con la IP).
Pide los datos de la base del paso 2, crea las tablas, roles y permisos, y el administrador.
El dominio, la dirección del sitio y la cookie segura se configuran solos con la dirección que usaste.

**6. Configuración del servidor** (como root, una vez):

```bash
cd /var/www/midominio.com/app && sudo bash scripts/server-setup.sh
```

Deja las tareas programadas (faltas, recordatorios, avisos) y el despliegue automático: cada push a
`master` queda en producción en máximo 2 minutos.

**7. Comprobar:**

```bash
sudo -u erp -H bash -c 'cd /var/www/midominio.com/app && php artisan app:check-production'
```

Sin errores, el sistema está listo. El único aviso esperado al inicio es el del correo.

## Correo (recuperación de contraseña y cotizaciones a proveedores)

En `/var/www/midominio.com/app/.env` completa `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` con los datos de tu proveedor de correo y corre
`sudo -u erp -H bash -c 'cd /var/www/midominio.com/app && php artisan config:cache'`.

## Si algo falla

| Síntoma | Causa |
|---|---|
| El paso 1 del instalador sale en rojo | Falta una extensión de PHP o permisos de escritura: el instalador dice cuál |
| La migración falla con el error 1419 | Falta el ajuste `log_bin_trust_function_creators` del paso 2 |
| `check-production` dice que las tareas programadas no corren | Falta el paso 6 |
| Los cambios no llegan solos | Falta el paso 6, o revisa `storage/logs/deploy.log` |

Guía técnica completa (Cloudflare, hosting compartido, app móvil, volver a una versión):
[docs/deployment/produccion-servidor.md](docs/deployment/produccion-servidor.md).
