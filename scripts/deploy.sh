#!/usr/bin/env bash
# Despliegue en el servidor de producción (se ejecuta DENTRO del servidor, en la carpeta del proyecto).
#
#   bash scripts/deploy.sh                 # trae origin/master y despliega
#   bash scripts/deploy.sh --ref <sha>     # despliega ese commit (volver a una versión anterior)
#   bash scripts/deploy.sh --if-changed    # solo si origin/master cambió (modo cron, sin GitHub Actions)
#   bash scripts/deploy.sh --force         # vuelve a correr todo aunque no haya cambios
#
# Variables opcionales (en el entorno o en el crontab):
#   DEPLOY_BRANCH=master        rama que se despliega
#   PHP_BIN=php                 binario de PHP (ej. /usr/bin/php8.3)
#   PHP_FPM_SERVICE=php8.3-fpm  servicio a recargar al final para limpiar OPcache (necesita sudo sin contraseña)
#   SKIP_NPM=1                  no compilar el panel (si public/build llega por otro medio)
#
# Pasos: git → composer (sin dev) → npm ci + npm run build (solo si cambió el frontend) → modo mantenimiento →
# migrate --force → storage:link → config/route/view/event:cache → queue:restart → fuera de mantenimiento.
# Si algo falla vuelve al commit anterior (el código; una migración ya aplicada no se deshace sola).
set -Eeuo pipefail

# Todo va dentro de main(): bash lee la función completa antes de ejecutarla, así que el `git reset`
# que reemplaza este mismo archivo a mitad del despliegue no lo corrompe.
main() {
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

BRANCH="${DEPLOY_BRANCH:-master}"
PHP="${PHP_BIN:-php}"
REF=""
IF_CHANGED=0
FORCE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --ref) REF="${2:-}"; shift 2 ;;
    --if-changed) IF_CHANGED=1; shift ;;
    --force) FORCE=1; shift ;;
    -h|--help) sed -n '2,18p' "$0"; exit 0 ;;
    *) echo "Opción desconocida: $1" >&2; exit 2 ;;
  esac
done

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }

[[ -f .env ]] || { echo "Falta .env en $APP_DIR (copia .env.production.example y complétalo)." >&2; exit 1; }
[[ -d .git ]] || { echo "$APP_DIR no es un clon de git." >&2; exit 1; }

# Un solo despliegue a la vez (el cron y una corrida a mano pueden coincidir)
if [[ -z "${DEPLOY_LOCK_HELD:-}" ]]; then
  exec 9>"$APP_DIR/storage/framework/deploy.lock"
  if ! flock -n 9; then
    log "Otro despliegue está en curso; se omite este."
    exit 0
  fi
fi

if [[ -n "${DEPLOY_PREVIOUS:-}" ]]; then
  # Segunda pasada: la versión nueva de este script, con el código ya en TARGET
  PREVIOUS="$DEPLOY_PREVIOUS"
  TARGET="$(git rev-parse HEAD)"
else
  git fetch --quiet origin "$BRANCH"
  TARGET="$(git rev-parse "${REF:-origin/$BRANCH}^{commit}")"
  PREVIOUS="$(git rev-parse HEAD)"
fi

if [[ -z "${DEPLOY_PREVIOUS:-}" && "$TARGET" == "$PREVIOUS" && $FORCE -eq 0 && -d vendor && ( -f public/build/manifest.json || -n "${SKIP_NPM:-}" ) ]]; then
  [[ $IF_CHANGED -eq 1 ]] || log "Ya está en ${TARGET:0:7}; nada que desplegar (usa --force para repetir)."
  exit 0
fi

# Un commit que ya falló no se reintenta en cada vuelta del cron (evita entrar y salir de mantenimiento);
# un commit nuevo o una corrida a mano (sin --if-changed) sí lo intentan.
FAILED_MARK="$APP_DIR/storage/framework/deploy.failed"
if [[ $IF_CHANGED -eq 1 && -z "${DEPLOY_PREVIOUS:-}" && -f "$FAILED_MARK" && "$(cat "$FAILED_MARK")" == "$TARGET" ]]; then
  exit 0
fi

log "Desplegando ${PREVIOUS:0:7} → ${TARGET:0:7} ($BRANCH)"

