<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fondo del inicio de sesión: varias imágenes que se alternan y los segundos de cada una.
 * NULL = hereda de la plataforma; sin nada cargado, las imágenes incluidas (public/img/login).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->json('login_backgrounds')->nullable()->comment('Rutas (disco public) de los fondos del login, en orden');
            $table->unsignedSmallInteger('login_slide_seconds')->nullable()->comment('Segundos de cada fondo del login');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['login_backgrounds', 'login_slide_seconds']);
        });
    }
};
