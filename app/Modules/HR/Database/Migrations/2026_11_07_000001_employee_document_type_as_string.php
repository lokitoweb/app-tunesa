<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * employee_documents.type deja de ser ENUM: los tipos viven en EmployeeDocument::TYPES (validados en
 * cada entrada) y agregar uno (examen médico, licencia de conducir, exoneración de pensión…) ya no
 * necesita alterar la tabla. Los valores existentes no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->string('type', 40)->change();
        });
    }

    public function down(): void
    {
        // Sin vuelta a ENUM: borraría los tipos nuevos que ya se usaron
    }
};
