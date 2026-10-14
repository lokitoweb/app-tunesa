<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beneficios para el personal: descuentos, convenios, promociones... con
 * categorías propias de cada empresa, vigencia y público (audience_targets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('icon', 40)->default('gift')->comment('Ícono (bootstrap-icons / Material en la app)');
            $table->string('color', 7)->default('#6C4CE0');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'position']);
        });

        Schema::create('benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('benefit_categories')->nullOnDelete();
            $table->string('title', 150);
            $table->string('summary', 200)->nullable()->comment('Frase corta para la tarjeta');
            $table->text('description')->nullable();
            $table->string('partner', 120)->nullable()->comment('Empresa aliada o proveedor');
            $table->string('highlight', 40)->nullable()->comment('Ej.: 20% dscto, 2x1, Gratis');
            $table->string('promo_code', 60)->nullable();
            $table->text('how_to_use')->nullable();
            $table->text('terms')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('contact', 150)->nullable();
            $table->string('location', 200)->nullable();
            $table->string('image_path')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->string('status', 20)->default('draft')->comment('draft | published | archived');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefits');
        Schema::dropIfExists('benefit_categories');
    }
};
