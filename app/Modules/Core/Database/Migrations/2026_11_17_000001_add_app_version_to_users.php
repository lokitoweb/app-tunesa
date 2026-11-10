<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versión de la app con la que entró cada persona por última vez (cabecera X-App-Version).
 * El push "Nueva versión de la app" ya no llega a quien tiene esa versión o una más nueva.
 * Solo agrega una columna (nullable): no cambia datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'app_version')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('app_version', 20)->nullable()->after('fcm_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'app_version')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('app_version'));
        }
    }
};
