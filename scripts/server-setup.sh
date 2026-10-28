#!/usr/bin/env bash
# Configuración del servidor que la web (/install) no puede hacer: tareas cron, permiso para recargar
# PHP-FPM y dueño de storage/. Se corre como root, UNA vez por servidor, y otra vez cada vez que
# este archivo cambie (el despliegue avisa). Correrlo de nuevo no duplica nada.
#
#   sudo bash scripts/server-setup.sh            # aplica
#   sudo bash scripts/server-setup.sh --dry-run  # solo muestra lo que haría
#
# Variables opcionales: APP_USER (dueño, por defecto el de la carpeta), PHP_FPM_SERVICE (ej. php8.2-fpm).
#
# REGLA PARA QUIEN AGREGUE COSAS: una tarea periódica nueva va en routes/console.php (Schedule::…),
# NUNCA como línea de cron nueva: la línea de schedule:run ya las corre todas. Solo si el servidor
# necesita algo que Laravel no puede hacer (otro cron, un servicio, un permiso) se agrega aquí, en
# CRON_LINES o como un paso nuevo, y se anota en docs/deployment/produccion-servidor.md.
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DRY=0
[[ "${1:-}" == "--dry-run" ]] && DRY=1
[[ "${1:-}" == "-h" || "${1:-}" == "--help" ]] && { sed -n '2,13p' "$0"; exit 0; }

ok()   { printf '  \e[32m✔\e[0m %s\n' "$*"; }
warn() { printf '  \e[33m!\e[0m %s\n' "$*"; WARNINGS=$((WARNINGS + 1)); }
fail() { printf '  \e[31m✘\e[0m %s\n' "$*" >&2; exit 1; }
WARNINGS=0

[[ $EUID -eq 0 ]] || fail "Córrelo como root: sudo bash scripts/server-setup.sh"
[[ -f "$APP_DIR/artisan" ]] || fail "$APP_DIR no es la carpeta del proyecto."

APP_USER="${APP_USER:-$(stat -c %U "$APP_DIR")}"
[[ "$APP_USER" != root ]] || fail "La carpeta es de root. Debe ser de un usuario normal (ej. chown -R cyp: $APP_DIR) y volver a correr."
APP_GROUP="$(id -gn "$APP_USER")"
as_app() { sudo -u "$APP_USER" -H bash -lc "cd '$APP_DIR' && $*"; }

echo "Proyecto: $APP_DIR   dueño: $APP_USER"
[[ $DRY -eq 1 ]] && echo "(--dry-run: no se cambia nada)"

# 1. Programas que usan el despliegue y la app
echo "1. Programas"
for bin in php composer git flock; do
  as_app "command -v $bin" >/dev/null || fail "Falta $bin para $APP_USER."
done
ok "php $(as_app 'php -r "echo PHP_VERSION;"'), composer, git"
as_app "command -v npm" >/dev/null && ok "npm" || warn "Falta npm: el deploy no podrá compilar el panel (o usa SKIP_NPM=1 en la línea del cron)."
command -v mysqldump >/dev/null && ok "mysqldump" || warn "Falta mysqldump: el deploy no migrará sin respaldo (apt install mysql-client)."
[[ -f "$APP_DIR/.env" ]] && ok ".env" || warn "Falta .env: abre /install en el navegador o copia .env.production.example."

# 2. Dueño de storage/ y bootstrap/cache (un comando corrido como root deja archivos que el sitio ya no puede escribir)
echo "2. Permisos"
WRONG="$(find "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" ! -user "$APP_USER" 2>/dev/null | wc -l)"
if [[ $WRONG -eq 0 ]]; then
  ok "storage/ y bootstrap/cache son de $APP_USER"
elif [[ $DRY -eq 1 ]]; then
  warn "$WRONG archivos de storage/ o bootstrap/cache no son de $APP_USER (se corregirían)"
else
  find "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" ! -user "$APP_USER" -exec chown "$APP_USER:$APP_GROUP" {} +
  ok "$WRONG archivos devueltos a $APP_USER"
fi

# 3. PHP-FPM que hay que recargar tras cada deploy (limpia OPcache)
echo "3. PHP-FPM"
PHPV="$(as_app 'php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;"')"
FPM="${PHP_FPM_SERVICE:-php$PHPV-fpm}"
if systemctl list-unit-files "$FPM.service" >/dev/null 2>&1 && systemctl cat "$FPM" >/dev/null 2>&1; then
  ok "servicio $FPM"
  SUDOERS="/etc/sudoers.d/$(echo "$APP_USER" | tr -c 'a-zA-Z0-9_\n' '_')-deploy"
  RULE="$APP_USER ALL=(root) NOPASSWD: $(command -v systemctl) reload $FPM"
  if [[ -f "$SUDOERS" ]] && grep -qxF "$RULE" "$SUDOERS"; then
    ok "$APP_USER ya puede recargar $FPM"
  elif [[ $DRY -eq 1 ]]; then
    warn "se crearía $SUDOERS: $RULE"
  else
    TMP="$(mktemp)"; echo "$RULE" >"$TMP"
    visudo -cf "$TMP" >/dev/null || { rm -f "$TMP"; fail "La regla de sudoers no es válida; no se tocó nada."; }
    install -m 440 -o root -g root "$TMP" "$SUDOERS"; rm -f "$TMP"
    ok "$APP_USER puede recargar $FPM sin contraseña (solo eso): $SUDOERS"
  fi
