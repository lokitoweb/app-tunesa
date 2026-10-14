<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos de la empresa (políticas, reglamentos, manuales...) en carpetas
 * propias, con público (audience_targets) y registro de lectura y de
 * "leído y conforme" de cada persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('icon', 40)->default('folder');
            $table->string('color', 7)->default('#6C4CE0');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'position']);
        });

        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('company_document_folders')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('version', 30)->nullable()->comment('Ej.: v2, 2026');
            $table->string('file_disk', 30);
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedInteger('file_size')->default(0);
            $table->boolean('requires_acknowledgement')->default(false);
            $table->date('acknowledge_by')->nullable()->comment('Fecha límite para confirmar');
            $table->string('status', 20)->default('draft')->comment('draft | published | archived');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'folder_id']);
        });

        Schema::create('company_document_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('company_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_read_at')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_ip', 45)->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_document_reads');
        Schema::dropIfExists('company_documents');
        Schema::dropIfExists('company_document_folders');
    }
};
