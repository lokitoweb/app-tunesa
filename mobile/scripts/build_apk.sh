#!/usr/bin/env bash
# Compila el APK de TuneSA.
#
#   scripts/build_apk.sh                  # release contra env/prod.json
#   scripts/build_apk.sh staging          # release contra env/staging.json
#   scripts/build_apk.sh dev debug        # debug contra el backend local (http)
#   API_BASE_URL=https://api.cliente.pe scripts/build_apk.sh prod   # otro dominio
#   API_BASE_URL=https://xxxx.trycloudflare.com scripts/build_apk.sh tunnel   # túnel de pruebas
#   FIREBASE_API_KEY=... FIREBASE_APP_ID=... scripts/build_apk.sh prod   # push (ver README)
#   (en Android, los FIREBASE_* vacíos se toman de android/app/google-services.json)
#   APP_CERT_SHA256=AA:BB:... scripts/build_apk.sh prod   # huella de la llave: detecta APK re-firmados
#   BUILD_AAB=1 scripts/build_apk.sh prod   # además el .aab que se sube a Google Play
#
# En release revisa el APK con scripts/check_play_requirements.py (targetSdk,
# permisos y páginas de 16 KB) y falla si no cumple lo que exige Google Play.
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
for VAR in FIREBASE_API_KEY FIREBASE_APP_ID FIREBASE_MESSAGING_SENDER_ID FIREBASE_PROJECT_ID FIREBASE_IOS_APP_ID FIREBASE_IOS_BUNDLE_ID APP_CERT_SHA256; do
  if [[ -n "${!VAR:-}" ]]; then
    EXTRA_DEFINES+=("--dart-define=${VAR}=${!VAR}")
  fi
done
# Si android/app/google-services.json existe, completa los FIREBASE_* de Android
# que sigan vacíos (ni en el entorno ni en el JSON del entorno).
GS_JSON="android/app/google-services.json"
if [[ -f "$GS_JSON" ]] && command -v python3 >/dev/null 2>&1; then
  while IFS='=' read -r VAR VALUE; do
    if [[ -n "$VALUE" && -z "${!VAR:-}" ]] && grep -q "\"${VAR}\": *\"\"" "$ENV_FILE"; then
      EXTRA_DEFINES+=("--dart-define=${VAR}=${VALUE}")
    fi
  done < <(python3 - "$GS_JSON" <<'PY'
import json, sys
data = json.load(open(sys.argv[1], encoding="utf-8"))
project = data.get("project_info", {})
for client in data.get("client", []):
    info = client.get("client_info", {})
    if info.get("android_client_info", {}).get("package_name") != "com.tunesa.mobile":
        continue
    keys = client.get("api_key") or [{}]
    print("FIREBASE_API_KEY=" + keys[0].get("current_key", ""))
    print("FIREBASE_APP_ID=" + info.get("mobilesdk_app_id", ""))
    print("FIREBASE_MESSAGING_SENDER_ID=" + str(project.get("project_number", "")))
    print("FIREBASE_PROJECT_ID=" + project.get("project_id", ""))
    break
PY
)
  echo "Firebase (Android): valores tomados de $GS_JSON cuando faltaban."
elif [[ -f "$GS_JSON" ]]; then
  echo "Aviso: hay $GS_JSON pero no python3; el push usa solo FIREBASE_* del entorno." >&2
fi
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

VERSION="$(grep '^version:' pubspec.yaml | awk '{print $2}')"

# Release: el código Dart sale ofuscado (nombres de clases y funciones ilegibles
# al descompilar). Los símbolos para leer trazas de errores quedan en
# build/debug-info/<versión> (no van dentro del APK; guárdalos con cada versión):
#   flutter symbolize -i traza.txt -d build/debug-info/<versión>/app.android-arm64.symbols
OBFUSCATE=()
if [[ "$MODE" == "release" ]]; then
  OBFUSCATE=(--obfuscate "--split-debug-info=build/debug-info/${VERSION}")
fi

flutter build apk "--${MODE}" --dart-define-from-file="$ENV_FILE" ${OBFUSCATE[@]+"${OBFUSCATE[@]}"} ${EXTRA_DEFINES[@]+"${EXTRA_DEFINES[@]}"}
OUT_DIR="$ROOT/build/dist"
OUT="$OUT_DIR/tunesa-${VERSION%%+*}-${ENV_NAME}-${MODE}.apk"
mkdir -p "$OUT_DIR"
cp "build/app/outputs/flutter-apk/app-${MODE}.apk" "$OUT"
echo
echo "APK listo: $OUT"

if [[ "$MODE" == "release" ]]; then
  python3 scripts/check_play_requirements.py "$OUT"
fi

# Google Play solo acepta Android App Bundle (.aab) para apps nuevas
if [[ "${BUILD_AAB:-0}" == "1" && "$MODE" == "release" ]]; then
  flutter build appbundle --release --dart-define-from-file="$ENV_FILE" ${OBFUSCATE[@]+"${OBFUSCATE[@]}"} ${EXTRA_DEFINES[@]+"${EXTRA_DEFINES[@]}"}
  AAB="$OUT_DIR/tunesa-${VERSION%%+*}-${ENV_NAME}-release.aab"
  cp build/app/outputs/bundle/release/app-release.aab "$AAB"
  echo "AAB para Google Play: $AAB"
fi