# Borra solo lo compilado (config, rutas, vistas, eventos). No usa optimize:clear: vaciaría la caché
# de la app (bloqueos de login, límites) y en el primer deploy falla porque la tabla cache aún no existe.
clear_compiled() {
  "$PHP" artisan config:clear
  "$PHP" artisan route:clear
  "$PHP" artisan view:clear
  "$PHP" artisan event:clear
}

IN_MAINTENANCE=0
BUILT_FRONTEND=0
rollback() {
  local status=$?
  trap - ERR
  log "ERROR (código $status). Volviendo a ${PREVIOUS:0:7}…"
  if [[ "$TARGET" != "$PREVIOUS" ]]; then
    git reset --quiet --hard "$PREVIOUS" || true
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --quiet || true
    if [[ -z "${SKIP_NPM:-}" && $BUILT_FRONTEND -eq 1 ]]; then
      { npm ci --no-audit --no-fund --silent && npm run build --silent; } || true
    fi
  fi
  echo "$TARGET" > "$FAILED_MARK" || true
  clear_compiled >/dev/null 2>&1 || true
  "$PHP" artisan config:cache >/dev/null 2>&1 || true
  [[ $IN_MAINTENANCE -eq 0 ]] || "$PHP" artisan up || true
  log "Despliegue fallido. Revisa el error de arriba; si falló una migración, corrígela antes de reintentar."
  log "El cron no reintentará ${TARGET:0:7}; corre bash scripts/deploy.sh a mano o sube un commit nuevo."
  exit "$status"
}
trap rollback ERR

# Archivos que cambian entre los dos commits (para no recompilar el panel si no hace falta)
CHANGED="$(git diff --name-only "$PREVIOUS" "$TARGET" 2>/dev/null || echo '*')"

# El servidor es solo de despliegue: cualquier edición local en archivos versionados se descarta.
# .env, storage/ y vendor/ no están en git y no se tocan.
if [[ -z "${DEPLOY_PREVIOUS:-}" ]]; then
  git reset --quiet --hard "$TARGET"
  # Si el commit trae otra versión de este script, sigue con la nueva (conserva el candado)
  if ! git diff --quiet "$PREVIOUS" "$TARGET" -- scripts/deploy.sh; then
    log "scripts/deploy.sh cambió: continúa la versión nueva"
    trap - ERR
    DEPLOY_LOCK_HELD=1 DEPLOY_PREVIOUS="$PREVIOUS" exec bash "$APP_DIR/scripts/deploy.sh" $([[ $FORCE -eq 1 ]] && echo --force)
  fi
fi

log "composer install (sin dependencias de desarrollo)"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress

if [[ -z "${SKIP_NPM:-}" ]]; then
  if [[ ! -f public/build/manifest.json || $FORCE -eq 1 || "$CHANGED" == '*' ]] \
     || grep -qE '^(resources/(js|css)/|package(-lock)?\.json$|vite\.config\.js$)' <<<"$CHANGED"; then
    log "npm ci + npm run build (panel web)"
    BUILT_FRONTEND=1
    npm ci --no-audit --no-fund
    npm run build
  else
    log "Frontend sin cambios: se conserva public/build"
  fi
fi

log "Modo mantenimiento"
"$PHP" artisan down --retry=15 --refresh=15 || true
IN_MAINTENANCE=1

clear_compiled
"$PHP" artisan migrate --force
[[ -e public/storage ]] || "$PHP" artisan storage:link || true

log "Cachés de producción"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache

# Los workers (si hay queue:work permanente) toman el código nuevo
"$PHP" artisan queue:restart

"$PHP" artisan up
IN_MAINTENANCE=0
trap - ERR
rm -f "$FAILED_MARK"

# OPcache guarda el PHP viejo hasta recargar PHP-FPM
if [[ -n "${PHP_FPM_SERVICE:-}" ]]; then
  sudo -n systemctl reload "$PHP_FPM_SERVICE" && log "$PHP_FPM_SERVICE recargado" \
    || log "AVISO: no se pudo recargar $PHP_FPM_SERVICE (revisa la regla de sudoers de la guía)."
fi

"$PHP" artisan app:check-production || log "AVISO: app:check-production encontró problemas (ver arriba)."

log "Listo: ${TARGET:0:7} en producción."
}

main "$@"
exit
