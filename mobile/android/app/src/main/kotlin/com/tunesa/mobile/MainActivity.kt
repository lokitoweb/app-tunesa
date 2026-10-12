package com.tunesa.mobile

import android.content.Intent
import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import android.os.Build
import android.os.Debug
import android.net.Uri
import android.provider.Settings
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File
import java.security.MessageDigest

class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        // Canal "tunesa/device_integrity": señales de un teléfono manipulado.
        // La app las manda al servidor (X-Device-Integrity) y RRHH ve la marcación como sospechosa.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "tunesa/device_integrity")
            .setMethodCallHandler { call, result ->
                if (call.method != "check") {
                    result.notImplemented()
                    return@setMethodCallHandler
                }
                result.success(DeviceIntegrity.check(this))
            }

        // Canal "tunesa/notifications": abre los ajustes de notificaciones de la app
        // (Perfil > Notificaciones, cuando el teléfono las tiene bloqueadas).
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "tunesa/notifications")
            .setMethodCallHandler { call, result ->
                if (call.method != "openSettings") {
                    result.notImplemented()
                    return@setMethodCallHandler
                }
                result.success(openNotificationSettings())
            }
    }

    private fun openNotificationSettings(): Boolean {
        val notifications = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, packageName)
        } else {
            null
        }
        val details = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", packageName, null))
        return listOfNotNull(notifications, details).any { intent ->
            runCatching { startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)); true }.getOrDefault(false)
        }
    }
}

/** Revisiones locales y baratas (sin red): root, emulador, depurador, Frida/Xposed y firma del APK. */
object DeviceIntegrity {
    private val suPaths = listOf(
        "/system/bin/su", "/system/xbin/su", "/sbin/su", "/system/su", "/system/bin/.ext/su",
        "/system/usr/we-need-root/su", "/data/local/su", "/data/local/bin/su", "/data/local/xbin/su",
        "/su/bin/su", "/system/app/Superuser.apk", "/sbin/.magisk", "/data/adb/magisk", "/cache/.disable_magisk",
    )

    private val rootPackages = listOf(
        "com.topjohnwu.magisk", "eu.chainfire.supersu", "com.koushikdutta.superuser",
        "com.noshufou.android.su", "com.thirdparty.superuser", "com.kingroot.kinguser",
    )

    private val hookPackages = listOf(
        "de.robv.android.xposed.installer", "org.lsposed.manager", "io.va.exposed", "com.saurik.substrate",
    )

    fun check(activity: FlutterActivity): Map<String, Any?> {
        val pm = activity.packageManager
        val debuggableBuild = (activity.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0

        return mapOf(
            "rooted" to isRooted(pm),
            "emulator" to isEmulator(),
            // En builds debug el depurador es normal
            "debugger" to (!debuggableBuild && (Debug.isDebuggerConnected() || Debug.waitingForDebugger())),
            "hooked" to isHooked(pm),
            "signature" to signatureSha256(activity),
        )
    }

    private fun isRooted(pm: PackageManager): Boolean {
        if (Build.TAGS?.contains("test-keys") == true) return true
        if (suPaths.any { runCatching { File(it).exists() }.getOrDefault(false) }) return true
        return rootPackages.any { installed(pm, it) }
    }

    private fun isEmulator(): Boolean {
        val f = Build.FINGERPRINT.orEmpty()
        val model = Build.MODEL.orEmpty()
        val hw = Build.HARDWARE.orEmpty()
        val product = Build.PRODUCT.orEmpty()
        return f.startsWith("generic") || f.startsWith("unknown") || f.contains("emulator") ||
            model.contains("Emulator") || model.contains("Android SDK built for") ||
            hw == "goldfish" || hw == "ranchu" || hw.contains("vbox") ||
            product.startsWith("sdk_gphone") || product.contains("sdk_google") || product == "google_sdk" ||
            Build.MANUFACTURER.orEmpty().contains("Genymotion") ||
            (Build.BRAND.orEmpty().startsWith("generic") && Build.DEVICE.orEmpty().startsWith("generic"))
    }

    private fun isHooked(pm: PackageManager): Boolean {
        if (hookPackages.any { installed(pm, it) }) return true
        val xposed = runCatching { Class.forName("de.robv.android.xposed.XposedBridge"); true }.getOrDefault(false)
        if (xposed) return true
        // Librerías inyectadas en el proceso (Frida, Xposed, Substrate)
        val maps = runCatching { File("/proc/self/maps").readText() }.getOrDefault("")
        return listOf("frida", "gum-js-loop", "xposed", "lsposed", "substrate").any { maps.contains(it, ignoreCase = true) }
    }

    @Suppress("DEPRECATION")
    private fun signatureSha256(activity: FlutterActivity): String? = runCatching {
        val pm = activity.packageManager
        val name = activity.packageName
        val signatures = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            val info = pm.getPackageInfo(name, PackageManager.GET_SIGNING_CERTIFICATES).signingInfo
            if (info == null) emptyArray() else if (info.hasMultipleSigners()) info.apkContentsSigners else info.signingCertificateHistory
        } else {
            pm.getPackageInfo(name, PackageManager.GET_SIGNATURES).signatures
        }
        val first = signatures?.firstOrNull() ?: return@runCatching null
        MessageDigest.getInstance("SHA-256").digest(first.toByteArray()).joinToString(":") { "%02X".format(it) }
    }.getOrNull()

    @Suppress("DEPRECATION")
    private fun installed(pm: PackageManager, pkg: String): Boolean =
        runCatching { pm.getPackageInfo(pkg, 0); true }.getOrDefault(false)
}
