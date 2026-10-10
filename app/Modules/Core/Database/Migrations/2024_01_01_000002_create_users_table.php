<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: users
 * Tabla central de usuarios con aislamiento multi-tenant.
 * Soporta todos los roles: super_admin, company_admin, hr_manager, supervisor, employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // === AISLAMIENTO MULTI-TENANT ===
            // NULL solo para super_admin que opera a nivel global
            $table->foreignId('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->cascadeOnDelete()
                ->comment('NULL solo para super_admin');

            // === DATOS PERSONALES ===
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Número de documento de identidad
            $table->string('document_number', 20)->nullable();
            $table->enum('document_type', ['dni', 'passport', 'ce'])->default('dni');

            $table->string('phone', 20)->nullable();
            $table->string('avatar_path')->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();

            // === ROL Y ESTADO ===
            $table->enum('role', [
                'super_admin',
                'company_admin',
                'hr_manager',
                'supervisor',
                'employee',
            ])->default('employee');

            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');

            // Código de empleado interno de la empresa
            $table->string('employee_code', 50)->nullable()->comment('Código interno de empleado');

            // Fecha de contratación y fin de contrato
            $table->date('hire_date')->nullable();
            $table->date('termination_date')->nullable();

            // Tipo de contrato
            $table->enum('contract_type', [
                'full_time',
                'part_time',
                'contractor',
                'intern',
            ])->default('full_time');

            // Salario base (usado por módulo de nómina)
            $table->decimal('base_salary', 10, 2)->default(0);

            // Cargo/puesto
            $table->string('position')->nullable();
            $table->string('department')->nullable();

            // Token de recordatorio y 2FA
            $table->rememberToken();
            $table->string('two_factor_secret')->nullable();
            $table->string('two_factor_recovery_codes')->nullable();

            // Device token para notificaciones push
            $table->string('fcm_token')->nullable()->comment('Firebase Cloud Messaging token para push notifications');

            $table->timestamps();
            $table->softDeletes();

            // === ÍNDICES ===
            // Compuesto: acelera todas las consultas de usuarios por tenant
            $table->index(['tenant_id', 'role']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
