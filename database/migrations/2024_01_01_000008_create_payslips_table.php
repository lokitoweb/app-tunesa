<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: payslips (Boletas de Pago)
 * RRHH sube las boletas PDF y quedan asociadas al empleado.
 * El empleado las visualiza desde la app móvil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();

            // === AISLAMIENTO MULTI-TENANT ===
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // === RELACIONES ===
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('Empleado al que pertenece la boleta');

            // Quién subió la boleta
            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->comment('HR Manager que subió la boleta');

            // === DATOS DE LA BOLETA ===
            $table->string('title')->comment('Ej: Boleta de Pago - Octubre 2024');

            // Período de la boleta
            $table->tinyInteger('period_month')->comment('Mes: 1-12');
            $table->year('period_year')->comment('Año: ej. 2024');

            // Archivo PDF
            $table->string('file_path')->comment('Ruta en Storage (S3 o local)');
            $table->string('file_name')->comment('Nombre original del archivo');
            $table->unsignedInteger('file_size')->nullable()->comment('Tamaño en bytes');
            $table->string('mime_type', 50)->default('application/pdf');

            // === DATOS ECONÓMICOS (OPCIONALES, para display) ===
            $table->decimal('gross_salary', 10, 2)->nullable()->comment('Salario bruto');
            $table->decimal('deductions', 10, 2)->nullable()->comment('Total de descuentos');
            $table->decimal('net_salary', 10, 2)->nullable()->comment('Salario neto a pagar');
            $table->json('breakdown')->nullable()
                ->comment('Desglose detallado: bonos, descuentos, etc. en formato JSON');

            // === ESTADO Y NOTIFICACIÓN ===
            $table->enum('status', [
                'draft',        // Borrador, no visible para el empleado
                'published',    // Publicada y visible
                'archived',     // Archivada
            ])->default('draft');

            $table->timestamp('published_at')->nullable();

            // Control de lectura: el empleado confirmó ver la boleta
            $table->timestamp('read_at')->nullable()
                ->comment('Cuándo el empleado abrió/leyó la boleta por primera vez');

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // === ÍNDICES ===
            $table->index(['tenant_id', 'user_id', 'period_year', 'period_month']);
            $table->index(['tenant_id', 'status']);

            // Un empleado solo puede tener una boleta por mes/año
            $table->unique(
                ['user_id', 'period_month', 'period_year'],
                'unique_payslip_per_period'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
