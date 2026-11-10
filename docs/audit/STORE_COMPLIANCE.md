# Cumplimiento de tiendas (fase 1)

Estados: Cumplido · Parcial · Pendiente · No aplicable · No verificado. Nada se marca "Cumplido" sin evidencia.
Los requisitos vigentes de Google Play y Apple deben contrastarse con su documentación oficial al cerrar el
bloque 6 (no consultada todavía en esta fase).

## Google Play

| Requisito | Estado | Evidencia / nota |
|---|---|---|
| Android App Bundle (AAB) | Cumplido | `scripts/build_apk.sh:149` en modo release |
| Target SDK vigente | No verificado | Viene de Flutter; CI exige ≥ 36 (`scripts/check_play_requirements.py`) |
| Alineación 16 KB de bibliotecas nativas | Parcial | CI lo revisa; no ejecutado en esta fase |
| Firma de producción | **Pendiente** | GitHub Actions sin `ANDROID_KEYSTORE_*`: CI firma con llave de depuración |
| Permisos justificados | Cumplido | Ubicación solo en uso, cámara, notificaciones; audio y almacenamiento quitados |
| Ubicación en segundo plano | No aplicable | No se pide |
| Política de privacidad (URL pública) | Cumplido | `/privacidad` (administrable) |
| Eliminación de cuenta (app y web) | Cumplido | App: Privacidad y datos; web: `/eliminar-cuenta` |
| Formulario Data Safety | Pendiente | Lo llena el propietario en Play Console (lista de datos en `SECURITY_AUDIT.md` y la política) |
| Copias de seguridad / extracción de datos | Cumplido | `allowBackup=false` y reglas de extracción |
| Tráfico sin cifrar | Cumplido | Solo en depuración |
| Visibilidad de paquetes (`<queries>` con apps de root) | Parcial | Puede generar preguntas en la revisión |

## Apple App Store

| Requisito | Estado | Evidencia / nota |
|---|---|---|
| `PrivacyInfo.xcprivacy` | Hecho (local, 10/11) | `ios/Runner/PrivacyInfo.xcprivacy` en Resources de Runner: ubicación precisa, fotos, nombre, correo, ID de usuario y de dispositivo; sin rastreo; UserDefaults CA92.1. Prueba `store_readiness_test.dart`. Compilación iOS no verificada (solo en macOS, job manual) |
| Textos de uso (ubicación, cámara, fotos, micrófono) | Cumplido | `Info.plist` |
| Certificado y perfil de distribución App Store | **Pendiente** | Secretos `IOS_*` no cargados; método por defecto ad-hoc |
| Subida a TestFlight | Pendiente | No hay paso de subida en CI |
| App Privacy (App Store Connect) | Pendiente | La llena el propietario |
| Eliminación de cuenta | Cumplido | Igual que Android |
| Cuenta de prueba para el revisor | Pendiente | La app exige inicio de sesión |
| iPad | Decisión | `TARGETED_DEVICE_FAMILY = 1,2` declara iPad: exige capturas de iPad y que funcione en iPad. Retirarlo deja la app solo para iPhone (en iPad se ve en modo compatibilidad) |
| Entitlement de push `aps-environment=development` | No verificado | Debe ser producción con el perfil de distribución |
| Cifrado (`ITSAppUsesNonExemptEncryption=false`) | No verificado | Cifrado local estándar; probablemente exento |
