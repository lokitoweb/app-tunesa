<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jefe de área: es el jefe inmediato de quienes trabajan en el área. Revisa
 * sus solicitudes (vacaciones, permisos) junto con el supervisor de la sede
 * y RR. HH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('head_id')->nullable()->after('code')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_id');
        });
    }
};
