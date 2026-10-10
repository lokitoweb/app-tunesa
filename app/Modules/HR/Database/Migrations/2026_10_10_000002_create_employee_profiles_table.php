<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha laboral del colaborador: datos bancarios, régimen previsional,
 * régimen laboral y depósito de CTS. Uno por usuario.
 *
 * Los números de cuenta y el CUSPP se guardan cifrados (cast 'encrypted'),
 * por eso son TEXT. La fecha de ingreso es users.hire_date (una sola fuente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Datos personales complementarios
            $table->string('address')->nullable();
            $table->string('district', 100)->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();

            // Cuenta de abono de haberes
            $table->string('bank_name', 100)->nullable();
            $table->enum('bank_account_type', ['savings', 'checking', 'payroll'])->nullable();
            $table->char('bank_account_currency', 3)->nullable();
            $table->text('bank_account_number')->nullable()->comment('Cifrado');
            $table->text('interbank_code')->nullable()->comment('CCI / CLABE / CBU, cifrado');

            // Régimen previsional
            $table->enum('pension_system', ['afp', 'onp', 'social_security', 'none'])->nullable();
            $table->string('pension_fund', 100)->nullable()->comment('AFP / administradora');
            $table->text('pension_code')->nullable()->comment('CUSPP / NSS, cifrado');
            $table->enum('health_insurance', ['essalud', 'eps', 'sis', 'imss', 'private', 'none'])->nullable();

            // Régimen laboral y CTS (solo RRHH)
            $table->enum('labor_regime', ['general', 'micro_company', 'small_company', 'agrarian', 'construction', 'part_time', 'other'])->nullable();
            $table->string('cts_bank_name', 100)->nullable();
            $table->text('cts_account_number')->nullable()->comment('Cifrado');
            $table->char('cts_currency', 3)->nullable();

            // Verificación de RRHH sobre los datos de pago
            $table->enum('payment_info_status', ['incomplete', 'pending', 'verified', 'rejected'])->default('incomplete');
            $table->timestamp('payment_info_updated_at')->nullable();
            $table->foreignId('payment_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('payment_verified_at')->nullable();
            $table->string('payment_rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'payment_info_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
