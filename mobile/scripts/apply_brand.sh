#!/usr/bin/env bash
# Aplica un kit de marca (mobile/branding/<kit>/) antes de compilar: copia sus íconos, pantalla de
# arranque y logo encima de la app y deja el nombre para Android (dart-define APP_NAME) e iOS
# (ios/Flutter/Brand.xcconfig). Lo llama scripts/build_apk.sh con el "BRAND" de env/<entorno>.json.
#
#   scripts/apply_brand.sh grupo-negreiros
#   scripts/apply_brand.sh default          # marca neutra (la que queda en el repositorio)
#
# Un cliente nuevo: copia branding/default a branding/<cliente>, reemplaza las imágenes (mismas
# medidas) y el nombre en brand.json, y pon "BRAND": "<cliente>" en su env/<entorno>.json.
# El identificador de la app (com.tunesa.mobile) NO cambia: lo usa Firebase para los avisos push.
set -euo pipefail

KIT="${1:-default}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIR="$ROOT/branding/$KIT"

[[ "$KIT" =~ ^[a-z0-9-]+$ ]] || { echo "Nombre de kit inválido: $KIT" >&2; exit 1; }
[[ -f "$DIR/brand.json" ]] || { echo "No existe el kit $DIR (kits: $(ls "$ROOT/branding" | tr '\n' ' '))" >&2; exit 1; }

APP_NAME="$(sed -n 's/.*"APP_NAME": *"\([^"]*\)".*/\1/p' "$DIR/brand.json")"
[[ -n "$APP_NAME" ]] || { echo "Falta APP_NAME en $DIR/brand.json" >&2; exit 1; }
[[ "$APP_NAME" != *[\"\\\$\;]* ]] || { echo "APP_NAME no puede tener comillas, \\, \$ ni ;" >&2; exit 1; }

# Imágenes: el kit replica las rutas de la app
(cd "$DIR" && find . -type f ! -name brand.json -print0) | while IFS= read -r -d '' f; do
  mkdir -p "$ROOT/$(dirname "$f")"
  cp "$DIR/$f" "$ROOT/$f"
done

# iOS: Info.plist usa $(APP_DISPLAY_NAME)
printf '// Generado por scripts/apply_brand.sh (kit %s)\nAPP_DISPLAY_NAME=%s\n' "$KIT" "$APP_NAME" > "$ROOT/ios/Flutter/Brand.xcconfig"

echo "Marca aplicada: $KIT ($APP_NAME)"
