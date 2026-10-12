<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de geocerca por sede. NULL = valor de la empresa (o el de fábrica):
 *  - geofence_tolerance_meters: margen máximo de la zona de borde (Branch::DEFAULT_EDGE_TOLERANCE)
 *  - max_gps_accuracy_meters:   precisión GPS máxima aceptada en esta sede
 *  - geofence_policy:           reject | flag fuera del radio en esta sede
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedSmallInteger('geofence_tolerance_meters')->nullable()->after('allowed_radius_meters');
            $table->unsignedSmallInteger('max_gps_accuracy_meters')->nullable()->after('geofence_tolerance_meters');
            $table->string('geofence_policy', 10)->nullable()->after('max_gps_accuracy_meters');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['geofence_tolerance_meters', 'max_gps_accuracy_meters', 'geofence_policy']);
        });
    }
};
