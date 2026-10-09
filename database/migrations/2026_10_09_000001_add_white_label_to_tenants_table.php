<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca blanca por empresa: colores corporativos y dominio propio.
 * El subdominio sigue siendo el slug (empresa.tunesa.app); custom_domain
 * permite que la empresa use su propio dominio (asistencia.empresa.com).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('primary_color', 9)->default('#1E40AF')->after('logo_path')
                ->comment('Color corporativo primario (hex)');
            $table->string('secondary_color', 9)->default('#F59E0B')->after('primary_color')
                ->comment('Color corporativo secundario (hex)');
            $table->string('custom_domain')->nullable()->unique()->after('slug')
                ->comment('Dominio propio de la empresa (opcional)');
            $table->json('settings')->nullable()->after('currency')
                ->comment('Preferencias de la empresa (ej: auto-aprobar registros)');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['custom_domain']);
            $table->dropColumn(['primary_color', 'secondary_color', 'custom_domain', 'settings']);
        });
    }
};
