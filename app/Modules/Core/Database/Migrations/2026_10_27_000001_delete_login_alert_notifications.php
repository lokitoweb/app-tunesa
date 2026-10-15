<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Quita de la bandeja los avisos "Nuevo inicio de sesión": ya no se generan */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->where('type', 'security.new_device')->delete();
    }

    public function down(): void
    {
        // No se recuperan
    }
};
