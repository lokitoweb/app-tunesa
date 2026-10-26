<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA Ronda 2 · regla vigente 9: días de vacaciones que se ganan por año de servicio
 * (régimen general: 30 días calendario). NULL = hereda de la plataforma (30 por defecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('vacation_days_per_year')->nullable()->after('lunch_paid');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('vacation_days_per_year');
        });
    }
};
