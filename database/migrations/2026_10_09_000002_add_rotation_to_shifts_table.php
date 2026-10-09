<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos fijos y rotativos.
 *
 * - fixed:    aplica los días de `working_days` (comportamiento actual).
 * - rotating: ciclo de N días de trabajo seguidos de M días de descanso
 *             (ej: 4x2, 6x1, 14x7), contado desde `rotation_anchor_date`
 *             de la asignación del empleado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->enum('type', ['fixed', 'rotating'])->default('fixed')->after('code');
            $table->unsignedSmallInteger('rotation_work_days')->nullable()->after('working_days')
                ->comment('Rotativo: días seguidos de trabajo');
            $table->unsignedSmallInteger('rotation_rest_days')->nullable()->after('rotation_work_days')
                ->comment('Rotativo: días seguidos de descanso');
        });

        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->date('rotation_anchor_date')->nullable()->after('effective_until')
                ->comment('Rotativo: primer día de trabajo del ciclo (default: effective_from)');
        });
    }

    public function down(): void
    {
        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->dropColumn('rotation_anchor_date');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['type', 'rotation_work_days', 'rotation_rest_days']);
        });
    }
};
