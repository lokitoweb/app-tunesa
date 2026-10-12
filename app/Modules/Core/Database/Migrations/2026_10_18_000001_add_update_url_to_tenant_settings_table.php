<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlace de descarga de la app (tienda o APK). La app lo abre desde el aviso
 * "Hay una nueva versión" y la pantalla de actualización obligatoria.
 * NULL = heredar de la plataforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->string('update_url', 500)->nullable()->after('force_update');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', fn (Blueprint $table) => $table->dropColumn('update_url'));
    }
};
