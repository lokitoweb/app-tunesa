<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué ve y qué edita el colaborador desde la app, por empresa.
 * Una fila por tenant; si no existe se usan los valores por defecto del modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();

            $table->boolean('mobile_payslips_enabled')->default(true);
            $table->boolean('mobile_documents_enabled')->default(true);
            $table->boolean('mobile_documents_upload')->default(true);
            $table->boolean('mobile_payment_info_enabled')->default(true);
            $table->boolean('mobile_payment_info_edit')->default(true);
            $table->boolean('mobile_uniforms_enabled')->default(true);
            $table->boolean('mobile_uniforms_edit')->default(true);

            // Una vez verificados por RRHH, los datos de pago quedan bloqueados en la app
            $table->boolean('lock_verified_payment_info')->default(true);
            // Tipos de documento que el legajo exige (para el % de avance del onboarding)
            $table->json('required_documents')->nullable();
            $table->unsignedSmallInteger('max_upload_mb')->default(5);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_configurations');
    }
};
