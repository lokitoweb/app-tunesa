<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros de la liquidación: remuneración mínima y % de asignación familiar (por empresa,
 * heredables de la plataforma), quién recibe asignación familiar y las tasas de aporte de pensión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->decimal('minimum_wage', 10, 2)->nullable();
            $table->decimal('family_allowance_pct', 5, 2)->nullable();
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->boolean('family_allowance')->default(false);
        });

        Schema::create('pension_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('system', 10); // afp | onp
            $table->decimal('contribution_pct', 5, 2)->default(0);
            $table->decimal('insurance_pct', 5, 2)->default(0);
            $table->decimal('commission_pct', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pension_rates');
        Schema::table('employee_profiles', fn (Blueprint $table) => $table->dropColumn('family_allowance'));
        Schema::table('tenant_settings', fn (Blueprint $table) => $table->dropColumn(['minimum_wage', 'family_allowance_pct']));
    }
};
