<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regímenes laborales administrables por empresa (parámetros de vacaciones, gratificación, CTS e
 * indemnización) y ceses con su liquidación de beneficios sociales.
 * employee_profiles.labor_regime deja de ser una lista fija: guarda el código del régimen de la empresa
 * (los códigos de siempre siguen valiendo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labor_regimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            // Vacío = el parámetro no aplica o no está definido (no se calcula ese concepto)
            $table->unsignedSmallInteger('vacation_days')->nullable();
            $table->decimal('gratifications_per_year', 5, 2)->nullable();
            $table->decimal('gratification_bonus_pct', 5, 2)->nullable();
            $table->decimal('cts_per_year', 5, 2)->nullable();
            $table->decimal('indemnity_days_per_year', 6, 2)->nullable();
            $table->unsignedSmallInteger('indemnity_cap_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->string('labor_regime', 50)->nullable()->change();
        });

        Schema::create('employee_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('labor_regime', 50)->nullable();
            $table->date('termination_date');
            $table->string('reason', 50);
            $table->text('notes')->nullable();
            $table->json('data');
            $table->decimal('total', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_settlements');
        Schema::dropIfExists('labor_regimes');
    }
};
