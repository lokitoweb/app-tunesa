#!/usr/bin/env bash
# Compila el APK de TuneSA.
#
#   scripts/build_apk.sh                  # release contra env/prod.json
#   scripts/build_apk.sh staging          # release contra env/staging.json
#   scripts/build_apk.sh dev debug        # debug contra el backend local (http)
#   API_BASE_URL=https://api.cliente.pe scripts/build_apk.sh prod   # otro dominio
#   API_BASE_URL=https://xxxx.trycloudflare.com scripts/build_apk.sh tunnel   # túnel de pruebas
#   FIREBASE_API_KEY=... FIREBASE_APP_ID=... scripts/build_apk.sh prod   # push (ver README)
#
# Firma: si existe android/key.properties usa la llave de producción. Sin ella,
# un release de prod FALLA; staging/tunnel/dev se firman con la llave debug
# (sirve para pruebas, no para Play Store) y se muestra un aviso.
# ALLOW_DEBUG_SIGNING=1 permite un APK prod de prueba firmado con la llave debug
# (lo usa el CI en PRs sin secrets; nunca para distribuir).
set -euo pipefail

ENV_NAME="${1:-prod}"
MODE="${2:-release}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="env/${ENV_NAME}.json"

cd "$ROOT"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "No existe $ENV_FILE. Entornos disponibles: $(ls env | sed 's/\.json//' | tr '\n' ' ')" >&2
  exit 1
fi
if [[ "$MODE" != "release" && "$MODE" != "debug" ]]; then
  echo "Modo inválido: $MODE (usa release o debug)" >&2
  exit 1
fi
if [[ "$MODE" == "release" ]] && grep -q '"API_BASE_URL": *"http://' "$ENV_FILE" && [[ -z "${API_BASE_URL:-}" ]]; then
  echo "Aviso: $ENV_FILE usa http://. Android bloquea tráfico sin cifrar en release; usa https o el modo debug." >&2
fi

if grep -q 'CAMBIA-ESTE-SUBDOMINIO' "$ENV_FILE" && [[ -z "${API_BASE_URL:-}" ]]; then
  echo "Falta la URL del túnel: edita API_BASE_URL en $ENV_FILE o compila con API_BASE_URL=https://xxxx.trycloudflare.com" >&2
  exit 1
fi

EXTRA_DEFINES=()
if [[ -n "${API_BASE_URL:-}" ]]; then
  EXTRA_DEFINES+=("--dart-define=API_BASE_URL=${API_BASE_URL}")
fi
# Push (Firebase sin google-services.json): solo los valores no vacíos del
# entorno sobrescriben los del JSON. Sin ellos la app compila con el push apagado.
for VAR in FIREBASE_API_KEY FIREBASE_APP_ID FIREBASE_MESSAGING_SENDER_ID FIREBASE_PROJECT_ID FIREBASE_IOS_APP_ID FIREBASE_IOS_BUNDLE_ID; do
  if [[ -n "${!VAR:-}" ]]; then
    EXTRA_DEFINES+=("--dart-define=${VAR}=${!VAR}")
  fi
done
if [[ ! -f android/key.properties && "$MODE" == "release" ]]; then
  if [[ "$ENV_NAME" == "prod" && "${ALLOW_DEBUG_SIGNING:-0}" != "1" ]]; then
    echo "Error: un release de producción exige android/key.properties (llave de firma de la empresa)." >&2
    echo "Ver la sección 'Firma del APK' de mobile/README.md. Para pruebas usa: scripts/build_apk.sh staging|tunnel|dev" >&2
    exit 1
  fi
  echo "Aviso: sin android/key.properties, el APK de ${ENV_NAME} se firma con la llave debug (solo para pruebas)." >&2
fi

flutter --version
flutter pub get
flutter analyze
flutter test

flutter build apk "--${MODE}" --dart-define-from-file="$ENV_FILE" ${EXTRA_DEFINES[@]+"${EXTRA_DEFINES[@]}"}

VERSION="$(grep '^version:' pubspec.yaml | awk '{print $2}')"
OUT_DIR="$ROOT/build/dist"
OUT="$OUT_DIR/tunesa-${VERSION%%+*}-${ENV_NAME}-${MODE}.apk"
mkdir -p "$OUT_DIR"
cp "build/app/outputs/flutter-apk/app-${MODE}.apk" "$OUT"
echo
echo "APK listo: $OUT"
