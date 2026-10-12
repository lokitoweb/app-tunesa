<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo registró el celular su token de push (users.fcm_token). El panel lo
 * muestra al probar el push: un token viejo suele ser de una instalación que
 * ya no existe y Google lo acepta igual (HTTP 200) sin entregar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('fcm_token_updated_at')->nullable()->after('fcm_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('fcm_token_updated_at'));
    }
};
