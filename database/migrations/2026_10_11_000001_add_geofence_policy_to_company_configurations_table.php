<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Política de la empresa ante una marcación fuera del radio de la sucursal:
 *  - reject: la marcación se rechaza (comportamiento original).
 *  - flag:   la marcación se registra marcada "fuera de geocerca" para que RRHH la revise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_configurations', function (Blueprint $table) {
            $table->string('geofence_policy', 10)->default('reject')->after('max_upload_mb');
        });
    }

    public function down(): void
    {
        Schema::table('company_configurations', function (Blueprint $table) {
            $table->dropColumn('geofence_policy');
        });
    }
};
