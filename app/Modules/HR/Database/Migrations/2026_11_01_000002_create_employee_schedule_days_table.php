<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA Ronda 2 · HQ-01 / HQ-05: horario por fecha (colaborador + fecha → turno o día libre).
 *
 * Una fila manda sobre la asignación semanal o rotativa (employee_shifts) ese día:
 * shift_id NULL = día libre explícito. Sin fila, rige la asignación vigente. Turno
 * nocturno: work_date es la fecha de inicio (misma convención que attendances).
 * Se escribe desde el calendario de programación; cada guardado queda en la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->cascadeOnDelete()
                ->comment('NULL = día libre');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete()
                ->comment('Agencia donde trabaja ese día (obligatoria con turno)');
            $table->string('source', 20)->default('calendar');
            $table->string('notes', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'work_date']);
            $table->index(['tenant_id', 'branch_id', 'work_date']);
            $table->index(['tenant_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_schedule_days');
    }
};
