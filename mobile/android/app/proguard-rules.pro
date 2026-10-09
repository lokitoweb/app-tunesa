# Reglas R8 de TuneSA (release). Flutter ya agrega flutter_proguard_rules.pro
# y cada plugin (camera, geolocator, flutter_secure_storage, pdfx, file_picker,
# image_picker, connectivity_plus, ...) publica sus propias consumer rules.
# Estas reglas son conservadoras: mantienen el embedding y los plugins.

# Embedding de Flutter y registro de plugins
-keep class io.flutter.app.** { *; }
-keep class io.flutter.embedding.** { *; }
-keep class io.flutter.plugin.** { *; }
-keep class io.flutter.plugins.** { *; }
-keep class io.flutter.util.** { *; }
-keep class io.flutter.view.** { *; }

# Actividad principal (referenciada desde el manifest)
-keep class com.tunesa.mobile.MainActivity { *; }

# Componentes diferidos de Flutter referencian Play Core, que la app no incluye
-dontwarn com.google.android.play.core.**

# Anotaciones solo de compilación usadas por Tink (flutter_secure_storage)
-dontwarn com.google.errorprone.annotations.**
-dontwarn javax.annotation.**

# Conservar información para trazas de errores legibles
-keepattributes *Annotation*,Signature,InnerClasses,EnclosingMethod,SourceFile,LineNumberTable
