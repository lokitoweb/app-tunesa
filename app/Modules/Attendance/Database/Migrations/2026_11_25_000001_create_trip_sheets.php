<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hojas de viajes firmadas (evidencia): el supervisor imprime la hoja de la Bitácora de viajes,
 * los choferes la firman y se sube escaneada o en foto. Se guarda en el disco privado; al quitarla
 * de la lista el archivo se conserva (borrado lógico) y queda en la bitácora de auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('period_from')->comment('Periodo de la hoja: desde');
            $table->date('period_to')->comment('Periodo de la hoja: hasta');
            $table->string('file_path')->comment('Archivo en el disco privado');
            $table->string('original_name', 200);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('notes', 300)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'period_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_sheets');
    }
};
