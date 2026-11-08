<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recordatorio push de entrada: cuántos minutos y si va antes o después de la hora de entrada
 * del turno. NULL = hereda de la plataforma (por defecto: 5 minutos después).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('entry_reminder_minutes')->nullable()->comment('Minutos del recordatorio de entrada respecto a la hora de entrada');
            $table->string('entry_reminder_timing', 10)->nullable()->comment('before | after: antes o después de la hora de entrada');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['entry_reminder_minutes', 'entry_reminder_timing']);
        });
    }
};
