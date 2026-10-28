<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos que pide un cargo además de los de toda la empresa (Configuración de la app): por
 * ejemplo la licencia de conducir para el cargo Conductor. Lo obligatorio para cada persona es la
 * unión de ambos. Vacío = solo los de la empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->json('required_documents')->nullable()->after('is_roaming');
        });
    }

    public function down(): void
    {
        Schema::table('positions', fn (Blueprint $table) => $table->dropColumn('required_documents'));
    }
};
