<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcación solo con turno programado:
 *  - tenant_settings.punch_requires_schedule (NULL = heredar; por defecto sí).
 *  - attendance_offshift_authorizations: RRHH o Gerencia habilita a una
 *    persona a marcar un día que no tenía turno (descanso, feriado o sin
 *    programación). Una por persona y día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->boolean('punch_requires_schedule')->nullable()->after('lunch_paid');
        });

        Schema::create('attendance_offshift_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('reason', 300);
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
            $table->index(['tenant_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_offshift_authorizations');
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn('punch_requires_schedule');
        });
    }
};
