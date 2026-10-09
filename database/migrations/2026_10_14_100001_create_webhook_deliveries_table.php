<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de las integraciones de "Marca y app": una fila por envío al
 * webhook o al endpoint de notificaciones (y por cada prueba manual desde el
 * panel). Los reintentos del job actualizan la misma fila (attempt, next_retry_at).
 *
 * tenant_id NULL = prueba hecha desde la vista global (plataforma). Se purgan a
 * los 30 días con `model:prune` (WebhookDelivery es Prunable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('Identifica el envío entre reintentos del job');
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('target', 40)->comment('webhook_url | notification_endpoint');
            $table->string('event', 100);
            $table->string('url', 255);
            $table->text('request_body')->nullable()->comment('Cuerpo enviado (truncado a 16 KB)');
            $table->string('signature', 80)->nullable()->comment('Valor de X-Tunesa-Signature');
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->string('status', 20)->default('pending')->comment('pending | success | failed');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->text('response_body')->nullable()->comment('Respuesta (truncada a 4 KB)');
            $table->text('error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
