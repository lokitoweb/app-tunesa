<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: tenants
 * Representa cada empresa contratante del SaaS.
 * Es la tabla raíz de todo el sistema multi-tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            // Identificador único del tenant (slug para subdominio o cabecera HTTP)
            $table->string('slug')->unique()->comment('Identificador único del tenant (ej: empresa-acme)');

            // Datos de la empresa
            $table->string('company_name');
            $table->string('ruc', 20)->nullable()->comment('RUC o NIT de la empresa');
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable();
            $table->string('logo_path')->nullable()->comment('Ruta del logo de la empresa');
            $table->text('address')->nullable();

            // Plan de suscripción
            $table->enum('plan', ['trial', 'basic', 'professional', 'enterprise'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();

            // Estado del tenant
            $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active');

            // Configuración de zona horaria (crucial para horarios y asistencias)
            $table->string('timezone', 50)->default('America/Lima');
            $table->string('currency', 10)->default('PEN');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
