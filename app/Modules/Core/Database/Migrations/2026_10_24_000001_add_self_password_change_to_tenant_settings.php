<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite (o no) que el colaborador cambie su contraseña desde la app.
 * NULL = hereda de la plataforma; por defecto permitido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->boolean('self_password_change')->nullable()->after('fcm_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('self_password_change');
        });
    }
};
