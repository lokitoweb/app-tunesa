<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Políticas de marcación, geocodificación y push en "Marca y app".
 * NULL = heredar (empresa → plataforma → TenantSetting::DEFAULTS).
 *
 * La clave de Google Maps reutiliza la columna cifrada `maps_api_key`
 * (en código y formularios se llama google_maps_api_key).
 * `fcm_service_account_json` solo se lee en la fila global: la plataforma es
 * dueña de la única app móvil (un solo proyecto de Firebase).
 */
return new class extends Migration
{
    private const COLUMNS = ['mock_location_policy', 'max_time_drift_seconds', 'geocoding_provider', 'fcm_service_account_json', 'fcm_enabled'];

    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->string('mock_location_policy', 10)->nullable()->comment('reject | flag');
            $table->unsignedInteger('max_time_drift_seconds')->nullable();
            $table->string('geocoding_provider', 20)->nullable()->comment('nominatim | google');
            $table->text('fcm_service_account_json')->nullable()->comment('Cifrado. Solo fila global');
            $table->boolean('fcm_enabled')->nullable();
        });
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            Schema::table('tenant_settings', fn (Blueprint $table) => $table->dropColumn($column));
        }
    }
};
