<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intentos de marcación rechazados (fuera de la geocerca, ubicación simulada,
 * GPS impreciso, sin conexión no permitido...). Es evidencia para RRHH: la app
 * recibe el rechazo y aquí queda quién, dónde, cuándo y con qué teléfono.
 * No guarda la selfie (se descarta al rechazar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_punch_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('type', ['clock_in', 'break_start', 'break_end', 'clock_out']);
            $table->string('error_code', 40);
            $table->string('message', 500);

            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->unsignedInteger('allowed_radius')->nullable();
            $table->boolean('mock_location_detected')->default(false);
            $table->json('device_flags')->nullable();

            $table->boolean('offline')->default(false);
            $table->dateTime('offline_recorded_at')->nullable()->comment('UTC');
            $table->dateTime('device_time')->nullable()->comment('UTC');
            $table->uuid('client_uuid')->nullable();
            $table->string('device_id', 191)->nullable();
            $table->string('device_os', 100)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'error_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punch_attempts');
    }
};
