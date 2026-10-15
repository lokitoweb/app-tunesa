<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comunicados de RR. HH.: avisos a toda la empresa, una sede, un área, un
 * cargo o una persona (audience_targets), con push, envío programado y
 * registro de lectura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('body');
            $table->string('category', 20)->default('general')->comment('general | meeting | update | reminder | urgent');
            $table->boolean('is_important')->default(false);
            $table->dateTime('event_at')->nullable()->comment('Reuniones: fecha y hora');
            $table->string('location', 200)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('status', 20)->default('draft')->comment('draft | scheduled | sent');
            $table->dateTime('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'sent_at']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['announcement_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
    }
};