else
  FPM=""
  warn "No encontré php$PHPV-fpm. Si el sitio usa otro, vuelve a correr con PHP_FPM_SERVICE=nombre."
fi

# 4. Cron del dueño: un bloque entre marcas que se reemplaza entero en cada corrida.
#    Lo de fuera del bloque (otros sistemas del servidor) no se toca.
echo "4. Tareas cron de $APP_USER"
CRON_LINES=(
  # Todas las tareas de routes/console.php (faltas, recordatorios, cola de push, comunicados...)
  "* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
  # Cada push a master queda en producción en máximo 2 minutos
  "*/2 * * * * cd $APP_DIR && ${FPM:+PHP_FPM_SERVICE=$FPM }bash scripts/deploy.sh --if-changed >> storage/logs/deploy.log 2>&1"
)
BEGIN="# >>> app-tunesa ($APP_DIR) · generado por scripts/server-setup.sh, no editar a mano"
END="# <<< app-tunesa ($APP_DIR)"
CURRENT="$(crontab -u "$APP_USER" -l 2>/dev/null || true)"
OTHERS="$(awk -v b="$BEGIN" -v e="$END" '$0==b{skip=1;next} $0==e{skip=0;next} !skip' <<<"$CURRENT")"
# Líneas sueltas de versiones anteriores de la guía (mismo proyecto, fuera del bloque): se reemplazan por el bloque
OTHERS="$(grep -vE "cd $APP_DIR && (php artisan schedule:run|.*scripts/deploy\.sh)" <<<"$OTHERS" || true)"
NEW="$(printf '%s\n' "$OTHERS" | sed '/^$/d'; echo "$BEGIN"; printf '%s\n' "${CRON_LINES[@]}"; echo "$END")"
if [[ "$(sed '/^$/d' <<<"$CURRENT")" == "$NEW" ]]; then
  ok "ya están al día (${#CRON_LINES[@]} líneas)"
elif [[ $DRY -eq 1 ]]; then
  warn "el crontab de $APP_USER quedaría así:"; sed 's/^/      /' <<<"$NEW"
else
  crontab -u "$APP_USER" - <<<"$NEW"
  ok "instaladas (${#CRON_LINES[@]} líneas); crontab -u $APP_USER -l para verlas"
fi

# 5. MySQL con registro binario: la app protege tablas con triggers (p. ej. la bitácora de
#    marcaciones) y MySQL solo deja crearlos con log_bin_trust_function_creators=1 (o SUPER).
#    Solo si la base es local; se lee el tipo y el host del .env, nunca la contraseña.
echo "5. MySQL"
env_get() { grep -E "^$1=" "$APP_DIR/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '"' | tr -d "'"; }
DB_CONN="$(env_get DB_CONNECTION)"; DB_HOST_ENV="$(env_get DB_HOST)"
MYCNF_DIR="/etc/mysql/conf.d"
if [[ "$DB_CONN" != "mysql" && "$DB_CONN" != "mariadb" ]]; then
  ok "la app no usa MySQL (${DB_CONN:-sin DB_CONNECTION}): nada que hacer"
elif [[ -n "$DB_HOST_ENV" && "$DB_HOST_ENV" != "127.0.0.1" && "$DB_HOST_ENV" != "localhost" ]]; then
  warn "MySQL está en otro servidor ($DB_HOST_ENV): activa allí log_bin_trust_function_creators=1 (o su equivalente del proveedor)."
elif ! VARS="$(mysql -NBe 'SELECT @@log_bin, @@log_bin_trust_function_creators' 2>/dev/null)"; then
  warn "No pude entrar a MySQL como root. Corre: mysql -u root -p -e \"SET GLOBAL log_bin_trust_function_creators = 1;\" y agrega log_bin_trust_function_creators = 1 en [mysqld]."
else
  read -r LOG_BIN TRUST <<<"$VARS"
  if [[ "$LOG_BIN" != "1" || "$TRUST" == "1" ]]; then
    ok "MySQL ya deja crear triggers (registro binario: $LOG_BIN, permiso: $TRUST)"
  elif [[ $DRY -eq 1 ]]; then
    warn "se activaría log_bin_trust_function_creators=1 (ahora y en $MYCNF_DIR/zz-app-tunesa.cnf)"
  else
    mysql -e "SET GLOBAL log_bin_trust_function_creators = 1;"
    if [[ -d "$MYCNF_DIR" ]]; then
      printf '[mysqld]\n# app-tunesa: permite a la app crear sus triggers con el registro binario activo\nlog_bin_trust_function_creators = 1\n' >"$MYCNF_DIR/zz-app-tunesa.cnf"
      ok "log_bin_trust_function_creators=1 activo y permanente ($MYCNF_DIR/zz-app-tunesa.cnf)"
    else
      warn "Activado hasta el próximo reinicio de MySQL; agrega log_bin_trust_function_creators = 1 en [mysqld] de su configuración."
    fi
  fi
fi

# Huella de esta versión: deploy.sh avisa si el archivo cambió y hay que volver a correrlo
if [[ $DRY -eq 0 ]]; then
  sha256sum "$0" | cut -d' ' -f1 >"$APP_DIR/storage/framework/server-setup.sha"
  chown "$APP_USER:$APP_GROUP" "$APP_DIR/storage/framework/server-setup.sha"
fi

echo
[[ $WARNINGS -eq 0 ]] && echo "Servidor listo." || echo "Listo, con $WARNINGS aviso(s) arriba."
