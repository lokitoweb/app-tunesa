<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tesorería (FrmReqProg / FrmRegpagos / Fcaja_chica del ERP de escritorio):
 * programación y autorización de cuotas (Modificar_PROGRAMACION,
 * validar_pago_final), pagos parciales con cuenta de origen y voucher
 * (Tblcancelaciones_crear), detracción del requerimiento (corregir_detraccion)
 * y caja chica con recibo correlativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // TCuenta_origen: cuentas de la empresa desde las que se paga
        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bank', 50);
            $table->string('account_number', 100);
            $table->string('currency', 10)->default('PEN');
            $table->string('alias', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'bank', 'account_number']);
        });

        Schema::table('payment_installments', function (Blueprint $table) {
            $table->decimal('paid_amount', 14, 2)->default(0)->after('amount');
            $table->date('scheduled_for')->nullable()->after('due_date')->comment('f_programacion');
            $table->string('scheduled_by', 50)->nullable()->after('scheduled_for');
            $table->foreignId('authorized_by_id')->nullable()->after('scheduled_by')->constrained('users')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable()->after('authorized_by_id');
            $table->timestamp('paid_at')->nullable()->after('authorized_at');
            $table->index(['tenant_id', 'scheduled_for']);
        });

        Schema::table('payment_cancellations', function (Blueprint $table) {
            $table->foreignId('company_bank_account_id')->nullable()->after('payment_installment_id')->constrained()->nullOnDelete();
            $table->string('voucher_path')->nullable()->after('operation_number')->comment('Disco private');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('detraction_number', 100)->nullable()->after('has_detraction');
            $table->decimal('detraction_amount', 14, 2)->nullable()->after('detraction_number');
            $table->unsignedTinyInteger('detraction_percent')->nullable()->after('detraction_amount');
        });

        // Caja chica: gastos menores con recibo correlativo y comprobante
        Schema::create('petty_cash_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('receipt_number');
            $table->date('date');
            $table->string('description', 250);
            $table->string('category', 100)->nullable();
            $table->string('beneficiary', 150)->nullable();
            $table->string('document_type', 20)->nullable()->comment('BOLETA | FACTURA | RECIBO | TICKET | SIN COMPROBANTE');
            $table->string('document_number', 50)->nullable();
            $table->string('ruc', 11)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('cash_box', 50)->default('CAJA EFECTIVO')->comment('Origen: CAJA EFECTIVO, CAJA PASAJES...');
            $table->string('voucher_path')->nullable()->comment('Disco private');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'receipt_number']);
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_expenses');
        Schema::table('requisitions', fn (Blueprint $t) => $t->dropColumn(['detraction_number', 'detraction_amount', 'detraction_percent']));
        Schema::table('payment_cancellations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_bank_account_id');
            $table->dropColumn('voucher_path');
        });
        Schema::table('payment_installments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'scheduled_for']);
            $table->dropConstrainedForeignId('authorized_by_id');
            $table->dropColumn(['paid_amount', 'scheduled_for', 'scheduled_by', 'authorized_at', 'paid_at']);
        });
        Schema::dropIfExists('company_bank_accounts');
    }
};
