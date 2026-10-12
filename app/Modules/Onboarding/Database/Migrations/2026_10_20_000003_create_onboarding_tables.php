<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incorporación (onboarding): etapas que cada empresa activa y el proceso de
 * cada colaborador. Las etapas no duplican datos: se evalúan contra la ficha,
 * las tallas, el legajo y la capacitación de inducción (OnboardingService).
 * Los documentos exigidos son los de Configuración de la app (required_documents).
 *
 * users.onboarding_status copia el estado del proceso para el candado de la
 * marcación. Por defecto 'approved': quien ya marca hoy no queda bloqueado;
 * los ingresos nuevos se crean en 'pending'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 30);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_required')->default(true);
            $table->foreignId('course_id')->nullable()->constrained('training_courses')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('onboarding_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('onboarding_status', 20)->default('approved')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('onboarding_status'));
        Schema::dropIfExists('onboarding_processes');
        Schema::dropIfExists('onboarding_stages');
    }
};
