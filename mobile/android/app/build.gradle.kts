import java.util.Base64
import java.util.Properties
import java.io.FileInputStream

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Firma de release: android/key.properties (NO se versiona). Ver mobile/README.md.
// En CI se genera desde los secrets ANDROID_KEYSTORE_BASE64, etc.
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}

// Nombre bajo el ícono: APP_NAME de los --dart-define (scripts/build_apk.sh lo toma del kit de
// marca, mobile/branding/<kit>/brand.json). Flutter los pasa a Gradle en base64 separados por coma.
val appName: String = (project.findProperty("dart-defines") as String?)
    ?.split(",")
    ?.map { String(Base64.getDecoder().decode(it)) }
    ?.firstOrNull { it.startsWith("APP_NAME=") }
    ?.substringAfter("=")
    ?.takeIf { it.isNotBlank() }
    ?: "ERP"

android {
    // Igual al applicationId (MainActivity vive en com.tunesa.mobile)
    namespace = "com.tunesa.mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        // flutter_local_notifications (avisos push en primer plano) usa java.time
        isCoreLibraryDesugaringEnabled = true
    }

    defaultConfig {
        applicationId = "com.tunesa.mobile"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        manifestPlaceholders["appLabel"] = appName
        manifestPlaceholders["usesCleartextTraffic"] = "false"
    }

    signingConfigs {
        create("release") {
            if (keystorePropertiesFile.exists()) {
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
                storeFile = file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
            }
        }
    }

    buildTypes {
        debug {
            // Permite http:// contra el backend local (emulador → 10.0.2.2)
            manifestPlaceholders["usesCleartextTraffic"] = "true"
        }
        release {
            // Con key.properties firma con la llave de producción; sin ella usa
            // la llave debug para que el APK de prueba se pueda instalar igual.
            // scripts/build_apk.sh se niega a compilar prod sin key.properties.
            signingConfig = if (keystorePropertiesFile.exists()) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }

            // R8: reduce y ofusca el código y quita recursos sin usar. Flutter
            // agrega sus reglas y cada plugin trae las suyas (consumer rules);
            // proguard-rules.pro solo suma reglas conservadoras.
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }
}

// Firebase (push) se inicializa desde Dart con las opciones de --dart-define
// (FIREBASE_*); NO se usa el plugin com.google.gms.google-services.
// scripts/build_apk.sh lee google-services.json (esta carpeta) y pasa sus
// valores como FIREBASE_* cuando faltan. Sin esos valores el push queda apagado.

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
