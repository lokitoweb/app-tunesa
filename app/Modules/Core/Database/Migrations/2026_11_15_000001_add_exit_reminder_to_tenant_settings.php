<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recordatorio push de salida, igual que el de entrada: cuántos minutos y si va antes o después
 * de la hora de salida esperada. NULL = hereda de la plataforma (por defecto: 5 minutos después).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('exit_reminder_minutes')->nullable()->comment('Minutos del recordatorio de salida respecto a la hora de salida');
            $table->string('exit_reminder_timing', 10)->nullable()->comment('before | after: antes o después de la hora de salida');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['exit_reminder_minutes', 'exit_reminder_timing']);
        });
    }
};