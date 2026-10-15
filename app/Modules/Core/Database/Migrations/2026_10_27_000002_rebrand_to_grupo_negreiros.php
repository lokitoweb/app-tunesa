<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nueva marca: Grupo Negreiros. Solo cambia lo que seguía con los valores de
 * fábrica anteriores (nombre "Tunesa"/"TuneSA" y azul #1E40AF); lo que cada
 * empresa personalizó en "Marca y app" se respeta.
 */
return new class extends Migration
{
    private const OLD_BLUE = '#1E40AF';

    private const NEW_BLUE = '#3C729E';

    public function up(): void
    {
        DB::table('tenant_settings')->whereIn('trade_name', ['Tunesa', 'TuneSA', 'TUNESA'])->update(['trade_name' => 'Grupo Negreiros']);
        DB::table('tenant_settings')->whereRaw('UPPER(primary_color) = ?', [self::OLD_BLUE])->update(['primary_color' => self::NEW_BLUE]);
        DB::table('tenants')->whereRaw('UPPER(primary_color) = ?', [self::OLD_BLUE])->update(['primary_color' => self::NEW_BLUE]);
    }

    public function down(): void
    {
        // Sin vuelta atrás automática: la marca anterior se restaura desde "Marca y app"
    }
};
