<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horario partido: la pausa fija separa dos tramos de trabajo (p. ej. 08:00–12:00 y
 * 18:00–22:00). Se marca igual (4 marcaciones); cambia cómo se nombran y se muestran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('split_shift')->default(false)->after('break_end_time')->comment('Horario partido: dos tramos');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('split_shift');
        });
    }
};
