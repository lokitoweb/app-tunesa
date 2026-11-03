<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feriados y días especiales administrables (p. ej. 24 y 31 de diciembre):
 *  - kind: holiday (no laborable: no hay faltas) | special (laborable con regla propia).
 *  - overtime_rule: none (normal) | all (todo lo trabajado es hora extra) | after (desde overtime_from).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->string('kind', 10)->default('holiday')->after('name');
            $table->string('overtime_rule', 10)->default('none')->after('kind');
            $table->time('overtime_from')->nullable()->after('overtime_rule');
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['kind', 'overtime_rule', 'overtime_from']);
        });
    }
};
