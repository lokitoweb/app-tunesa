<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca blanca extendida y configuración de la app móvil por empresa.
 *
 * Una fila por empresa (tenant_id único). La fila con tenant_id NULL es el
 * valor por defecto de la plataforma: la edita el Super Admin desde la vista
 * global y la heredan las empresas en todo lo que no definan.
 *
 * Todas las columnas de configuración son NULL = "heredar" (fila global y,
 * después, los valores por defecto de TenantSetting::DEFAULTS). El logo claro
 * y los colores primario/secundario de una empresa siguen en `tenants`; las
 * columnas logo_path / primary_color / secondary_color de esta tabla solo se
 * usan en la fila global (la plataforma no tiene fila en `tenants`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->unique()->constrained('tenants')->cascadeOnDelete();

            // Identidad
            $table->string('trade_name', 200)->nullable();
            $table->string('logo_path')->nullable()->comment('Solo fila global: logo claro de la plataforma');
            $table->string('logo_dark_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('splash_path')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->string('address', 500)->nullable();

            // Colores
            $table->string('primary_color', 7)->nullable()->comment('Solo fila global');
            $table->string('secondary_color', 7)->nullable()->comment('Solo fila global');
            $table->string('accent_color', 7)->nullable();
            $table->string('navbar_color', 7)->nullable();

            // App móvil
            $table->string('api_base_url')->nullable();
            $table->string('min_supported_version', 40)->nullable();
            $table->string('latest_version', 40)->nullable();
            $table->boolean('force_update')->nullable();
            $table->boolean('selfie_required')->nullable();
            $table->boolean('face_detection_required')->nullable();
            $table->boolean('offline_mode_enabled')->nullable();
            $table->unsignedSmallInteger('offline_max_hours')->nullable();
            $table->unsignedSmallInteger('max_gps_accuracy_meters')->nullable();

            // Reglas operativas
            $table->unsignedSmallInteger('default_grace_minutes')->nullable();
            $table->unsignedSmallInteger('lunch_minutes')->nullable();
            $table->boolean('lunch_paid')->nullable();
            $table->unsignedSmallInteger('default_geofence_radius')->nullable();

            // Integraciones (secretos cifrados con APP_KEY)
            $table->string('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->text('maps_api_key')->nullable();
            $table->string('notification_endpoint')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
