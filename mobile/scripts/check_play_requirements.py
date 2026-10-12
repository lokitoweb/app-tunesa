#!/usr/bin/env python3
"""Revisa un APK contra los requisitos técnicos de Google Play.

    python3 scripts/check_play_requirements.py build/dist/tunesa-...apk

1. targetSdkVersion >= 36 (obligatorio desde el 31/08/2026 para apps nuevas y
   actualizaciones) — se lee con aapt2 del Android SDK.
2. Solo los permisos que la app justifica en Play Console. Un plugin nuevo que
   agregue, por ejemplo, ubicación en segundo plano, lectura de fotos o
   alarmas exactas hace fallar el CI antes de que Google rechace la versión.
3. Páginas de memoria de 16 KB: cada .so de 64 bits con segmentos LOAD
   alineados a 16 KB (obligatorio para apps con target 35+).

Sale con código 1 si algo no cumple.
"""
import glob
import os
import shutil
import struct
import subprocess
import sys
import zipfile

MIN_TARGET_SDK = 36

# Permisos aceptados (los que declara la app y los que agregan sus plugins).
# Cada uno se justifica en Play Console › Contenido de la app (ver docs/deployment/play-store.md).
ALLOWED_PERMISSIONS = {
    "android.permission.INTERNET",
    "android.permission.ACCESS_NETWORK_STATE",
    "android.permission.POST_NOTIFICATIONS",
    "android.permission.ACCESS_FINE_LOCATION",
    "android.permission.ACCESS_COARSE_LOCATION",
    "android.permission.CAMERA",
    "android.permission.WAKE_LOCK",
    "android.permission.VIBRATE",
    "android.permission.RECEIVE_BOOT_COMPLETED",
    "android.permission.USE_BIOMETRIC",
    "android.permission.USE_FINGERPRINT",
    "com.google.android.c2dm.permission.RECEIVE",
    "com.google.android.finsky.permission.BIND_GET_INSTALL_REFERRER_SERVICE",
    "com.tunesa.mobile.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION",
}

# Permisos que Google Play restringe o exige declarar con formulario propio.
RESTRICTED = {
    "android.permission.ACCESS_BACKGROUND_LOCATION": "ubicación en segundo plano (formulario y video en Play Console)",
    "android.permission.READ_MEDIA_IMAGES": "acceso a fotos (usar el selector de fotos del sistema)",
    "android.permission.READ_MEDIA_VIDEO": "acceso a videos (usar el selector de fotos del sistema)",
    "android.permission.READ_EXTERNAL_STORAGE": "almacenamiento (usar el selector del sistema)",
    "android.permission.WRITE_EXTERNAL_STORAGE": "almacenamiento",
    "android.permission.MANAGE_EXTERNAL_STORAGE": "acceso a todos los archivos",
    "android.permission.SCHEDULE_EXACT_ALARM": "alarmas exactas",
    "android.permission.USE_EXACT_ALARM": "alarmas exactas",
    "android.permission.QUERY_ALL_PACKAGES": "ver todas las apps instaladas",
    "android.permission.REQUEST_INSTALL_PACKAGES": "instalar apps",
    "android.permission.FOREGROUND_SERVICE": "servicio en primer plano (declarar el tipo)",
    "android.permission.READ_PHONE_STATE": "estado del teléfono",
    "android.permission.READ_CONTACTS": "contactos",
    "android.permission.RECORD_AUDIO": "micrófono",
    "android.permission.SYSTEM_ALERT_WINDOW": "mostrar sobre otras apps",
    "android.permission.READ_SMS": "SMS",
    "android.permission.READ_CALL_LOG": "registro de llamadas",
}

PAGE_16K = 16384


def find_aapt2():
    home = os.environ.get("ANDROID_HOME") or os.environ.get("ANDROID_SDK_ROOT") or ""
    candidates = sorted(glob.glob(os.path.join(home, "build-tools", "*", "aapt2")))
    if candidates:
        return candidates[-1]
    return shutil.which("aapt2")


def badging(apk):
    aapt2 = find_aapt2()
    if not aapt2:
        print("::warning::Sin aapt2 (Android SDK): no se revisan targetSdk ni permisos.")
        return None
    return subprocess.run([aapt2, "dump", "badging", apk], check=True, capture_output=True, text=True).stdout


def check_manifest(apk):
    out = badging(apk)
    if out is None:
        return []
    errors = []
    target = None
    perms = set()
    for line in out.splitlines():
        if line.startswith("targetSdkVersion:"):
            target = int(line.split("'")[1])
        elif line.startswith("uses-permission:"):
            perms.add(line.split("name='")[1].split("'")[0])
    print(f"targetSdkVersion: {target}")
    if target is None or target < MIN_TARGET_SDK:
        errors.append(f"targetSdkVersion {target}: Google Play exige {MIN_TARGET_SDK} o más.")

    print("Permisos:")
    for p in sorted(perms):
        print(f"  {p}")
        if p in RESTRICTED:
            errors.append(f"Permiso restringido por Google Play: {p} ({RESTRICTED[p]}).")
        elif p not in ALLOWED_PERMISSIONS:
            errors.append(f"Permiso nuevo sin revisar: {p}. Si es necesario, justifícalo y agrégalo a ALLOWED_PERMISSIONS.")
    return errors


def load_alignments(data):
    """Alineación de cada segmento PT_LOAD de un ELF de 64 bits (little endian)."""
    if data[:4] != b"\x7fELF" or data[4] != 2:
        return None
    phoff = struct.unpack_from("<Q", data, 0x20)[0]
    phentsize, phnum = struct.unpack_from("<HH", data, 0x36)
    aligns = []
    for i in range(phnum):
        off = phoff + i * phentsize
        p_type = struct.unpack_from("<I", data, off)[0]
        if p_type == 1:  # PT_LOAD
            aligns.append(struct.unpack_from("<Q", data, off + 0x30)[0])
    return aligns


def check_16k(apk):
    errors = []
    with zipfile.ZipFile(apk) as z:
        libs = [n for n in z.namelist() if n.startswith(("lib/arm64-v8a/", "lib/x86_64/")) and n.endswith(".so")]
        print(f"Librerías nativas de 64 bits: {len(libs)}")
        for name in libs:
            info = z.getinfo(name)
            aligns = load_alignments(z.read(name))
            if aligns is None:
                continue
            bad = [a for a in aligns if a < PAGE_16K]
            status = "OK" if not bad else "NO ALINEADA"
            print(f"  {status:12} {name} (LOAD align {min(aligns) if aligns else '-'})")
            if bad:
                errors.append(f"{name} no soporta páginas de 16 KB (LOAD align {min(bad)}).")
            if info.compress_type == zipfile.ZIP_STORED and (info.header_offset + 30 + len(info.filename.encode()) + len(info.extra)) % PAGE_16K:
                errors.append(f"{name} va sin comprimir pero no está alineada a 16 KB dentro del APK.")
    return errors


def main():
    if len(sys.argv) != 2:
        print(__doc__)
        return 2
    apk = sys.argv[1]
    errors = check_manifest(apk) + check_16k(apk)
    if errors:
        print("\nNo cumple los requisitos de Google Play:")
        for e in errors:
            print(f"::error::{e}")
        return 1
    print("\nCumple targetSdk, permisos y páginas de 16 KB.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
