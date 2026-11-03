<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubicación de la sede por ubigeo: city sigue siendo el departamento (región)
 * y se agregan provincia y distrito (public/data/ubigeo-pe.json) para ubicar
 * la dirección en el mapa con precisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('province', 100)->nullable()->after('city');
            $table->string('district', 100)->nullable()->after('province');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['province', 'district']);
        });
    }
};
