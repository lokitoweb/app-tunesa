<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regalos de cumpleaños entre compañeros (animaciones de la app).
 * date = día del cumpleaños (zona horaria de la empresa): el envío y la
 * reproducción solo valen ese día; después queda como historial.
 * Un mismo regalo una sola vez por remitente y cumpleaños.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('gift_type', 30);
            $table->date('date');
            $table->timestamps();

            $table->unique(['user_id', 'sender_id', 'gift_type', 'date']);
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_gifts');
    }
};
