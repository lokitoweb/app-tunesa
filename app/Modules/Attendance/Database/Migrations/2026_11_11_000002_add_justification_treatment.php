<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faltas y tiempos justificados (decisión del propietario, 11/2026):
 * - attendances.early_exit_minutes: salida anticipada guardada al cerrar la jornada (fórmula única), como la tardanza.
 * - attendances.justification_paid: RR. HH. elige con / sin goce al aprobar (sin valor por defecto; null = sin clasificar).
 * - leave_requests.with_pay: vacaciones y descanso médico siempre con goce; permisos los clasifica RR. HH. al aprobar.
 * Solo agrega columnas; las aprobaciones anteriores de permisos quedan "sin clasificar" para revisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedSmallInteger('early_exit_minutes')->default(0)->after('late_minutes');
            $table->boolean('justification_paid')->nullable()->after('justification_status');
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('with_pay')->nullable()->after('status');
        });

        DB::table('leave_requests')->whereIn('type', ['vacation', 'medical_leave'])->update(['with_pay' => true]);
    }

    public function down(): void
    {
        Schema::table('attendances', fn (Blueprint $table) => $table->dropColumn(['early_exit_minutes', 'justification_paid']));
        Schema::table('leave_requests', fn (Blueprint $table) => $table->dropColumn('with_pay'));
    }
};
