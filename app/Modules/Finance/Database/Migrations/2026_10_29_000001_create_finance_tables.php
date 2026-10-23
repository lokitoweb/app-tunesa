<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras y tesorería migradas del ERP de escritorio (sisgere, SQL Server).
 * Tipos y largos tomados de los parámetros de sus procedimientos almacenados
 * (tproveedor_crear, trequerimiento_crear, Tcuotadoc_crear, crear_costofijo...).
 * "empresa" del sistema viejo = tenant; los colaboradores no van aquí: se
 * vinculan con users por DNI/correo (legacy_id_map).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Equivalencia id viejo -> registro nuevo: re-importar no duplica
        Schema::create('legacy_id_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('legacy_table', 60)->comment('Ej.: tproveedor, tblrequerimiento');
            $table->string('legacy_id', 60);
            $table->string('model_type', 120);
            $table->unsignedBigInteger('model_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'legacy_table', 'legacy_id']);
            $table->index(['model_type', 'model_id']);
        });

        // tproveedor
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 250);
            $table->string('ruc', 11)->nullable();
            $table->string('dni', 8)->nullable();
            $table->string('address', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('contact', 50)->nullable();
            $table->string('city', 50)->nullable();
            $table->string('category', 100)->nullable()->comment('rubro');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'ruc']);
            $table->index(['tenant_id', 'name']);
        });

        // tcuenta / tproveedorbanco
        Schema::create('supplier_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('bank', 50);
            $table->string('account_number', 100);
            $table->string('account_type', 50)->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'bank', 'account_number']);
        });

        // tproducto
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('classification', 50)->nullable();
            $table->string('unit', 50)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
        });

        // tblrequerimiento (+ datos de OC/OS de trequerimiento_oc / _os)
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('series', 50)->nullable()->comment('serierq');
            $table->unsignedInteger('number')->nullable()->comment('cod_req correlativo');
            $table->dateTime('requested_at');
            $table->text('detail')->nullable();
            $table->string('area', 50)->nullable();
            $table->string('requested_by', 50)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 100)->nullable();
            $table->string('plate', 50)->nullable();
            $table->string('km', 50)->nullable();
            $table->string('agency', 100)->nullable();
            $table->string('site', 100)->nullable();
            $table->string('delivery_place', 100)->nullable();
            $table->string('classification', 100)->nullable();
            $table->string('stage', 50)->default('solicitado')->comment('etapa');
            $table->string('payment_terms', 50)->nullable();
            $table->string('notes', 100)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purchase_order_number', 50)->nullable();
            $table->string('service_order_number', 50)->nullable();
            $table->string('invoice_number', 50)->nullable();
            $table->string('payment_origin', 50)->nullable();
            $table->string('sale_type', 50)->nullable();
            $table->string('priority', 50)->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('reviewed_by', 50)->nullable();
            $table->string('currency', 10)->default('PEN');
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('amount_payable', 14, 2)->nullable();
            $table->boolean('has_detraction')->default(false);
            $table->unsignedSmallInteger('installments')->default(1);
            $table->json('attachments')->nullable()->comment('Rutas en storage: quote, request, receipt');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'series', 'number']);
            $table->index(['tenant_id', 'stage', 'requested_at']);
        });

        // tdetalle
        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('justification', 100)->nullable();
            $table->timestamps();
        });

        // cotizacion (CREAR_COTIZACION)
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 50)->nullable();
            $table->string('bank', 50)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('approved_by', 50)->nullable();
            $table->string('stage', 50)->nullable();
            $table->dateTime('quoted_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('contact', 50)->nullable();
            $table->string('phone', 50)->nullable();
            $table->timestamps();
        });

        // tblcuota_doc
        Schema::create('payment_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('installment');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('pending')->comment('estado_pago: pending | paid');
            $table->string('payment_method', 50)->nullable();
            $table->string('operation_number', 50)->nullable();
            $table->string('registered_by', 50)->nullable();
            $table->json('vouchers')->nullable()->comment('Hasta 4 vouchers/adjuntos en storage');
            $table->timestamps();
            $table->index(['requisition_id', 'installment']); // sin unique: el sistema viejo no lo garantizaba
            $table->index(['tenant_id', 'status', 'due_date']);
        });

        // tblcancelaciones
        Schema::create('payment_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_installment_id')->constrained()->cascadeOnDelete();
            $table->dateTime('paid_at');
            $table->decimal('amount', 14, 2);
            $table->string('bank', 50)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('operation_number', 50)->nullable();
            $table->boolean('is_amortization')->default(false);
            $table->string('registered_by', 50)->nullable();
            $table->string('authorized_by', 50)->nullable();
            $table->timestamps();
        });

        // costofijo (+ cabecera_gastos_fijos por mes)
        Schema::create('fixed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period', 7)->nullable()->comment('YYYY-MM');
            $table->string('classification', 50)->nullable();
            $table->string('supplier_name', 250)->nullable();
            $table->string('bank', 50)->nullable();
            $table->string('account_number', 100)->nullable();
            $table->string('detail', 250)->nullable();
            $table->date('date');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('PEN');
            $table->unsignedSmallInteger('installment')->default(1);
            $table->unsignedSmallInteger('frequency')->nullable()->comment('Días entre cuotas');
            $table->unsignedInteger('batch')->nullable()->comment('nlote');
            $table->decimal('detraction_amount', 14, 2)->nullable();
            $table->unsignedTinyInteger('detraction_percent')->nullable();
            $table->string('detraction_number', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        foreach (['fixed_costs', 'payment_cancellations', 'payment_installments', 'quotations', 'requisition_items',
            'requisitions', 'products', 'supplier_bank_accounts', 'suppliers', 'legacy_id_map'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
