<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de vacaciones, permisos y licencias médicas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();

            // === AISLAMIENTO MULTI-TENANT ===
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()
                ->comment('Empleado que solicita');

            $table->enum('type', ['vacation', 'permission', 'medical_leave']);

            $table->date('start_date');
            $table->date('end_date');
            // Permisos por horas (opcional): mismo día con hora de inicio y fin
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('days', 5, 2)->default(0)->comment('Días calendario solicitados');

            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable()
                ->comment('Sustento (certificado médico, etc.) en disco privado');

            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
