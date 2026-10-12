<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos de eliminación de cuenta y datos (Google Play / App Store y Ley
 * 29733): desde la app (Mi perfil › Privacidad) o desde la web pública
 * /eliminar-cuenta. RRHH los atiende; user_id queda null si el correo no
 * corresponde a nadie (la web no revela si la cuenta existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->text('reason')->nullable();
            $table->string('source', 10); // app | web
            $table->string('status', 20)->default('pending'); // pending | completed | rejected
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};
