<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumen de integridad de la entrada y la salida en la jornada (el detalle
 * de cada evento está en attendance_punches). Las horas *_device_time van en UTC.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'clock_in_accuracy_meters', 'clock_out_accuracy_meters',
        'clock_in_device_time', 'clock_out_device_time',
        'clock_in_time_drift_seconds', 'clock_out_time_drift_seconds',
        'clock_in_mock_location', 'clock_out_mock_location',
        'clock_in_face_detected', 'clock_out_face_detected',
        'is_suspicious',
    ];

    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->decimal('clock_in_accuracy_meters', 8, 2)->nullable();
            $table->decimal('clock_out_accuracy_meters', 8, 2)->nullable();
            $table->dateTime('clock_in_device_time')->nullable()->comment('UTC');
            $table->dateTime('clock_out_device_time')->nullable()->comment('UTC');
            $table->integer('clock_in_time_drift_seconds')->nullable();
            $table->integer('clock_out_time_drift_seconds')->nullable();
            $table->boolean('clock_in_mock_location')->default(false);
            $table->boolean('clock_out_mock_location')->default(false);
            $table->boolean('clock_in_face_detected')->nullable();
            $table->boolean('clock_out_face_detected')->nullable();
            $table->boolean('is_suspicious')->default(false);

            $table->index(['tenant_id', 'is_suspicious', 'work_date'], 'attendances_tenant_suspicious_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_tenant_suspicious_date_index');
        });

        foreach (self::COLUMNS as $column) {
            Schema::table('attendances', fn (Blueprint $table) => $table->dropColumn($column));
        }
    }
};
