<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobación de horas extra por jefatura. overtime_hours sigue siendo lo
 * calculado al marcar la salida; a planilla va overtime_approved_hours solo
 * si overtime_status = approved. Las jornadas anteriores con horas extra
 * quedan aprobadas tal cual, para no cambiar planillas ya cerradas.
 */
return new class extends Migration
{
    private const COLUMNS = ['overtime_status', 'overtime_approved_hours', 'overtime_reviewed_by', 'overtime_reviewed_at', 'overtime_review_notes'];

    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('overtime_status', 10)->nullable()->comment('pending | approved | rejected');
            $table->decimal('overtime_approved_hours', 5, 2)->nullable();
            $table->foreignId('overtime_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overtime_reviewed_at')->nullable();
            $table->string('overtime_review_notes', 500)->nullable();

            $table->index(['tenant_id', 'overtime_status', 'work_date'], 'attendances_tenant_overtime_date_index');
        });

        DB::table('attendances')->where('overtime_hours', '>', 0)->update([
            'overtime_status'         => 'approved',
            'overtime_approved_hours' => DB::raw('overtime_hours'),
            'overtime_review_notes'   => 'Registrada antes de la aprobación de horas extra',
        ]);
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_tenant_overtime_date_index');
            $table->dropConstrainedForeignId('overtime_reviewed_by');
        });

        foreach (array_diff(self::COLUMNS, ['overtime_reviewed_by']) as $column) {
            Schema::table('attendances', fn (Blueprint $table) => $table->dropColumn($column));
        }
    }
};
