<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Público de un contenido (beneficio, documento de la empresa...): a quién
 * se le muestra en la app. Una fila por regla; basta con cumplir una.
 * Ver App\Modules\Core\Support\Audience.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->morphs('targetable');
            $table->string('target_type', 20)->comment('all | branch | department | position | user');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_value', 150)->nullable()->comment('Cargo (position)');
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_targets');
    }
};
